<?php
declare(strict_types=1);

/**
 * Base Class for Multi-Feedback Download Handlers
 *
 * Enthält gemeinsame Funktionalität für Team- und Individual-Download-Handler
 *
 * @author Cornel Musielak
 * @version 1.2.0
 */
abstract class ilExMultiFeedbackDownloadHandlerBase
{
    protected ilLogger $logger;
    protected array $temp_directories = [];
    protected ?ilExerciseStatusFilePlugin $plugin = null;

    /**
     * Gibt den Entity-Typ zurück ('team' oder 'user')
     */
    abstract protected function getEntityType(): string;

    /**
     * Gibt den Temp-Directory-Prefix zurück
     */
    abstract protected function getTempDirectoryPrefix(): string;

    /**
     * Generiert den README-Inhalt
     */
    abstract protected function generateReadmeContent(\ilExAssignment $assignment, array $entities): string;

    /**
     * Generiert den Fallback README-Inhalt (ohne Plugin-Übersetzungen)
     */
    abstract protected function generateReadmeContentFallback(\ilExAssignment $assignment, array $entities): string;

    /**
     * Generiert den Entity-Overview für README
     */
    abstract protected function generateEntityOverviewForReadme(array $entities): string;

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();

        // Plugin-Instanz für Übersetzungen
        try {
            $plugin_id = 'exstatusfile';
            $repo = $DIC['component.repository'];
            $factory = $DIC['component.factory'];

            $info = $repo->getPluginById($plugin_id);
            if ($info !== null && $info->isActive()) {
                $this->plugin = $factory->getPlugin($plugin_id);
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not load plugin for translations: " . $e->getMessage());
            $this->plugin = null;
        }

        register_shutdown_function([$this, 'cleanupAllTempDirectories']);
    }

    /**
     * Status-Files erstellen und Checksums zurückgeben
     * @return array Checksums der Status-Dateien für spätere Änderungserkennung
     */
    protected function addStatusFiles(\ZipArchive &$zip, \ilExAssignment $assignment, array $entities, string $temp_dir): array
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
     * Checksum-Datei hinzufügen
     */
    protected function addChecksumsFile(\ZipArchive &$zip, array $checksums, string $temp_dir): void
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
     * README erstellen
     */
    protected function addReadme(\ZipArchive &$zip, \ilExAssignment $assignment, array $entities, string $temp_dir): void
    {
        $readme_content = $this->plugin
            ? $this->generateReadmeContent($assignment, $entities)
            : $this->generateReadmeContentFallback($assignment, $entities);
        $readme_path = $temp_dir . '/README.md';

        file_put_contents($readme_path, $readme_content);
        $zip->addFile($readme_path, "README.md");
    }

    /**
     * ZIP-Download senden
     */
    protected function sendZIPDownload(string $zip_path, \ilExAssignment $assignment, array $entities): void
    {
        if (!file_exists($zip_path)) {
            throw new Exception("ZIP file not found: $zip_path");
        }

        $filename = $this->generateDownloadFilename($assignment, $entities);
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
    protected function sendErrorResponse(string $message): void
    {
        $this->logger->error("Multi-Feedback error: " . $message);

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
    protected function generateZIPFilename(\ilExAssignment $assignment, array $entities): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $count = count($entities);
        $timestamp = date('Y-m-d_H-i-s');

        if ($this->getEntityType() === 'team') {
            return "Multi_Feedback_{$base_name}_{$count}_Teams_{$timestamp}.zip";
        } else {
            return "Multi_Feedback_Individual_{$base_name}_{$count}_Users_{$timestamp}.zip";
        }
    }

    /**
     * Download-Filename generieren
     */
    protected function generateDownloadFilename(\ilExAssignment $assignment, array $entities): string
    {
        $base_name = $this->toAscii($assignment->getTitle());
        $count = count($entities);

        if ($this->getEntityType() === 'team') {
            return "Multi_Feedback_{$base_name}_{$count}_Teams.zip";
        } else {
            return "Multi_Feedback_Individual_{$base_name}_{$count}_Users.zip";
        }
    }

    /**
     * User-Folder-Name generieren
     */
    protected function generateUserFolderName(array $user_data): string
    {
        return $this->toAscii(
            $user_data['lastname'] . "_" .
            $user_data['firstname'] . "_" .
            $user_data['login'] . "_" .
            $user_data['user_id']
        );
    }

    /**
     * Entfernt ILIAS Timestamp-Prefix aus Dateinamen
     * z.B.: 20251009061955_what_jpg.jpg -> what_jpg.jpg
     */
    protected function removeILIASTimestampPrefix(string $filename): string
    {
        if (preg_match('/^(\d{14})_(.+)$/', $filename, $matches)) {
            return $matches[2];
        }
        return $filename;
    }

    /**
     * Submitted Files direkt aus DB holen (ohne ilExSubmission Template-Abhängigkeit)
     */
    protected function getSubmittedFilesFromDB(int $assignment_id, int $user_id): array
    {
        global $DIC;
        $db = $DIC->database();
        $files = [];

        try {
            $query = "SELECT * FROM exc_returned
                      WHERE ass_id = " . $db->quote($assignment_id, 'integer') . "
                      AND user_id = " . $db->quote($user_id, 'integer') . "
                      AND mimetype IS NOT NULL
                      ORDER BY ts DESC";

            $result = $db->query($query);

            while ($row = $db->fetchAssoc($result)) {
                $filename = $row['filename'];
                $client_data_dir = CLIENT_DATA_DIR;
                $possible_paths = [];

                if (strpos($filename, '/') === 0) {
                    $possible_paths[] = $filename;
                } else {
                    $possible_paths[] = $client_data_dir . "/" . $filename;
                }

                if (strpos($filename, '/') === false) {
                    $exercise_id = $this->getExerciseIdFromAssignment($assignment_id);
                    if ($exercise_id) {
                        $possible_paths[] = $client_data_dir . "/ilExercise/" . $exercise_id . "/exc_" . $assignment_id . "/" . $user_id . "/" . $filename;
                        $possible_paths[] = $client_data_dir . "/ilExercise/exc_" . $exercise_id . "/subm_" . $assignment_id . "/" . $user_id . "/" . $filename;
                    }
                }

                $file_path = null;
                $basename = basename($filename);

                foreach ($possible_paths as $path) {
                    if (file_exists($path) && is_readable($path)) {
                        $file_path = $path;
                        $this->logger->debug("Found file at: $path");
                        break;
                    }
                }

                if ($file_path) {
                    $files[] = [
                        'filename' => $basename,
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
    protected function getExerciseIdFromAssignment(int $assignment_id): ?int
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
     * Temp-Directory erstellen
     */
    protected function createTempDirectory(string $prefix): string
    {
        $temp_dir = sys_get_temp_dir() . '/' . $this->getTempDirectoryPrefix() . $prefix . '_' . uniqid();
        mkdir($temp_dir, 0777, true);
        $this->temp_directories[] = $temp_dir;

        return $temp_dir;
    }

    /**
     * ASCII-Konvertierung
     */
    protected function toAscii(string $filename): string
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
    protected function cleanupTempDirectory(string $temp_dir): void
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
