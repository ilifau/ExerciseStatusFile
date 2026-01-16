<?php
declare(strict_types=1);

/**
 * Multi-Feedback Download Handler
 * 
 * Verarbeitet Multi-Team-Downloads und generiert strukturierte ZIPs
 * 
 * @author Cornel Musielak
 * @version 1.1.1
 */
class ilExMultiFeedbackDownloadHandler
{
    private ilLogger $logger;
    private array $temp_directories = [];
    private ilExTeamDataProvider $team_provider;
    private ?ilExerciseStatusFilePlugin $plugin = null;
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->team_provider = new ilExTeamDataProvider();
        
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
     * Multi-Feedback-Download für ausgewählte Teams generieren
     */
    public function generateMultiFeedbackDownload(int $assignment_id, array $team_ids): void
    {
        try {
            $this->logger->info("Multi-Feedback download started - Assignment: $assignment_id, Teams: " . count($team_ids));

            $assignment = new \ilExAssignment($assignment_id);
            if (!$assignment->getAssignmentType()->usesTeams()) {
                throw new Exception("Assignment $assignment_id is not a team assignment");
            }

            $validated_teams = $this->validateTeams($assignment_id, $team_ids);
            if (empty($validated_teams)) {
                throw new Exception("No valid teams found");
            }

            $this->logger->info("Validated " . count($validated_teams) . " teams, starting ZIP creation...");

            $zip_path = $this->createMultiFeedbackZIP($assignment, $validated_teams);

            $this->logger->info("ZIP created successfully, sending download...");

            $this->sendZIPDownload($zip_path, $assignment, $validated_teams);

        } catch (Exception $e) {
            $this->logger->error("Multi-Feedback download error: " . $e->getMessage());
            $this->sendErrorResponse($e->getMessage());
        }
    }
    
    /**
     * Teams validieren
     */
    private function validateTeams(int $assignment_id, array $team_ids): array
    {
        $validated_teams = [];
        $all_teams = $this->team_provider->getTeamsForAssignment($assignment_id);
        
        foreach ($team_ids as $team_id) {
            foreach ($all_teams as $team_data) {
                if ($team_data['team_id'] == $team_id) {
                    $validated_teams[] = $team_data;
                    break;
                }
            }
        }
        
        return $validated_teams;
    }
    
    /**
     * Multi-Feedback ZIP erstellen
     */
    private function createMultiFeedbackZIP(\ilExAssignment $assignment, array $teams): string
    {
        $temp_dir = $this->createTempDirectory('multi_feedback');
        $zip_filename = $this->generateZIPFilename($assignment, $teams);
        $zip_path = $temp_dir . '/' . $zip_filename;

        $zip = new \ZipArchive();
        if ($zip->open($zip_path, \ZipArchive::CREATE) !== true) {
            throw new Exception("Could not create ZIP file: $zip_path");
        }

        try {
            // Status-Dateien hinzufügen und deren Checksums erfassen
            $status_checksums = $this->addStatusFiles($zip, $assignment, $teams, $temp_dir);
            $submission_checksums = $this->addTeamSubmissionsFromArrays($zip, $assignment, $teams);

            // Alle Checksums zusammenführen (Status + Submissions)
            $all_checksums = array_merge($status_checksums, $submission_checksums);
            $this->addChecksumsFile($zip, $all_checksums, $temp_dir);
            $this->addReadme($zip, $assignment, $teams, $temp_dir);

            $zip->close();
            return $zip_path;

        } catch (Exception $e) {
            $zip->close();
            throw $e;
        }
    }

    /**
     * Team-Submissions aus Array-Daten hinzufügen
     * @return array Checksums aller hinzugefügten Dateien
     */
    private function addTeamSubmissionsFromArrays(\ZipArchive &$zip, \ilExAssignment $assignment, array $teams_data): array
    {
        $base_name = $this->toAscii("Multi_Feedback_" . $assignment->getTitle() . "_" . $assignment->getId());
        $checksums = [];

        foreach ($teams_data as $team_data) {
            $team_id = $team_data['team_id'];
            $team_folder = $base_name . "/Team_" . $team_id;

            $zip->addEmptyDir($team_folder);

            // WICHTIG: Sammle ALLE Submissions von ALLEN Team-Mitgliedern
            $all_team_submissions = $this->collectAllTeamSubmissions($assignment->getId(), $team_data['members']);

            foreach ($team_data['members'] as $member_data) {
                $user_id = $member_data['user_id'];
                $user_folder = $team_folder . "/" . $this->generateUserFolderName($member_data);

                $zip->addEmptyDir($user_folder);

                // Füge ALLE Team-Submissions zu JEDEM Team-Mitglied hinzu
                $user_checksums = $this->addTeamSubmissionsToUserFolder($zip, $user_folder, $all_team_submissions, $user_id);
                $checksums = array_merge($checksums, $user_checksums);
            }
        }

        return $checksums;
    }
    
    /**
     * Sammle alle Submissions von allen Team-Mitgliedern
     */
    private function collectAllTeamSubmissions(int $assignment_id, array $members): array
    {
        $all_submissions = [];
        $seen_files = []; // Um Duplikate zu vermeiden
        
        foreach ($members as $member_data) {
            $user_id = $member_data['user_id'];
            $user_files = $this->getSubmittedFilesFromDB($assignment_id, $user_id);
            
            foreach ($user_files as $file_data) {
                // Verwende Dateipfad als eindeutigen Key um Duplikate zu vermeiden
                $file_key = $file_data['filepath'];
                
                if (!isset($seen_files[$file_key])) {
                    $seen_files[$file_key] = true;
                    $all_submissions[] = [
                        'filename' => $file_data['filename'],
                        'filepath' => $file_data['filepath'],
                        'mimetype' => $file_data['mimetype'],
                        'timestamp' => $file_data['timestamp'],
                        'uploaded_by_user_id' => $user_id,
                        'uploaded_by_login' => $member_data['login']
                    ];
                }
            }
        }
        
        // Sortiere nach Timestamp (neueste zuerst)
        usort($all_submissions, function($a, $b) {
            return strcmp($b['timestamp'], $a['timestamp']);
        });
        
        return $all_submissions;
    }
    
    /**
     * Füge Team-Submissions zu User-Folder hinzu
     * @return array Checksums der hinzugefügten Dateien
     */
    private function addTeamSubmissionsToUserFolder(\ZipArchive &$zip, string $user_folder, array $all_team_submissions, int $current_user_id): array
    {
        $checksums = [];

        try {
            if (empty($all_team_submissions)) {
                $this->logger->debug("No team submissions found for folder: $user_folder");
                return $checksums;
            }

            $this->logger->debug("Adding " . count($all_team_submissions) . " team submission(s) to: $user_folder");

            $files_added = 0;
            foreach ($all_team_submissions as $file_data) {
                $file_name = $file_data['filename'];
                $file_path = $file_data['filepath'];
                $uploaded_by = $file_data['uploaded_by_user_id'];
                $uploaded_by_login = $file_data['uploaded_by_login'];

                if (!file_exists($file_path)) {
                    $this->logger->warning("File does not exist: $file_path");
                    continue;
                }

                if (!is_readable($file_path)) {
                    $this->logger->warning("File is not readable: $file_path");
                    continue;
                }

                // Entferne ILIAS Timestamp-Prefix
                $clean_filename = $this->removeILIASTimestampPrefix($file_name);

                // Optional: Markiere wer die Datei hochgeladen hat (wenn nicht der aktuelle User)
                if ($uploaded_by != $current_user_id) {
                    // Füge Uploader-Info hinzu (optional, auskommentiert)
                    // $clean_filename = "[von_{$uploaded_by_login}]_" . $clean_filename;
                }

                $safe_filename = $this->toAscii($clean_filename);
                $zip_file_path = $user_folder . "/" . $safe_filename;

                if ($zip->addFile($file_path, $zip_file_path)) {
                    $files_added++;
                    $this->logger->debug("Successfully added team file: $safe_filename (uploaded by user $uploaded_by)");

                    // Berechne Hash für Checksum
                    $checksums[$zip_file_path] = [
                        'md5' => md5_file($file_path),
                        'size' => filesize($file_path),
                        'type' => 'submission'
                    ];
                } else {
                    $this->logger->error("Failed to add file to ZIP: $file_path -> $zip_file_path");
                }
            }

            $this->logger->debug("Added $files_added team files to $user_folder");

        } catch (Exception $e) {
            $this->logger->error("Error adding team submissions to folder: " . $e->getMessage());
        }

        return $checksums;
    }    

    /**
     * Status-Files erstellen und Checksums zurückgeben
     * @return array Checksums der Status-Dateien für spätere Änderungserkennung
     */
    private function addStatusFiles(\ZipArchive &$zip, \ilExAssignment $assignment, array $teams, string $temp_dir): array
    {
        $checksums = [];
        $status_file = new ilPluginExAssignmentStatusFile();
        $status_file->init($assignment);

        // XLSX
        $status_file->setFormat(ilPluginExAssignmentStatusFile::FORMAT_XML);
        $xlsx_path = $temp_dir . '/status.xlsx';
        $status_file->writeToFile($xlsx_path);

        if ($status_file->isWriteToFileSuccess() && file_exists($xlsx_path)) {
            $zip->addFile($xlsx_path, "status.xlsx");
            // Checksum für Änderungserkennung beim Upload
            $checksums['status.xlsx'] = [
                'md5' => md5_file($xlsx_path),
                'sha256' => hash_file('sha256', $xlsx_path),
                'size' => filesize($xlsx_path),
                'type' => 'status_file'
            ];
        }

        // CSV
        $status_file->setFormat(ilPluginExAssignmentStatusFile::FORMAT_CSV);
        $csv_path = $temp_dir . '/status.csv';
        $status_file->writeToFile($csv_path);

        if ($status_file->isWriteToFileSuccess() && file_exists($csv_path)) {
            $zip->addFile($csv_path, "status.csv");
            // Checksum für Änderungserkennung beim Upload
            $checksums['status.csv'] = [
                'md5' => md5_file($csv_path),
                'sha256' => hash_file('sha256', $csv_path),
                'size' => filesize($csv_path),
                'type' => 'status_file'
            ];
        }

        $this->logger->info("Added status files with checksums: xlsx=" . (isset($checksums['status.xlsx']) ? 'yes' : 'no') . ", csv=" . (isset($checksums['status.csv']) ? 'yes' : 'no'));

        return $checksums;
    }
    
    /**
     * Team-Info zu ZIP hinzufügen
     */
    private function addTeamInfoToZip(\ZipArchive &$zip, string $team_folder, array $team_data): void
    {
        $temp_dir = $this->createTempDirectory('team_info');
        $info_content = $this->generateTeamInfoContent($team_data);
        $info_path = $temp_dir . '/team_info.txt';
        
        file_put_contents($info_path, $info_content);
        $zip->addFile($info_path, $team_folder . "/team_info.txt");
    }
    
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
                
                // Der filename in der DB enthält oft schon den relativen Pfad
                // z.B.: ilExercise/10/39/exc_103946/subm_32/103630/20250626083054_what_jpg.jpg
                
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
    private function addReadme(\ZipArchive &$zip, \ilExAssignment $assignment, array $teams, string $temp_dir): void
    {
        $readme_content = $this->generateReadmeContent($assignment, $teams);
        $readme_path = $temp_dir . '/README.md';

        file_put_contents($readme_path, $readme_content);
        $zip->addFile($readme_path, "README.md");
    }

    /**
     * Checksum-Datei hinzufügen
     * Speichert MD5-Hashes aller Submission-Dateien zur späteren Validierung
     */
    private function addChecksumsFile(\ZipArchive &$zip, array $checksums, string $temp_dir): void
    {
        if (empty($checksums)) {
            $this->logger->warning("No checksums to add - skipping checksums.json");
            return;
        }

        $checksums_path = $temp_dir . '/checksums.json';
        $json_content = json_encode($checksums, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        file_put_contents($checksums_path, $json_content);
        $zip->addFile($checksums_path, "checksums.json");

        $this->logger->info("Added checksums.json with " . count($checksums) . " file hashes");
    }

    /**
     * Metadaten hinzufügen
     */
    private function addMetadata(\ZipArchive &$zip, \ilExAssignment $assignment, array $teams, string $temp_dir): void
    {
        // Team-Mapping
        $team_mapping = [];
        foreach ($teams as $team_data) {
            $team_mapping['teams'][$team_data['team_id']] = [
                'team_id' => $team_data['team_id'],
                'member_count' => $team_data['member_count'],
                'members' => $team_data['members'],
                'status' => $team_data['status']
            ];
        }
        
        $mapping_path = $temp_dir . '/team_mapping.json';
        file_put_contents($mapping_path, json_encode($team_mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($mapping_path, "team_mapping.json");
        
        // Statistiken
        $stats = $this->generateStatistics($assignment, $teams);
        $stats_path = $temp_dir . '/statistics.json';
        file_put_contents($stats_path, json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($stats_path, "statistics.json");
    }
    
    /**
     * ZIP-Download senden
     */
    private function sendZIPDownload(string $zip_path, \ilExAssignment $assignment, array $teams): void
    {
        if (!file_exists($zip_path)) {
            throw new Exception("ZIP file not found: $zip_path");
        }
        
        $filename = $this->generateDownloadFilename($assignment, $teams);
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
    private function generateZIPFilename(\ilExAssignment $assignment, array $teams): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $team_count = count($teams);
        $timestamp = date('Y-m-d_H-i-s');

        return "Multi_Feedback_{$base_name}_{$team_count}_Teams_{$timestamp}.zip";
    }

    /**
     * Download-Filename generieren
     */
    private function generateDownloadFilename(\ilExAssignment $assignment, array $teams): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $team_count = count($teams);

        return "Multi_Feedback_{$base_name}_{$team_count}_Teams.zip";
    }
    
    /**
     * User-Folder-Name generieren
     */
    private function generateUserFolderName(array $member_data): string
    {
        return $this->toAscii(
            $member_data['lastname'] . "_" . 
            $member_data['firstname'] . "_" . 
            $member_data['login'] . "_" . 
            $member_data['user_id']
        );
    }
    
    /**
     * Team-Info generieren
     */
    private function generateTeamInfo(\ilExAssignment $assignment, array $teams): array
    {
        return [
            'assignment' => [
                'id' => $assignment->getId(),
                'title' => $assignment->getTitle(),
                'type' => $assignment->getType()
            ],
            'multi_feedback' => [
                'team_count' => count($teams),
                'team_ids' => array_column($teams, 'team_id'),
                'generated_at' => date('Y-m-d H:i:s'),
                'plugin_version' => '1.1.1'
            ],
            'teams' => $teams
        ];
    }
    
    /**
     * Team-Info-Content generieren (mit Übersetzungen)
     */
    private function generateTeamInfoContent(array $team_data): string
    {
        if (!$this->plugin) {
            return $this->generateTeamInfoContentFallback($team_data);
        }
        
        $content = "TEAM INFORMATION\n";
        $content .= "================\n\n";
        $content .= "Team " . $this->plugin->txt('readme_id') . ": " . $team_data['team_id'] . "\n";
        $content .= $this->plugin->txt('readme_members') . ": " . $team_data['member_count'] . "\n";
        $content .= $this->plugin->txt('readme_status') . ": " . $team_data['status'] . "\n";
        
        if (!empty($team_data['mark'])) {
            $content .= $this->plugin->txt('readme_note') . ": " . $team_data['mark'] . "\n";
        }
        
        $content .= "\n" . $this->plugin->txt('readme_members') . ":\n";
        foreach ($team_data['members'] as $member) {
            $content .= "- " . $member['fullname'] . " (" . $member['login'] . ")\n";
        }
        
        if (!empty($team_data['comment'])) {
            $content .= "\nKommentar:\n" . $team_data['comment'] . "\n";
        }
        
        $content .= "\n" . $this->plugin->txt('readme_generated') . ": " . date('Y-m-d H:i:s') . "\n";
        
        return $content;
    }
    
    /**
     * Team-Info-Content ohne Plugin (Fallback)
     */
    private function generateTeamInfoContentFallback(array $team_data): string
    {
        $content = "TEAM INFORMATION\n";
        $content .= "================\n\n";
        $content .= "Team ID: " . $team_data['team_id'] . "\n";
        $content .= "Members: " . $team_data['member_count'] . "\n";
        $content .= "Status: " . $team_data['status'] . "\n";
        
        if (!empty($team_data['mark'])) {
            $content .= "Grade: " . $team_data['mark'] . "\n";
        }
        
        $content .= "\nMembers:\n";
        foreach ($team_data['members'] as $member) {
            $content .= "- " . $member['fullname'] . " (" . $member['login'] . ")\n";
        }
        
        if (!empty($team_data['comment'])) {
            $content .= "\nComment:\n" . $team_data['comment'] . "\n";
        }
        
        $content .= "\nGenerated: " . date('Y-m-d H:i:s') . "\n";
        
        return $content;
    }
    
    /**
     * README-Content generieren (mit Übersetzungen)
     */
    private function generateReadmeContent(\ilExAssignment $assignment, array $teams): string
    {
        if (!$this->plugin) {
            return $this->generateReadmeContentFallback($assignment, $teams);
        }
        
        $team_count = count($teams);
        
        return "# " . $this->plugin->txt('readme_title') . " - " . $assignment->getTitle() . "\n\n" .
               "## " . $this->plugin->txt('readme_information') . "\n\n" .
               "- **" . $this->plugin->txt('readme_assignment') . ":** " . $assignment->getTitle() . "\n" .
               "- **" . $this->plugin->txt('readme_teams') . ":** $team_count " . $this->plugin->txt('readme_selected') . "\n" .
               "- **" . $this->plugin->txt('readme_generated') . ":** " . date('Y-m-d H:i:s') . "\n\n" .
               "## " . $this->plugin->txt('readme_structure') . "\n\n" .
               "```\n" .
               "Multi_Feedback_[Assignment]_[TeamCount]_Teams/\n" .
               "├── status.xlsx                # " . $this->plugin->txt('readme_structure_status_xlsx') . "\n" .
               "├── status.csv                 # " . $this->plugin->txt('readme_structure_status_csv') . "\n" .
               "├── checksums.json             # Checksums for submission integrity\n" .
               "├── README.md                  # " . $this->plugin->txt('readme_structure_readme') . "\n" .
               "└── Team_[ID]/                 # " . $this->plugin->txt('readme_structure_per_team') . "\n" .
               "    └── [Lastname_Firstname_Login_ID]/  # " . $this->plugin->txt('readme_structure_per_member') . "\n" .
               "        └── [Submissions]      # " . $this->plugin->txt('readme_structure_submissions') . "\n" .
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
               "## " . $this->plugin->txt('readme_modified_submission_section') . "\n\n" .
               $this->plugin->txt('readme_modified_submission_info') . "\n\n" .
               $this->plugin->txt('readme_modified_submission_recommendation') . "\n\n" .
               "## " . $this->plugin->txt('readme_team_overview') . "\n\n" .
               $this->generateTeamOverviewForReadme($teams) . "\n";
    }
    
    /**
     * README-Content ohne Plugin (Fallback)
     */
    private function generateReadmeContentFallback(\ilExAssignment $assignment, array $teams): string
    {
        $team_count = count($teams);
        
        return "# Multi-Feedback - " . $assignment->getTitle() . "\n\n" .
               "## Information\n\n" .
               "- **Assignment:** " . $assignment->getTitle() . "\n" .
               "- **Teams:** $team_count selected\n" .
               "- **Generated:** " . date('Y-m-d H:i:s') . "\n\n" .
               "## Structure\n\n" .
               "```\n" .
               "Multi_Feedback_[Assignment]_[TeamCount]_Teams/\n" .
               "├── status.xlsx\n" .
               "├── status.csv\n" .
               "├── README.md\n" .
               "└── Team_[ID]/\n" .
               "    └── [Lastname_Firstname_Login_ID]/\n" .
               "        └── [Submissions]\n" .
               "```\n\n" .
               "## Workflow\n\n" .
               "1. **Edit status:** Open `status.xlsx` or `status.csv`. Set `update` to `1` for rows that should be updated.\n" .
               "2. **Add feedback:** Place feedback files in the corresponding user folders. *(Still in development)*\n" .
               "3. **Re-upload:** Upload the complete ZIP again.\n\n";
    }
    
    /**
     * Team-Overview für README (mit Übersetzungen)
     */
    private function generateTeamOverviewForReadme(array $teams): string
    {
        if (!$this->plugin) {
            return $this->generateTeamOverviewForReadmeFallback($teams);
        }
        
        $overview = "";
        foreach ($teams as $team_data) {
            $overview .= "### Team " . $team_data['team_id'] . "\n";
            $overview .= "- **" . $this->plugin->txt('readme_status') . ":** " . $team_data['status'] . "\n";
            $overview .= "- **" . $this->plugin->txt('readme_members') . ":** ";
            
            $member_names = [];
            foreach ($team_data['members'] as $member) {
                $member_names[] = $member['fullname'] . " (" . $member['login'] . ")";
            }
            $overview .= implode(', ', $member_names) . "\n";
            
            if (!empty($team_data['mark'])) {
                $overview .= "- **" . $this->plugin->txt('readme_note') . ":** " . $team_data['mark'] . "\n";
            }
            
            $overview .= "\n";
        }
        
        return $overview;
    }
    
    /**
     * Team-Overview für README ohne Plugin (Fallback)
     */
    private function generateTeamOverviewForReadmeFallback(array $teams): string
    {
        $overview = "";
        foreach ($teams as $team_data) {
            $overview .= "### Team " . $team_data['team_id'] . "\n";
            $overview .= "- **Status:** " . $team_data['status'] . "\n";
            $overview .= "- **Members:** ";
            
            $member_names = [];
            foreach ($team_data['members'] as $member) {
                $member_names[] = $member['fullname'] . " (" . $member['login'] . ")";
            }
            $overview .= implode(', ', $member_names) . "\n";
            
            if (!empty($team_data['mark'])) {
                $overview .= "- **Grade:** " . $team_data['mark'] . "\n";
            }
            
            $overview .= "\n";
        }
        
        return $overview;
    }
    
    /**
     * Statistiken generieren
     */
    private function generateStatistics(\ilExAssignment $assignment, array $teams): array
    {
        $stats = [
            'summary' => [
                'assignment_id' => $assignment->getId(),
                'assignment_title' => $assignment->getTitle(),
                'total_teams' => count($teams),
                'total_members' => array_sum(array_column($teams, 'member_count')),
                'generated_at' => date('Y-m-d H:i:s')
            ],
            'status_distribution' => [],
            'teams' => []
        ];
        
        $status_counts = [];
        foreach ($teams as $team_data) {
            $status = $team_data['status'];
            $status_counts[$status] = ($status_counts[$status] ?? 0) + 1;
            
            $stats['teams'][] = [
                'team_id' => $team_data['team_id'],
                'member_count' => $team_data['member_count'],
                'status' => $status,
                'has_submissions' => $team_data['has_submissions'] ?? false
            ];
        }
        
        $stats['status_distribution'] = $status_counts;
        
        return $stats;
    }
    
    /**
     * Temp-Directory erstellen
     */
    private function createTempDirectory(string $prefix): string
    {
        $temp_dir = sys_get_temp_dir() . '/plugin_multi_feedback_' . $prefix . '_' . uniqid();
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