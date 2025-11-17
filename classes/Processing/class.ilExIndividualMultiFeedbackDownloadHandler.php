<?php
declare(strict_types=1);

/**
 * Individual Multi-Feedback Download Handler
 * 
 * Verarbeitet Multi-User-Downloads für Individual-Assignments
 * 
 * @author Cornel Musielak
 * @version 1.1.1
 */
class ilExIndividualMultiFeedbackDownloadHandler
{
    private ilLogger $logger;
    private array $temp_directories = [];
    private ilExUserDataProvider $user_provider;
    private ?ilExerciseStatusFilePlugin $plugin = null;
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->user_provider = new ilExUserDataProvider();
        
        // Plugin-Instanz für Übersetzungen - FIXED
        try {
            $plugin_id = 'exstatusfile';
            $repo = $DIC['component.repository'];
            $factory = $DIC['component.factory'];

            $info = $repo->getPluginById($plugin_id);
            if ($info !== null && $info->isActive()) {
                $this->plugin = $factory->getPlugin($plugin_id); // FIXED: Parameter hinzugefügt
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not load plugin for translations: " . $e->getMessage());
            $this->plugin = null;
        }
        
        register_shutdown_function([$this, 'cleanupAllTempDirectories']);
    }
    
    /**
     * Individual Multi-Feedback-Download für ausgewählte User generieren
     */
    public function generateIndividualMultiFeedbackDownload(int $assignment_id, array $user_ids): void
    {
        try {
            $this->logger->info("Individual Multi-Feedback download started - Assignment: $assignment_id, Users: " . count($user_ids));

            $assignment = new \ilExAssignment($assignment_id);

            // Nur für Individual-Assignments
            if ($assignment->getAssignmentType()->usesTeams()) {
                throw new Exception("Assignment $assignment_id is a team assignment");
            }

            $validated_users = $this->validateUsers($assignment_id, $user_ids);
            if (empty($validated_users)) {
                throw new Exception("No valid users found");
            }

            $this->logger->info("Validated " . count($validated_users) . " users, starting ZIP creation...");

            $zip_path = $this->createIndividualMultiFeedbackZIP($assignment, $validated_users);

            $this->logger->info("ZIP created successfully, sending download...");

            $this->sendZIPDownload($zip_path, $assignment, $validated_users);

        } catch (Exception $e) {
            $this->logger->error("Individual Multi-Feedback download error: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }
    
    /**
     * Users validieren
     */
    private function validateUsers(int $assignment_id, array $user_ids): array
    {
        $validated_users = [];
        $all_users = $this->user_provider->getUsersForAssignment($assignment_id);
        
        foreach ($user_ids as $user_id) {
            foreach ($all_users as $user_data) {
                if ($user_data['user_id'] == $user_id) {
                    $validated_users[] = $user_data;
                    break;
                }
            }
        }
        
        return $validated_users;
    }
    
    /**
     * Individual Multi-Feedback ZIP erstellen
     */
    private function createIndividualMultiFeedbackZIP(\ilExAssignment $assignment, array $users): string
    {
        $temp_dir = $this->createTempDirectory('individual_multi_feedback');
        $zip_filename = $this->generateZIPFilename($assignment, $users);
        $zip_path = $temp_dir . '/' . $zip_filename;
        
        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== true) {
            throw new Exception("Could not create ZIP file: $zip_path");
        }
        
        try {
            $this->addStatusFiles($zip, $assignment, $users, $temp_dir);
            $this->addUserSubmissionsFromArrays($zip, $assignment, $users);
            $this->addReadme($zip, $assignment, $users, $temp_dir);
            
            $zip->close();
            return $zip_path;
            
        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }
    
    /**
     * User-Submissions aus Array-Daten hinzufügen
     */
    private function addUserSubmissionsFromArrays(\ZipArchive &$zip, \ilExAssignment $assignment, array $users_data): void
    {
        $base_name = $this->toAscii("Multi_Feedback_Individual_" . $assignment->getTitle() . "_" . $assignment->getId());
        
        foreach ($users_data as $user_data) {
            $user_id = $user_data['user_id'];
            $user_folder = $base_name . "/" . $this->generateUserFolderName($user_data);
            
            $zip->addEmptyDir($user_folder);
            
            // WICHTIG: Submissions hinzufügen (ohne user_info.txt)
            $this->addUserSubmissionsToZip($zip, $user_folder, $assignment, $user_id);
        }
    }
    
    /**
     * Status-Files erstellen
     */
    private function addStatusFiles(\ZipArchive &$zip, \ilExAssignment $assignment, array $users, string $temp_dir): void
    {
        $status_file = new ilPluginExAssignmentStatusFile();
        $status_file->init($assignment);
        
        // XLSX
        $status_file->setFormat(ilPluginExAssignmentStatusFile::FORMAT_XML);
        $xlsx_path = $temp_dir . '/status.xlsx';
        $status_file->writeToFile($xlsx_path);
        
        if ($status_file->isWriteToFileSuccess() && file_exists($xlsx_path)) {
            $zip->addFile($xlsx_path, "status.xlsx");
        }
        
        // CSV
        $status_file->setFormat(ilPluginExAssignmentStatusFile::FORMAT_CSV);
        $csv_path = $temp_dir . '/status.csv';
        $status_file->writeToFile($csv_path);
        
        if ($status_file->isWriteToFileSuccess() && file_exists($csv_path)) {
            $zip->addFile($csv_path, "status.csv");
        }
    }
    
    /**
     * User-Info zu ZIP hinzufügen - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * Metadaten hinzufügen - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * User-Submissions zu ZIP hinzufügen - VERBESSERTE VERSION (ohne Template-Abhängigkeit)
     */
    private function addUserSubmissionsToZip(\ZipArchive &$zip, string $user_folder, \ilExAssignment $assignment, int $user_id): void
    {
        try {
            $this->logger->debug("Adding submissions for user $user_id to folder: $user_folder");
            
            // Direkt aus Datenbank holen ohne ilExSubmission (vermeidet Template-Problem)
            $submitted_files = $this->getSubmittedFilesFromDB($assignment->getId(), $user_id);
            
            if (empty($submitted_files)) {
                $this->logger->debug("User $user_id has no submitted files");
                return;
            }
            
            $this->logger->debug("User $user_id has " . count($submitted_files) . " submitted files");
            
            $files_added = 0;
            foreach ($submitted_files as $file_data) {
                $file_name = $file_data['filename'];
                $file_path = $file_data['filepath'];
                
                $this->logger->debug("Processing file for user $user_id: $file_name at $file_path");
                
                if (!file_exists($file_path)) {
                    $this->logger->warning("File does not exist: $file_path for user $user_id");
                    continue;
                }
                
                if (!is_readable($file_path)) {
                    $this->logger->warning("File is not readable: $file_path for user $user_id");
                    continue;
                }
                
                // Entferne ILIAS Timestamp-Prefix (z.B. 20251009061955_what_jpg.jpg -> what_jpg.jpg)
                $clean_filename = $this->removeILIASTimestampPrefix($file_name);
                $safe_filename = $this->toAscii($clean_filename);
                $zip_file_path = $user_folder . "/" . $safe_filename;
                
                if ($zip->addFile($file_path, $zip_file_path)) {
                    $files_added++;
                    $this->logger->debug("Successfully added file: $safe_filename to $zip_file_path");
                } else {
                    $this->logger->error("Failed to add file to ZIP: $file_path -> $zip_file_path");
                }
            }
            
            $this->logger->debug("Added $files_added files for user $user_id");
            
        } catch (Exception $e) {
            $this->logger->error("Error adding submissions for user $user_id: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }
    }
    
    /**
     * Entfernt ILIAS Timestamp-Prefix aus Dateinamen
     * z.B.: 20251009061955_what_jpg.jpg -> what_jpg.jpg
     */
    private function removeILIASTimestampPrefix(string $filename): string
    {
        // ILIAS Timestamp Format: YYYYMMDDHHMMSS_ (14 Ziffern + Underscore)
        // Beispiel: 20251009061955_what_jpg.jpg
        
        if (preg_match('/^(\d{14})_(.+)$/', $filename, $matches)) {
            return $matches[2]; // Gibt den Teil nach dem Timestamp zurück
        }
        
        return $filename; // Falls kein Timestamp gefunden, Original zurückgeben
    }
    
    /**
     * Submitted Files direkt aus DB holen (ohne ilExSubmission Template-Abhängigkeit)
     */
    private function getSubmittedFilesFromDB(int $assignment_id, int $user_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $files = [];
        
        try {
            // Hole alle returned files für diesen User und Assignment
            $query = "SELECT * FROM exc_returned 
                      WHERE ass_id = " . $db->quote($assignment_id, 'integer') . " 
                      AND user_id = " . $db->quote($user_id, 'integer') . "
                      AND mimetype IS NOT NULL
                      ORDER BY ts DESC";
            
            $result = $db->query($query);
            
            while ($row = $db->fetchAssoc($result)) {
                $filename = $row['filename'];
                
            $client_data_dir = CLIENT_DATA_DIR;

            // Baue verschiedene mögliche Pfade
            $possible_paths = [];

            // NEU: Prüfe ob filename bereits absoluter Pfad ist (beginnt mit /)
            if (strpos($filename, '/') === 0) {
                // Absoluter Pfad → direkt verwenden (ohne CLIENT_DATA_DIR)
                $possible_paths[] = $filename;
            } else {
                // Relativer Pfad → mit CLIENT_DATA_DIR kombinieren
                $possible_paths[] = $client_data_dir . "/" . $filename;
            }
                
                // Variante 2: filename ist nur der Dateiname, dann manuell Pfad bauen
                if (strpos($filename, '/') === false) {
                    $exercise_id = $this->getExerciseIdFromAssignment($assignment_id);
                    if ($exercise_id) {
                        $possible_paths[] = $client_data_dir . "/ilExercise/" . $exercise_id . "/exc_" . $assignment_id . "/" . $user_id . "/" . $filename;
                        $possible_paths[] = $client_data_dir . "/ilExercise/exc_" . $exercise_id . "/subm_" . $assignment_id . "/" . $user_id . "/" . $filename;
                    }
                }
                
                // Finde den existierenden Pfad
                $file_path = null;
                $basename = basename($filename); // Nur Dateiname für ZIP
                
                foreach ($possible_paths as $path) {
                    if (file_exists($path) && is_readable($path)) {
                        $file_path = $path;
                        $this->logger->debug("Found file at: $path");
                        break;
                    }
                }
                
                if ($file_path) {
                    $files[] = [
                        'filename' => $basename, // Nur Dateiname, nicht voller Pfad
                        'filepath' => $file_path,
                        'mimetype' => $row['mimetype'],
                        'timestamp' => $row['ts']
                    ];
                } else {
                    $this->logger->warning("Could not find file for user $user_id: $filename (tried: " . implode(', ', $possible_paths) . ")");
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error fetching submitted files from DB: " . $e->getMessage());
        }
        
        return $files;
    }
    
    /**
     * Exercise ID vom Assignment holen
     */
    private function getExerciseIdFromAssignment(int $assignment_id): ?int
    {
        global $DIC;
        $db = $DIC->database();
        
        try {
            $query = "SELECT exc_id FROM exc_assignment WHERE id = " . $db->quote($assignment_id, 'integer');
            $result = $db->query($query);
            
            if ($row = $db->fetchAssoc($result)) {
                return (int)$row['exc_id'];
            }
        } catch (Exception $e) {
            $this->logger->error("Error getting exercise_id: " . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * README erstellen (mit Übersetzungen)
     */
    private function addReadme(\ZipArchive &$zip, \ilExAssignment $assignment, array $users, string $temp_dir): void
    {
        $readme_content = $this->generateReadmeContent($assignment, $users);
        $readme_path = $temp_dir . '/README.md';
        
        file_put_contents($readme_path, $readme_content);
        $zip->addFile($readme_path, "README.md");
    }
    
    /**
     * Metadaten hinzufügen - ENTFERNT (wurde bereits in createIndividualMultiFeedbackZIP() entfernt)
     */
    // Diese Methode existiert nicht mehr
    
    /**
     * ZIP-Download senden
     */
    private function sendZIPDownload(string $zip_path, \ilExAssignment $assignment, array $users): void
    {
        if (!file_exists($zip_path)) {
            throw new Exception("ZIP file not found: $zip_path");
        }
        
        $filename = $this->generateDownloadFilename($assignment, $users);
        $filesize = filesize($zip_path);
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        readfile($zip_path);
        exit;
    }
    
    /**
     * Error-Response senden
     */
    private function sendErrorResponse(string $message): void
    {
        $this->logger->error("Multi-Feedback error: " . $message);

        // JSON Error Response für AJAX (KEIN Redirect!)
        header('Content-Type: application/json; charset=utf-8');
        header('HTTP/1.1 400 Bad Request');

        echo json_encode([
            'success' => false,
            'error' => true,
            'message' => $message
        ], JSON_UNESCAPED_UNICODE);

        exit;
    }
    
    /**
     * ZIP-Filename generieren
     */
    private function generateZIPFilename(\ilExAssignment $assignment, array $users): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $user_count = count($users);
        $timestamp = date('Y-m-d_H-i-s');

        return "Multi_Feedback_Individual_{$base_name}_{$user_count}_Users_{$timestamp}.zip";
    }

    /**
     * Download-Filename generieren
     */
    private function generateDownloadFilename(\ilExAssignment $assignment, array $users): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $user_count = count($users);

        return "Multi_Feedback_Individual_{$base_name}_{$user_count}_Users.zip";
    }
    
    /**
     * User-Folder-Name generieren
     */
    private function generateUserFolderName(array $user_data): string
    {
        return $this->toAscii(
            $user_data['lastname'] . "_" . 
            $user_data['firstname'] . "_" . 
            $user_data['login'] . "_" . 
            $user_data['user_id']
        );
    }
    
    /**
     * User-Info generieren - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * User-Info-Content generieren (mit Übersetzungen) - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * User-Info-Content ohne Plugin (Fallback) - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * README-Content generieren (mit Übersetzungen)
     */
    private function generateReadmeContent(\ilExAssignment $assignment, array $users): string
    {
        if (!$this->plugin) {
            return $this->generateReadmeContentFallback($assignment, $users);
        }
        
        $user_count = count($users);
        
        return "# " . $this->plugin->txt('readme_title') . " - " . $assignment->getTitle() . "\n\n" .
               "## " . $this->plugin->txt('readme_information') . "\n\n" .
               "- **" . $this->plugin->txt('readme_assignment') . ":** " . $assignment->getTitle() . "\n" .
               "- **" . $this->plugin->txt('readme_users') . ":** $user_count " . $this->plugin->txt('readme_selected') . "\n" .
               "- **" . $this->plugin->txt('readme_generated') . ":** " . date('Y-m-d H:i:s') . "\n\n" .
               "## " . $this->plugin->txt('readme_structure') . "\n\n" .
               "```\n" .
               "Multi_Feedback_Individual_[Assignment]_[UserCount]_Users/\n" .
               "├── status.xlsx                # " . $this->plugin->txt('readme_structure_status_xlsx') . "\n" .
               "├── status.csv                 # " . $this->plugin->txt('readme_structure_status_csv') . "\n" .
               "├── README.md                  # " . $this->plugin->txt('readme_structure_readme') . "\n" .
               "└── [Lastname_Firstname_Login_ID]/  # " . $this->plugin->txt('readme_structure_per_user') . "\n" .
               "    └── [Submissions]          # " . $this->plugin->txt('readme_structure_submissions') . "\n" .
               "```\n\n" .
               "## " . $this->plugin->txt('readme_workflow') . "\n\n" .
               "1. **" . $this->plugin->txt('readme_workflow_step1') . ":** " .
                   sprintf($this->plugin->txt('readme_workflow_step1_desc'), '`status.xlsx`', '`status.csv`') .
                   " Bei `update` eine `1` eintragen, wenn die entsprechende Zeile aktualisiert werden soll.\n" .
               "2. **" . $this->plugin->txt('readme_workflow_step2') . ":** " . $this->plugin->txt('readme_workflow_step2_desc') .
                   " **WICHTIG:** Ordner-Namen dürfen NICHT geändert werden!\n" .
                   "   " . $this->plugin->txt('readme_workflow_step2_warning') . "\n" .
                   "   " . $this->plugin->txt('readme_workflow_step2_example') . "\n" .
               "3. **" . $this->plugin->txt('readme_workflow_step3') . ":** " . $this->plugin->txt('readme_workflow_step3_desc') .
                   " Feedback-Dateien werden automatisch verarbeitet.\n\n" .
               "## " . $this->plugin->txt('readme_user_overview') . "\n\n" .
               $this->generateUserOverviewForReadme($users) . "\n";
    }
    
    /**
     * README-Content ohne Plugin (Fallback)
     */
    private function generateReadmeContentFallback(\ilExAssignment $assignment, array $users): string
    {
        $user_count = count($users);
        
        return "# Multi-Feedback - " . $assignment->getTitle() . "\n\n" .
               "## Information\n\n" .
               "- **Assignment:** " . $assignment->getTitle() . "\n" .
               "- **Users:** $user_count selected\n" .
               "- **Generated:** " . date('Y-m-d H:i:s') . "\n\n" .
               "## Structure\n\n" .
               "```\n" .
               "Multi_Feedback_Individual_[Assignment]_[UserCount]_Users/\n" .
               "├── status.xlsx\n" .
               "├── status.csv\n" .
               "├── README.md\n" .
               "└── [Lastname_Firstname_Login_ID]/\n" .
               "    └── [Submissions]\n" .
               "```\n\n" .
               "## Workflow\n\n" .
               "1. **Edit status:** Open `status.xlsx` or `status.csv`. Set `update` to `1` for rows that should be updated.\n" .
               "2. **Add feedback:** Place feedback files in the corresponding user folders. *(Still in development)*\n" .
               "3. **Re-upload:** Upload the complete ZIP again.\n\n";
    }
    
    /**
     * User-Overview für README (mit Übersetzungen)
     */
    private function generateUserOverviewForReadme(array $users): string
    {
        if (!$this->plugin) {
            return $this->generateUserOverviewForReadmeFallback($users);
        }
        
        $overview = "";
        foreach ($users as $user_data) {
            $overview .= "### " . $user_data['fullname'] . " (" . $user_data['login'] . ")\n";
            $overview .= "- **" . $this->plugin->txt('readme_status') . ":** " . $user_data['status'] . "\n";
            
            if (!empty($user_data['mark'])) {
                $overview .= "- **" . $this->plugin->txt('readme_note') . ":** " . $user_data['mark'] . "\n";
            }
            
            $submission_text = $user_data['has_submission'] ? $this->plugin->txt('readme_yes') : $this->plugin->txt('readme_no');
            $overview .= "- **" . $this->plugin->txt('readme_submission') . ":** $submission_text\n";
            $overview .= "\n";
        }
        
        return $overview;
    }
    
    /**
     * User-Overview für README ohne Plugin (Fallback)
     */
    private function generateUserOverviewForReadmeFallback(array $users): string
    {
        $overview = "";
        foreach ($users as $user_data) {
            $overview .= "### " . $user_data['fullname'] . " (" . $user_data['login'] . ")\n";
            $overview .= "- **Status:** " . $user_data['status'] . "\n";
            
            if (!empty($user_data['mark'])) {
                $overview .= "- **Grade:** " . $user_data['mark'] . "\n";
            }
            
            $submission_text = $user_data['has_submission'] ? "Yes" : "No";
            $overview .= "- **Submission:** $submission_text\n";
            $overview .= "\n";
        }
        
        return $overview;
    }
    
    /**
     * Metadaten hinzufügen - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * Statistiken generieren - ENTFERNT
     */
    // Diese Methode wird nicht mehr verwendet
    
    /**
     * Temp-Directory erstellen
     */
    private function createTempDirectory(string $prefix): string
    {
        $temp_dir = sys_get_temp_dir() . '/plugin_individual_multi_feedback_' . $prefix . '_' . uniqid();
        mkdir($temp_dir, 0777, true);
        $this->temp_directories[] = $temp_dir;
        
        return $temp_dir;
    }
    
    /**
     * ASCII-Konvertierung
     */
    private function toAscii(string $filename): string
    {
        global $DIC;
        return (new \ilFileServicesPolicy($DIC->fileServiceSettings()))->ascii($filename);
    }
    
    /**
     * Alle Temp-Directories aufräumen
     */
    public function cleanupAllTempDirectories(): void
    {
        foreach ($this->temp_directories as $temp_dir) {
            if (is_dir($temp_dir)) {
                $this->cleanupTempDirectory($temp_dir);
            }
        }
        $this->temp_directories = [];
    }
    
    /**
     * Einzelnes Temp-Directory aufräumen
     */
    private function cleanupTempDirectory(string $temp_dir): void
    {
        try {
            $files = glob($temp_dir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    } elseif (is_dir($file)) {
                        $this->cleanupTempDirectory($file);
                    }
                }
            }
            rmdir($temp_dir);
        } catch (Exception $e) {
            // Silent cleanup failure
        }
    }
}
?>