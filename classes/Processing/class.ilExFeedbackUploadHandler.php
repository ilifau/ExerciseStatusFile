<?php
declare(strict_types=1);

/**
 * Feedback Upload Handler
 * 
 * Verarbeitet hochgeladene Feedback-ZIPs und wendet Status-Updates an
 * Unterstützt Individual- und Team-Assignments
 * 
 * @author Cornel Musielak
 * @version 1.1.1
 */
class ilExFeedbackUploadHandler
{
    private ilLogger $logger;
    private array $temp_directories = [];
    private array $processing_stats = [];
    private array $renamed_files = []; // Tracking für umbenannte Dateien
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        
        register_shutdown_function([$this, 'cleanupAllTempDirectories']);
    }
    
    /**
     * Feedback Upload Processing
     */
    public function handleFeedbackUpload(array $parameters): void
    {
        $assignment_id = $parameters['assignment_id'] ?? 0;
        $tutor_id = $parameters['tutor_id'] ?? 0;

        $this->logger->info("=== FEEDBACK UPLOAD HANDLER CALLED === Assignment: $assignment_id, Version: 2025-10-30-11:20");

        if (!$assignment_id) {
            $this->logger->warning("Upload handler: Missing assignment_id");
            return;
        }

        try {
            $assignment = new \ilExAssignment($assignment_id);
            $zip_content = $this->extractZipContent($parameters);
            
            if (!$zip_content || !$this->isValidZipContent($zip_content)) {
                $this->logger->warning("Upload handler: Invalid ZIP content");
                return;
            }
            
            if ($assignment->getAssignmentType()->usesTeams()) {
                $this->processTeamUpload($zip_content, $assignment_id, $tutor_id);
            } else {
                $this->processIndividualUpload($zip_content, $assignment_id, $tutor_id);
            }
            
            $this->setProcessingSuccess($assignment_id, $tutor_id);
            
        } catch (Exception $e) {
            $this->logger->error("Upload handler error: " . $e->getMessage());
            // Exception weitergeben, damit sie in handleMultiFeedbackUploadRequest() verarbeitet werden kann
            throw $e;
        }
    }
    
    /**
     * Team Assignment Upload Processing
     */
    private function processTeamUpload(string $zip_content, int $assignment_id, int $tutor_id): void
    {
        $temp_zip = $this->createTempZipFile($zip_content, 'team_feedback');
        if (!$temp_zip) return;

        try {
            $this->validateZipForAssignment($temp_zip, $assignment_id);

            $extracted_files = $this->extractZipContents($temp_zip, 'team_extract');
            $checksums = $this->loadChecksumsFromExtractedFiles($extracted_files);
            $status_files = $this->findStatusFiles($extracted_files, $checksums);

            // Status-File-Verarbeitung ist optional
            if (!empty($status_files)) {
                try {
                    $this->processStatusFiles($status_files, $assignment_id, true);
                } catch (Exception $e) {
                    // Prüfe ob es ein kritischer Fehler ist (z.B. ungültiger Status-Wert)
                    $error_msg = $e->getMessage();
                    if (strpos($error_msg, 'Invalid status') !== false ||
                        strpos($error_msg, 'Status file error') !== false) {
                        // Kritischer Fehler - Upload abbrechen
                        $this->logger->error("Critical error in status file: " . $error_msg);
                        throw new Exception("Status-File Fehler: " . $error_msg);
                    }
                    // Nur bei anderen Fehlern (z.B. leere Files) fortfahren
                    $this->logger->info("Status file processing skipped: " . $error_msg);
                }
            }

            // Feedback-Files werden unabhängig von Status-Updates verarbeitet
            $this->processTeamFeedbackFiles($extracted_files, $assignment_id, $checksums);

        } finally {
            $this->cleanupTempFile($temp_zip);
        }
    }
    
    /**
     * Individual Assignment Upload Processing
     */
    private function processIndividualUpload(string $zip_content, int $assignment_id, int $tutor_id): void
    {
        $temp_zip = $this->createTempZipFile($zip_content, 'individual_feedback');
        if (!$temp_zip) return;

        try {
            $this->validateZipForAssignment($temp_zip, $assignment_id);

            $extracted_files = $this->extractZipContents($temp_zip, 'individual_extract');
            $checksums = $this->loadChecksumsFromExtractedFiles($extracted_files);
            $status_files = $this->findStatusFiles($extracted_files, $checksums);

            // Status-File-Verarbeitung ist optional
            if (!empty($status_files)) {
                try {
                    $this->processStatusFiles($status_files, $assignment_id, false);
                } catch (Exception $e) {
                    // Prüfe ob es ein kritischer Fehler ist (z.B. ungültiger Status-Wert)
                    $error_msg = $e->getMessage();
                    if (strpos($error_msg, 'Invalid status') !== false ||
                        strpos($error_msg, 'Status file error') !== false) {
                        // Kritischer Fehler - Upload abbrechen
                        $this->logger->error("Critical error in status file: " . $error_msg);
                        throw new Exception("Status-File Fehler: " . $error_msg);
                    }
                    // Nur bei anderen Fehlern (z.B. leere Files) fortfahren
                    $this->logger->info("Status file processing skipped: " . $error_msg);
                }
            }

            // Feedback-Files werden unabhängig von Status-Updates verarbeitet
            $this->processIndividualFeedbackFiles($extracted_files, $assignment_id, $checksums);

        } finally {
            $this->cleanupTempFile($temp_zip);
        }
    }
    
    /**
     * Umfassende ZIP-Validierung für Assignment
     */
    private function validateZipForAssignment(string $zip_path, int $assignment_id): void
    {
        $zip = new \ZipArchive();
        $zip_result = $zip->open($zip_path);
        
        if ($zip_result !== true) {
            throw new Exception("Die hochgeladene Datei ist kein gültiges ZIP-Archiv (Code: $zip_result).");
        }
        
        if ($zip->numFiles === 0) {
            $zip->close();
            throw new Exception("Das ZIP-Archiv ist leer.");
        }
        
        $assignment = new \ilExAssignment($assignment_id);
        $is_team_assignment = $assignment->getAssignmentType()->usesTeams();
        
        $file_list = [];
        $status_files_found = [];
        $has_team_structure = false;
        $has_user_structure = false;
        
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $file_list[] = $filename;
            
            $basename = basename($filename);
            $status_file_patterns = [
                'status.xlsx', 'status.csv', 'status.xls',
                'batch_status.xlsx', 'batch_status.csv'
            ];
            
            foreach ($status_file_patterns as $pattern) {
                if (strcasecmp($basename, $pattern) === 0) {
                    $status_files_found[] = $filename;
                    break;
                }
            }
            
            if (preg_match('/Team_\d+\//', $filename)) {
                $has_team_structure = true;
            }
            
            if (preg_match('/[^\/]+_[^\/]+_[^\/]+_\d+\//', $filename)) {
                $has_user_structure = true;
            }
        }
        
        $zip->close();

        // Validierungen
        // Status-Datei ist optional - Feedback kann auch ohne Status-Updates hochgeladen werden
        // (keine Exception, nur Info-Log wenn keine Status-Datei vorhanden)
        
        if ($is_team_assignment && !$has_team_structure) {
            throw new Exception("Team-Assignment benötigt Team-Ordner (Team_1/, Team_2/, etc.)");
        }
        
        if (!$is_team_assignment && $has_team_structure) {
            throw new Exception("Individual-Assignment darf keine Team-Ordner enthalten.");
        }
        
        if (!$has_user_structure) {
            throw new Exception("Keine User-Ordner (Lastname_Firstname_Login_ID/) im ZIP gefunden.");
        }
    }
    
    /**
     * ZIP-Content aus Upload-Parameters extrahieren
     */
    private function extractZipContent(array $parameters): ?string
    {
        if (isset($parameters['zip_content']) && is_string($parameters['zip_content'])) {
            return $parameters['zip_content'];
        }
        
        if (isset($parameters['upload_result'])) {
            return $this->getZipContentFromUploadResult($parameters['upload_result']);
        }
        
        if (isset($parameters['zip_path']) && file_exists($parameters['zip_path'])) {
            return file_get_contents($parameters['zip_path']);
        }
        
        return null;
    }
    
    /**
     * ZIP-Content aus Upload-Result extrahieren
     */
    private function getZipContentFromUploadResult($upload_result): ?string
    {
        if (method_exists($upload_result, 'getPath') && $upload_result->getPath()) {
            $temp_path = $upload_result->getPath();
            if (file_exists($temp_path)) {
                return file_get_contents($temp_path);
            }
        }
        
        return null;
    }
    
    /**
     * Prüft ob Content ein gültiges ZIP ist
     */
    private function isValidZipContent(string $content): bool
    {
        if (empty($content)) {
            throw new Exception("Die hochgeladene Datei ist leer.");
        }
        
        if (strlen($content) < 100) {
            throw new Exception("Die hochgeladene Datei ist zu klein.");
        }
        
        if (substr($content, 0, 2) !== 'PK') {
            throw new Exception("Die hochgeladene Datei ist kein gültiges ZIP-Archiv.");
        }
        
        return true;
    }
    
    /**
     * Erstellt temporäre ZIP-Datei
     */
    private function createTempZipFile(string $zip_content, string $prefix): ?string
    {
        $temp_zip = sys_get_temp_dir() . '/plugin_' . $prefix . '_' . uniqid() . '.zip';
        
        if (file_put_contents($temp_zip, $zip_content) === false) {
            $this->logger->error("Could not create temp ZIP file");
            return null;
        }
        
        if (!file_exists($temp_zip) || filesize($temp_zip) < 100) {
            $this->logger->error("Invalid temp ZIP file created");
            return null;
        }
        
        return $temp_zip;
    }
    
    /**
     * Extrahiert ZIP-Inhalte
     */
    private function extractZipContents(string $zip_path, string $extract_prefix): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($zip_path) !== true) {
            $this->logger->error("Could not open ZIP file");
            return [];
        }
        
        $extract_dir = $this->createTempDirectory($extract_prefix);
        $extracted_files = [];
        
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (empty($filename) || substr($filename, -1) === '/') continue;

                // Security: Prevent path traversal attacks
                // Remove any ../ or absolute paths
                $safe_filename = str_replace(['../', '..\\', '../', '..\\'], '', $filename);

                // Remove leading slashes (absolute paths)
                $safe_filename = ltrim($safe_filename, '/\\');

                // If filename was completely removed or contains null bytes, skip
                if (empty($safe_filename) || strpos($safe_filename, "\0") !== false) {
                    $this->logger->warning("Suspicious filename detected and skipped: $filename");
                    continue;
                }

                $zip->extractTo($extract_dir, $filename);
                $extracted_path = $extract_dir . '/' . $safe_filename;

                // Security: Verify extracted file is actually inside extract_dir
                $real_extract_dir = realpath($extract_dir);
                $real_extracted_path = realpath($extracted_path);

                if ($real_extracted_path === false || strpos($real_extracted_path, $real_extract_dir) !== 0) {
                    $this->logger->warning("Path traversal attempt detected: $filename");
                    // Delete the file if it was extracted outside
                    if (file_exists($extracted_path)) {
                        @unlink($extracted_path);
                    }
                    continue;
                }

                if (file_exists($extracted_path)) {
                    $extracted_files[] = [
                        'original_name' => $filename,
                        'extracted_path' => $extracted_path,
                        'size' => filesize($extracted_path)
                    ];
                }
            }
            
        } finally {
            $zip->close();
        }
        
        return $extracted_files;
    }
    
    /**
     * Findet Status-Files im entpackten ZIP mit intelligenter Auswahl
     *
     * Logik:
     * - Wenn nur status.xlsx geändert wurde -> verwende xlsx
     * - Wenn nur status.csv geändert wurde -> verwende csv
     * - Wenn beide geändert wurden -> verwende xlsx und zeige Warnung
     * - Wenn keine geändert wurde -> verwende xlsx (falls vorhanden)
     *
     * @param array $extracted_files Die extrahierten Dateien
     * @param array $checksums Checksums aus checksums.json
     * @return array Liste der zu verarbeitenden Status-Files (immer max. 1 File)
     */
    private function findStatusFiles(array $extracted_files, array $checksums): array
    {
        $xlsx_file = null;
        $csv_file = null;

        // Suche nach status.xlsx und status.csv
        foreach ($extracted_files as $file) {
            $basename = basename($file['original_name']);

            if ($basename === 'status.xlsx') {
                $xlsx_file = $file;
            } elseif ($basename === 'status.csv') {
                $csv_file = $file;
            }
        }

        // Keine Status-Files gefunden
        if (!$xlsx_file && !$csv_file) {
            return [];
        }

        // Prüfe welche Datei(en) geändert wurden
        $xlsx_modified = false;
        $csv_modified = false;

        if ($xlsx_file && !empty($checksums)) {
            $xlsx_modified = $this->isFileModified($xlsx_file, $checksums);
        }

        if ($csv_file && !empty($checksums)) {
            $csv_modified = $this->isFileModified($csv_file, $checksums);
        }

        // Entscheidungslogik
        if ($xlsx_modified && $csv_modified) {
            // BEIDE geändert -> xlsx verwenden aber Warnung
            $this->logger->warning(
                "Both status.xlsx and status.csv were modified. Using status.xlsx. " .
                "Please modify only ONE status file per upload."
            );
            return [$xlsx_file['extracted_path']];

        } elseif ($xlsx_modified) {
            // Nur xlsx geändert
            return [$xlsx_file['extracted_path']];

        } elseif ($csv_modified) {
            // Nur csv geändert
            return [$csv_file['extracted_path']];

        } else {
            // Keine Änderungen oder keine Checksums vorhanden
            if ($xlsx_file) {
                return [$xlsx_file['extracted_path']];
            } elseif ($csv_file) {
                return [$csv_file['extracted_path']];
            }
        }

        return [];
    }

    /**
     * Prüft ob eine Datei geändert wurde (Checksum-Vergleich)
     *
     * @param array $file File-Info Array mit 'extracted_path' und 'original_name'
     * @param array $checksums Checksums aus checksums.json
     * @return bool True wenn Datei geändert wurde
     */
    private function isFileModified(array $file, array $checksums): bool
    {
        if (!file_exists($file['extracted_path'])) {
            return false;
        }

        $current_checksum = hash_file('sha256', $file['extracted_path']);
        $original_name = $file['original_name'];

        // Checksums sind nach original_name indiziert
        if (isset($checksums[$original_name])) {
            return $current_checksum !== $checksums[$original_name];
        }

        // Kein Checksum gefunden -> als geändert betrachten
        return true;
    }

    /**
     * Lädt Checksums aus der checksums.json Datei im ZIP
     * @return array Checksums oder leeres Array falls nicht vorhanden
     */
    private function loadChecksumsFromExtractedFiles(array $extracted_files): array
    {
        foreach ($extracted_files as $file) {
            $basename = basename($file['original_name']);

            if ($basename === 'checksums.json') {
                $checksums_path = $file['extracted_path'];

                if (file_exists($checksums_path)) {
                    $content = file_get_contents($checksums_path);
                    $checksums = json_decode($content, true);

                    if (is_array($checksums)) {
                        $this->logger->info("Loaded " . count($checksums) . " checksums from checksums.json");
                        return $checksums;
                    }
                }
            }
        }

        $this->logger->info("No checksums.json found in ZIP - checksum validation disabled");
        return [];
    }

    /**
     * Verarbeitet Status-Files und wendet Updates an
     */
    private function processStatusFiles(array $status_files, int $assignment_id, bool $is_team): void
    {
        if (empty($status_files)) {
            throw new Exception("Keine gültigen Status-Dateien gefunden.");
        }
        
        $this->ensureTemplateInitialized();
        
        try {
            $this->clearAssignmentCaches($assignment_id);
            
            $assignment = new \ilExAssignment($assignment_id);
            
            $updates_applied = false;
            $load_errors = [];
            
            foreach ($status_files as $file_path) {
                if (!file_exists($file_path)) continue;
                
                try {
                    $status_file = new ilPluginExAssignmentStatusFile();
                    $status_file->init($assignment);
                    $status_file->allowPlagiarismUpdate(true);
                    
                    $status_file->loadFromFile($file_path);
                    
                    if ($status_file->isLoadFromFileSuccess()) {
                        if ($status_file->hasUpdates()) {
                            $updates = $status_file->getUpdates();
                            $updates_count = count($updates);
                            
                            // Tracke welche User/Teams ein Update bekommen
                            $updated_user_ids = [];
                            $updated_team_ids = [];
                            
                            foreach ($updates as $update) {
                                if (isset($update['usr_id'])) {
                                    $updated_user_ids[] = (int)$update['usr_id'];
                                }
                                elseif (isset($update['team_id'])) {
                                    $team_id = (int)$update['team_id'];
                                    $updated_team_ids[] = $team_id;
                                    
                                    try {
                                        $teams = ilExAssignmentTeam::getInstancesFromMap($assignment_id);
                                        if (isset($teams[$team_id])) {
                                            $member_ids = $teams[$team_id]->getMembers();
                                            foreach ($member_ids as $member_id) {
                                                $updated_user_ids[] = $member_id;
                                            }
                                        }
                                    } catch (Exception $e) {
                                        $this->logger->warning("Could not get team members for team $team_id");
                                    }
                                }
                            }
                            
                            $this->processing_stats['updated_users'] = $updated_user_ids;
                            $this->processing_stats['updated_teams'] = $updated_team_ids;
                            
                            $this->clearAssignmentCaches($assignment_id);
                            $status_file->applyStatusUpdates();
                            $this->clearAssignmentCaches($assignment_id);
                            
                            global $DIC;
                            $DIC->ui()->mainTemplate()->setOnScreenMessage('success', $status_file->getInfo(), true);
                            
                            $this->processing_stats['status_updates'] = $updates_count;
                            $this->processing_stats['processed_file'] = basename($file_path);
                            $this->processing_stats['timestamp'] = date('Y-m-d H:i:s');
                            $updates_applied = true;
                            
                            $this->logger->debug("Applied $updates_count status updates from " . basename($file_path));
                            break;
                            
                        } else {
                            $load_errors[] = "Keine Updates in " . basename($file_path) . " gefunden.";
                        }
                    } else {
                        if ($status_file->hasError()) {
                            $load_errors[] = "Fehler beim Laden von " . basename($file_path) . ": " . $status_file->getInfo();
                        } else {
                            $load_errors[] = "Datei " . basename($file_path) . " konnte nicht geladen werden.";
                        }
                    }
                    
                } catch (Exception $e) {
                    $load_errors[] = "Fehler beim Verarbeiten von " . basename($file_path) . ": " . $e->getMessage();
                    $this->logger->error("Exception processing " . basename($file_path) . ": " . $e->getMessage());
                }
            }
            
            if (!$updates_applied) {
                $error_msg = "Keine Status-Updates wurden angewendet. ";
                if (!empty($load_errors)) {
                    $error_msg .= "Probleme: " . implode(" | ", $load_errors);
                }
                throw new Exception($error_msg);
            }
            
            $this->clearAssignmentCaches($assignment_id);
            
        } catch (Exception $e) {
            $this->logger->error("Error in status file processing: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Stellt sicher dass das Template initialisiert ist
     */
    private function ensureTemplateInitialized(): void
    {
        global $DIC;
        
        try {
            if (!isset($DIC['tpl']) || !$DIC['tpl']) {
                $DIC['tpl'] = new ilGlobalPageTemplate($DIC->globalScreen(), $DIC->ui(), $DIC->http());
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not initialize template: " . $e->getMessage());
        }
    }
    
    /**
     * Assignment-Caches leeren
     */
    private function clearAssignmentCaches(int $assignment_id): void
    {
        try {
            $session_keys_to_clear = [
                'exc_assignment_' . $assignment_id,
                'exc_members_' . $assignment_id,
                'exc_status_files_processed',
                'exc_status_files_stats',
                'exc_teams_' . $assignment_id
            ];
            
            foreach ($session_keys_to_clear as $key) {
                if (isset($_SESSION[$key])) {
                    unset($_SESSION[$key]);
                }
            }
            
            if (isset($GLOBALS['assignment_cache_' . $assignment_id])) {
                unset($GLOBALS['assignment_cache_' . $assignment_id]);
            }
            
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
        } catch (Exception $e) {
            $this->logger->warning("Cache clearing failed: " . $e->getMessage());
        }
    }
    
    /**
     * Verarbeitet Team-spezifische Feedback-Files
     */
    private function processTeamFeedbackFiles(array $extracted_files, int $assignment_id, array $checksums = []): void
    {
        $this->logger->info("processTeamFeedbackFiles: Starting with " . count($extracted_files) . " extracted files");

        $team_feedback_files = $this->findTeamFeedbackFiles($extracted_files);

        $this->logger->info("processTeamFeedbackFiles: Found " . count($team_feedback_files) . " teams with feedback files");

        if (empty($team_feedback_files)) {
            $this->logger->info("processTeamFeedbackFiles: No team feedback files found, returning");
            return;
        }

        foreach ($team_feedback_files as $team_id => $files) {
            $this->logger->info("processTeamFeedbackFiles: Processing team_id=$team_id with " . count($files) . " files");
            $this->processTeamSpecificFeedback($team_id, $files, $assignment_id, $checksums);
        }

        $this->processing_stats['team_feedback_files'] = count($team_feedback_files);
    }
    
    /**
     * Verarbeitet Individual Feedback-Files
     */
    private function processIndividualFeedbackFiles(array $extracted_files, int $assignment_id, array $checksums = []): void
    {
        $individual_feedback_files = $this->findIndividualFeedbackFiles($extracted_files);

        if (empty($individual_feedback_files)) {
            return;
        }

        foreach ($individual_feedback_files as $user_id => $files) {
            $this->processUserSpecificFeedback($user_id, $files, $assignment_id, false, $checksums);
        }

        $this->processing_stats['individual_feedback_files'] = count($individual_feedback_files);
    }
    
    /**
     * Findet Team-Feedback-Files in ZIP-Struktur
     */
    private function findTeamFeedbackFiles(array $extracted_files): array
    {
        $team_files = [];

        foreach ($extracted_files as $file) {
            $path = $file['original_name'];

            // Pattern: .../Team_13/Username/file.ext
            // Wichtig: Team_ muss nach einem / stehen (nicht in der Mitte eines Ordnernamens)
            if (preg_match('/\/Team_(\d+)\/[^\/]+\/([^\/]+)$/', $path, $matches)) {
                $team_id = (int)$matches[1];
                $filename = $matches[2];

                $this->logger->info("findTeamFeedbackFiles: Found file '$filename' for team_id=$team_id in path: $path");

                if (!isset($team_files[$team_id])) {
                    $team_files[$team_id] = [];
                }

                $team_files[$team_id][] = [
                    'filename' => $filename,
                    'path' => $file['extracted_path'],
                    'original_path' => $path
                ];
            }
        }

        $this->logger->info("findTeamFeedbackFiles: Returning " . count($team_files) . " teams: " . implode(', ', array_keys($team_files)));

        return $team_files;
    }
    
    /**
     * Findet Individual-Feedback-Files
     */
    private function findIndividualFeedbackFiles(array $extracted_files): array
    {
        $individual_files = [];

        $this->logger->info("findIndividualFeedbackFiles: Processing " . count($extracted_files) . " extracted files");

        foreach ($extracted_files as $file) {
            $path = $file['original_name'];

            $this->logger->debug("findIndividualFeedbackFiles: Checking file: $path");

            // Pattern: IRGENDETWAS/Lastname_Firstname_Login_12345/filename.ext
            if (preg_match('/\/([^\/]+)_([^\/]+)_([^\/]+)_(\d+)\/([^\/]+)$/', $path, $matches)) {
                $user_id = (int)$matches[4];
                $filename = $matches[5];
                
                if (!isset($individual_files[$user_id])) {
                    $individual_files[$user_id] = [];
                }
                
                $individual_files[$user_id][] = [
                    'filename' => $filename,
                    'path' => $file['extracted_path'],
                    'original_path' => $path
                ];

                $this->logger->info("findIndividualFeedbackFiles: MATCHED file '$filename' for user_id=$user_id");
            } else {
                $this->logger->debug("findIndividualFeedbackFiles: NO MATCH for path: $path");
            }
        }

        $this->logger->info("findIndividualFeedbackFiles: Found " . count($individual_files) . " users with feedback files");

        return $individual_files;
    }
    
    /**
     * Verarbeitet Team-spezifisches Feedback
     */
    private function processTeamSpecificFeedback(int $team_id, array $files, int $assignment_id, array $checksums = []): void
    {
        try {
            $teams = ilExAssignmentTeam::getInstancesFromMap($assignment_id);

            if (!isset($teams[$team_id])) {
                $this->logger->warning("Team $team_id not found in assignment $assignment_id");
                return;
            }

            $team = $teams[$team_id];
            $member_ids = $team->getMembers();

            if (empty($member_ids)) {
                $this->logger->warning("Team $team_id has no members");
                return;
            }

            // Feedback-Dateien werden immer verarbeitet, auch ohne Status-Update
            // Dies macht die Handhabung für Tutoren intuitiver

            $this->logger->debug("Processing feedback for team $team_id with " . count($member_ids) . " members");

            // Verarbeite Files für JEDES Team-Mitglied
            foreach ($member_ids as $member_id) {
                $existing_submissions = $this->getExistingSubmissionFiles($assignment_id, $member_id);
                $new_feedback_files = $this->filterNewFeedbackFiles($files, $existing_submissions, $checksums);

                if (!empty($new_feedback_files)) {
                    // Already filtered, so skip filtering in processUserSpecificFeedback
                    $this->processUserSpecificFeedback($member_id, $new_feedback_files, $assignment_id, true, $checksums);
                }
            }

        } catch (Exception $e) {
            $this->logger->error("Error processing team feedback for team $team_id: " . $e->getMessage());
        }
    }
    
    /**
     * Verarbeitet User-spezifisches Feedback
     * @param bool $already_filtered Ob die Files bereits gefiltert wurden (vermeidet doppelte Filterung)
     */
    private function processUserSpecificFeedback(int $user_id, array $files, int $assignment_id, bool $already_filtered = false, array $checksums = []): void
    {
        try {
            $this->logger->info("processUserSpecificFeedback: Starting for user_id=$user_id with " . count($files) . " files, already_filtered=" . ($already_filtered ? 'true' : 'false'));

            // Feedback-Dateien werden immer verarbeitet, auch ohne Status-Update
            // Dies macht die Handhabung für Tutoren intuitiver

            // Nur filtern wenn noch nicht gefiltert
            if (!$already_filtered) {
                $existing_submissions = $this->getExistingSubmissionFiles($assignment_id, $user_id);
                $this->logger->info("processUserSpecificFeedback: Found " . count($existing_submissions) . " existing submissions for user_id=$user_id");
                $new_feedback_files = $this->filterNewFeedbackFiles($files, $existing_submissions, $checksums);
            } else {
                $new_feedback_files = $files;
            }

            if (empty($new_feedback_files)) {
                $this->logger->info("processUserSpecificFeedback: No new feedback files for user_id=$user_id after filtering - RETURNING");
                return;
            }

            $this->logger->info("processUserSpecificFeedback: Processing " . count($new_feedback_files) . " new feedback files for user $user_id");
            
            $assignment = new \ilExAssignment($assignment_id);
            $is_team = $assignment->getAssignmentType()->usesTeams();
            
            // Bestimme Participant-ID
            $participant_id = $user_id;
            if ($is_team) {
                $teams = \ilExAssignmentTeam::getInstancesFromMap($assignment_id);
                foreach ($teams as $team_id => $team) {
                    if (in_array($user_id, $team->getMembers())) {
                        $participant_id = $team_id;
                        break;
                    }
                }
            }
            
            // Resource Storage oder Filesystem?
            $feedback_rcid = $this->getFeedbackCollectionIdFromDB($assignment_id, $participant_id);
            
            if (empty($feedback_rcid)) {
                $this->logger->debug("Using filesystem storage for participant $participant_id");
                $this->addFeedbackFilesViaFilesystem($user_id, $participant_id, $new_feedback_files, $assignment_id, $is_team);
            } else {
                $this->logger->debug("Using resource storage for participant $participant_id");
                $this->addFeedbackFilesViaResourceStorage($user_id, $participant_id, $new_feedback_files, $assignment_id, $is_team, $feedback_rcid);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error processing feedback for user $user_id: " . $e->getMessage());
        }
    }

    /**
     * Fügt Feedback-Files via Resource Storage hinzu
     */
    private function addFeedbackFilesViaResourceStorage(int $user_id, int $participant_id, array $files, int $assignment_id, bool $is_team, string $feedback_rcid): void
    {
        global $DIC;
        
        try {
            $rcid = $DIC->resourceStorage()->collection()->id($feedback_rcid);
            $collection = $DIC->resourceStorage()->collection()->get($rcid);
            
            if (!$collection) {
                throw new Exception("Could not get resource collection for participant $participant_id");
            }
            
            if ($is_team) {
                $stakeholder = new \ilExcTutorTeamFeedbackFileStakeholder();
            } else {
                $stakeholder = new \ilExcTutorFeedbackFileStakeholder();
            }
            
            $files_added = 0;
            foreach ($files as $file_data) {
                try {
                    $file_path = $file_data['path'];
                    $filename = $file_data['filename'];

                    if (!file_exists($file_path)) {
                        $this->logger->warning("Feedback file does not exist: $file_path");
                        continue;
                    }

                    $stream = \ILIAS\Filesystem\Stream\Streams::ofResource(fopen($file_path, 'rb'));

                    $rid = $DIC->resourceStorage()->manage()->stream(
                        $stream,
                        $stakeholder,
                        $filename
                    );
                    
                    $collection->add($rid);
                    $files_added++;
                    
                } catch (Exception $e) {
                    $this->logger->error("Error adding feedback file '$filename': " . $e->getMessage());
                }
            }
            
            if ($files_added > 0) {
                $DIC->resourceStorage()->collection()->store($collection);

                $member_status = new \ilExAssignmentMemberStatus($assignment_id, $user_id);
                $member_status->setFeedback(true);
                $member_status->update();

                $this->logger->debug("Successfully added $files_added feedback files via resource storage for user $user_id");

                // E-Mail-Benachrichtigung via Lazy Loading
                require_once __DIR__ . '/class.ilExFeedbackNotificationSender.php';
                $notifier = new ilExFeedbackNotificationSender();
                $notifier->sendNotification($assignment_id, $user_id, $is_team);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error in resource storage feedback: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Fügt Feedback-Files via Filesystem hinzu
     */
    private function addFeedbackFilesViaFilesystem(int $user_id, int $participant_id, array $files, int $assignment_id, bool $is_team): void
    {
        $feedback_id = (string)$participant_id;
        if ($is_team) {
            $feedback_id = "t" . $participant_id;
        }
        
        $exc_id = \ilExAssignment::lookupExerciseId($assignment_id);
        $storage = new \ilFSStorageExercise($exc_id, $assignment_id);
        $storage->create();
        
        $feedback_path = $storage->getFeedbackPath($feedback_id);
        
        if (!is_dir($feedback_path)) {
            \ilFileUtils::makeDirParents($feedback_path);
        }
        
        $files_added = 0;
        foreach ($files as $file_data) {
            try {
                $file_path = $file_data['path'];
                $filename = $file_data['filename'];

                if (!file_exists($file_path)) {
                    $this->logger->warning("Feedback file does not exist: $file_path");
                    continue;
                }

                $target_path = $feedback_path . '/' . $filename;

                if (copy($file_path, $target_path)) {
                    $files_added++;
                } else {
                    $this->logger->error("Could not copy feedback file '$filename'");
                }
                
            } catch (Exception $e) {
                $this->logger->error("Error adding feedback file '$filename': " . $e->getMessage());
            }
        }
        
        if ($files_added > 0) {
            $member_status = new \ilExAssignmentMemberStatus($assignment_id, $user_id);
            $member_status->setFeedback(true);
            $member_status->update();

            $this->logger->debug("Successfully added $files_added feedback files via filesystem for user $user_id");

            // E-Mail-Benachrichtigung via Lazy Loading
            require_once __DIR__ . '/class.ilExFeedbackNotificationSender.php';
            $notifier = new ilExFeedbackNotificationSender();
            $notifier->sendNotification($assignment_id, $user_id, $is_team);
        }
    }

    /**
     * Holt die Feedback Collection-ID aus der Datenbank
     */
    private function getFeedbackCollectionIdFromDB(int $assignment_id, int $participant_id): string
    {
        global $DIC;
        $db = $DIC->database();
        
        try {
            $query = "SELECT feedback_rcid FROM exc_mem_ass_status 
                      WHERE ass_id = " . $db->quote($assignment_id, 'integer') . " 
                      AND usr_id = " . $db->quote($participant_id, 'integer');
            
            $result = $db->query($query);
            
            if ($row = $db->fetchAssoc($result)) {
                return (string)($row['feedback_rcid'] ?? '');
            }
        } catch (Exception $e) {
            $this->logger->error("getFeedbackCollectionIdFromDB failed: " . $e->getMessage());
        }
        
        return '';
    }

    /**
     * Hole bereits abgegebene Submissions des Users
     */
    private function getExistingSubmissionFiles(int $assignment_id, int $user_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $existing_files = [];
        
        try {
            $assignment = new \ilExAssignment($assignment_id);
            $is_team = $assignment->getAssignmentType()->usesTeams();
            
            $user_ids = [$user_id];
            
            if ($is_team) {
                $teams = \ilExAssignmentTeam::getInstancesFromMap($assignment_id);
                foreach ($teams as $team_id => $team) {
                    if (in_array($user_id, $team->getMembers())) {
                        $user_ids = $team->getMembers();
                        break;
                    }
                }
            }
            
            foreach ($user_ids as $uid) {
                $query = "SELECT filename FROM exc_returned 
                        WHERE ass_id = " . $db->quote($assignment_id, 'integer') . " 
                        AND user_id = " . $db->quote($uid, 'integer') . "
                        AND mimetype IS NOT NULL";
                
                $result = $db->query($query);
                
                while ($row = $db->fetchAssoc($result)) {
                    $filename = basename($row['filename']);
                    $clean_filename = preg_replace('/^(\d{14})_/', '', $filename);
                    $existing_files[] = $clean_filename;
                }
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error getting existing submissions: " . $e->getMessage());
        }
        
        return $existing_files;
    }

    /**
     * Filtere neue Feedback-Files
     * Prüft gegen existing submissions und optional gegen checksums
     */
    private function filterNewFeedbackFiles(array $files, array $existing_submissions, array $checksums = []): array
    {
        $new_files = [];

        $this->logger->debug("filterNewFeedbackFiles: Checking " . count($files) . " files against " . count($existing_submissions) . " existing submissions");
        if (!empty($checksums)) {
            $this->logger->debug("filterNewFeedbackFiles: Checksum validation enabled with " . count($checksums) . " checksums");
        }

        foreach ($files as $file) {
            $filename = $file['filename'];
            $original_path = $file['original_path'] ?? '';

            // Skip System-Files (nur im Root, NICHT in User-Ordnern)
            // User-Ordner haben das Pattern: .../Lastname_Firstname_Login_12345/filename
            // System-Files im Root haben KEIN User-Ordner-Prefix
            $system_filenames = ['status.xlsx', 'status.csv', 'status.xls', 'checksums.json', 'README.md'];
            $is_in_user_folder = preg_match('/\/[^\/]+_[^\/]+_[^\/]+_\d+\/[^\/]+$/', $original_path);

            if (in_array($filename, $system_filenames) && !$is_in_user_folder) {
                // System-File im Root → filtern
                $this->logger->debug("filterNewFeedbackFiles: Skipping root system file: $filename (path: $original_path)");
                continue;
            }

            // Info-Log wenn ein System-Filename in einem User-Ordner gefunden wird
            if (in_array($filename, $system_filenames) && $is_in_user_folder) {
                $this->logger->info("filterNewFeedbackFiles: Found system filename '$filename' in user folder - will be processed normally (path: $original_path)");
            }

            if (substr($filename, 0, 1) === '.' || substr($filename, 0, 2) === '__') {
                $this->logger->debug("filterNewFeedbackFiles: Skipping hidden file: $filename");
                continue;
            }

            // Normalisiere Dateinamen
            $clean_filename = preg_replace('/^(\d{14})_/', '', $filename);

            // Prüfe ob File bereits als Submission existiert
            $is_submission = false;
            $matched_submission = null;

            foreach ($existing_submissions as $submission) {
                if ($filename === $submission || $clean_filename === $submission) {
                    $is_submission = true;
                    $matched_submission = $submission;
                    break;
                }

                $clean_submission = preg_replace('/^(\d{14})_/', '', $submission);
                if ($clean_filename === $clean_submission) {
                    $is_submission = true;
                    $matched_submission = $submission;
                    break;
                }
            }

            // Wenn es eine Submission ist, prüfe Hash wenn checksums verfügbar
            if ($is_submission && !empty($checksums)) {
                $file_modified = $this->checkIfFileModified($file, $checksums);

                if ($file_modified) {
                    // Datei wurde modifiziert - umbenennen und als Feedback hochladen
                    $renamed_file = $this->renameModifiedSubmission($file);
                    $new_files[] = $renamed_file;

                    $this->logger->info("filterNewFeedbackFiles: '$filename' is modified submission - renamed to '{$renamed_file['filename']}' - ADDED as feedback");
                    continue;
                } else {
                    // Datei ist identisch - filtern
                    $this->logger->debug("filterNewFeedbackFiles: '$filename' matches existing submission '$matched_submission' (identical hash) - FILTERED OUT");
                    continue;
                }
            }

            if ($is_submission) {
                // Submission ohne checksum validation - wie bisher filtern
                $this->logger->debug("filterNewFeedbackFiles: '$filename' matches existing submission '$matched_submission' - FILTERED OUT");
                continue;
            }

            // Neue Feedback-Datei
            $this->logger->debug("filterNewFeedbackFiles: '$filename' is NEW feedback file - ADDED");
            $new_files[] = $file;
        }

        $this->logger->debug("filterNewFeedbackFiles: Result: " . count($new_files) . " new feedback files");

        return $new_files;
    }

    /**
     * Prüft ob eine Datei im Vergleich zur Original-Submission modifiziert wurde
     */
    private function checkIfFileModified(array $file, array $checksums): bool
    {
        $file_path = $file['path'];
        $original_path = $file['original_path'];

        // Finde den Checksum-Eintrag für diese Datei
        // Der Pfad in checksums.json entspricht dem original_path
        if (!isset($checksums[$original_path])) {
            $this->logger->debug("checkIfFileModified: No checksum found for '$original_path' - treating as unmodified");
            return false;
        }

        $stored_checksum = $checksums[$original_path]['md5'] ?? null;

        if (!$stored_checksum) {
            $this->logger->debug("checkIfFileModified: No MD5 in checksum data for '$original_path'");
            return false;
        }

        if (!file_exists($file_path)) {
            $this->logger->warning("checkIfFileModified: File does not exist: '$file_path'");
            return false;
        }

        $current_hash = md5_file($file_path);

        if ($current_hash !== $stored_checksum) {
            $this->logger->info("checkIfFileModified: File '$original_path' was MODIFIED (hash mismatch)");
            $this->logger->debug("checkIfFileModified: Original hash: $stored_checksum, Current hash: $current_hash");
            return true;
        }

        return false;
    }

    /**
     * Benennt eine modifizierte Submission-Datei um
     * @return array Modified file array mit neuem filename
     */
    private function renameModifiedSubmission(array $file): array
    {
        $original_filename = $file['filename'];

        // Extrahiere Name und Extension
        $pathinfo = pathinfo($original_filename);
        $basename = $pathinfo['filename'];
        $extension = isset($pathinfo['extension']) ? '.' . $pathinfo['extension'] : '';

        // Neuer Name: Original_korrigiert.ext
        $new_filename = $basename . '_korrigiert' . $extension;

        // Track für Upload-Response
        $this->renamed_files[] = [
            'original' => $original_filename,
            'renamed' => $new_filename,
            'original_path' => $file['original_path']
        ];

        // WICHTIG: Physische Datei auf Festplatte umbenennen!
        // ILIAS nimmt den Dateinamen aus der physischen Datei, nicht aus unserem Parameter
        $old_path = $file['path'];
        $new_path = dirname($old_path) . '/' . $new_filename;

        if (file_exists($old_path)) {
            if (rename($old_path, $new_path)) {
                $this->logger->info("renameModifiedSubmission: Successfully renamed physical file from '$original_filename' to '$new_filename'");
            } else {
                $this->logger->error("renameModifiedSubmission: Failed to rename physical file from '$old_path' to '$new_path'");
                // Fallback: behalte alten Path
                $new_path = $old_path;
            }
        } else {
            $this->logger->warning("renameModifiedSubmission: Source file does not exist: $old_path");
            $new_path = $old_path;
        }

        // Erstelle neue File-Array mit neuem Namen UND neuem Path
        $renamed_file = $file;
        $renamed_file['filename'] = $new_filename;
        $renamed_file['path'] = $new_path;  // ← WICHTIG: Neuer Path!
        $renamed_file['was_renamed'] = true;
        $renamed_file['original_filename'] = $original_filename;

        return $renamed_file;
    }
    
    /**
     * Setzt Processing-Success-Flag
     */
    private function setProcessingSuccess(int $assignment_id, int $tutor_id): void
    {
        // Füge renamed files zu den stats hinzu
        if (!empty($this->renamed_files)) {
            $this->processing_stats['renamed_files'] = $this->renamed_files;
            $this->processing_stats['renamed_count'] = count($this->renamed_files);
        }

        $_SESSION['exc_status_files_processed'][$assignment_id][$tutor_id] = time();
        $_SESSION['exc_status_files_stats'][$assignment_id][$tutor_id] = $this->processing_stats;
    }
    
    /**
     * Temp-Directory erstellen
     */
    private function createTempDirectory(string $prefix): string
    {
        $temp_dir = sys_get_temp_dir() . '/plugin_' . $prefix . '_' . uniqid();
        mkdir($temp_dir, 0777, true);
        $this->temp_directories[] = $temp_dir;
        
        return $temp_dir;
    }
    
    /**
     * Temp-File aufräumen
     */
    private function cleanupTempFile(string $file_path): void
    {
        if (file_exists($file_path)) {
            unlink($file_path);
        }
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
    
    /**
     * Get Processing Statistics
     */
    public function getProcessingStats(): array
    {
        return $this->processing_stats;
    }
}
?>