<?php
declare(strict_types=1);

/**
 * Feedback Download Handler
 * 
 * Verarbeitet Feedback-Downloads und fügt Status-Files hinzu
 * Unterstützt Individual- und Team-Assignments
 * 
 * @author Cornel Musielak
 * @version 1.1.0
 */
class ilExFeedbackDownloadHandler
{
    private ilLogger $logger;
    private array $temp_directories = [];
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        
        register_shutdown_function([$this, 'cleanupAllTempDirectories']);
    }
    
    /**
     * Feedback Download Processing
     */
    public function handleFeedbackDownload(array $parameters): void
    {
        if (!$this->validateDownloadParameters($parameters)) {
            $this->logger->warning("Invalid download parameters provided");
            return;
        }
        
        try {
            $assignment = $parameters['assignment'];
            $members = $parameters['members'];
            $zip = &$parameters['zip'];
            
            if ($assignment->getAssignmentType()->usesTeams()) {
                $this->processTeamDownload($zip, $assignment, $members);
            } else {
                $this->processIndividualDownload($zip, $assignment, $members);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Feedback download processing error: " . $e->getMessage());
        }
    }
    
    /**
     * Team Assignment Download Processing
     */
    private function processTeamDownload(\ZipArchive &$zip, \ilExAssignment $assignment, array $members): void
    {
        $this->addStatusFilesToZip($zip, $assignment, $members, true);
        $this->createTeamStructureInZip($zip, $assignment);
        $this->addTeamReadmeToZip($zip, $assignment);
    }
    
    /**
     * Individual Assignment Download Processing
     */
    private function processIndividualDownload(\ZipArchive &$zip, \ilExAssignment $assignment, array $members): void
    {
        $this->addStatusFilesToZip($zip, $assignment, $members, false);
    }
    
    /**
     * Status-Files zum ZIP hinzufügen
     */
    private function addStatusFilesToZip(\ZipArchive &$zip, \ilExAssignment $assignment, array $members, bool $is_team): void
    {
        $temp_dir = $this->createTempDirectory('status_files');
        
        try {
            // XLSX Status File
            $xlsx_success = $this->createStatusFile($assignment, $temp_dir, 'xlsx');
            if ($xlsx_success) {
                $zip->addFile($temp_dir . '/status.xlsx', "status.xlsx");
            }
            
            // CSV Status File
            $csv_success = $this->createStatusFile($assignment, $temp_dir, 'csv');
            if ($csv_success) {
                $zip->addFile($temp_dir . '/status.csv', "status.csv");
            }
            
            if ($is_team) {
                $this->addTeamSpecificStatusFiles($zip, $assignment, $temp_dir);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error creating status files: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Status-File erstellen
     */
    private function createStatusFile(\ilExAssignment $assignment, string $temp_dir, string $format): bool
    {
        try {
            $status_file = new ilPluginExAssignmentStatusFile();
            $status_file->init($assignment);
            
            $file_format = ($format === 'xlsx') ? ilPluginExAssignmentStatusFile::FORMAT_XML : ilPluginExAssignmentStatusFile::FORMAT_CSV;
            $status_file->setFormat($file_format);
            
            $file_path = $temp_dir . '/status.' . $format;
            $status_file->writeToFile($file_path);
            
            if ($status_file->isWriteToFileSuccess() && file_exists($file_path)) {
                return true;
            }
            
            $this->logger->warning("Failed to create status.$format");
            return false;
            
        } catch (Exception $e) {
            $this->logger->error("Error creating status.$format: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Team-Struktur im ZIP erstellen
     */
    private function createTeamStructureInZip(\ZipArchive &$zip, \ilExAssignment $assignment): void
    {
        try {
            $base_name = $this->generateBaseName($assignment);
            $teams = ilExAssignmentTeam::getInstancesFromMap($assignment->getId());
            
            foreach ($teams as $team_id => $team) {
                $team_path = $base_name . "/Team_" . $team_id;
                $zip->addEmptyDir($team_path);
                
                foreach ($team->getMembers() as $user_id) {
                    $user_dir = $this->generateUserDirectory($user_id);
                    if ($user_dir) {
                        $user_path = $team_path . "/" . $user_dir;
                        $zip->addEmptyDir($user_path);
                    }
                }
                
                $this->addTeamInfoFile($zip, $team_path, $team);
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error creating team structure: " . $e->getMessage());
        }
    }
    
    /**
     * Team-spezifische Status-Files hinzufügen
     */
    private function addTeamSpecificStatusFiles(\ZipArchive &$zip, \ilExAssignment $assignment, string $temp_dir): void
    {
        try {
            $team_overview = $this->generateTeamOverview($assignment);
            $overview_path = $temp_dir . '/team_overview.txt';
            file_put_contents($overview_path, $team_overview);
            $zip->addFile($overview_path, "team_overview.txt");
            
            $team_mapping = $this->generateTeamMapping($assignment);
            $mapping_path = $temp_dir . '/team_mapping.json';
            file_put_contents($mapping_path, json_encode($team_mapping, JSON_PRETTY_PRINT));
            $zip->addFile($mapping_path, "team_mapping.json");
            
        } catch (Exception $e) {
            $this->logger->error("Error creating team-specific files: " . $e->getMessage());
        }
    }
    
    /**
     * Team-README zum ZIP hinzufügen
     */
    private function addTeamReadmeToZip(\ZipArchive &$zip, \ilExAssignment $assignment): void
    {
        $temp_dir = $this->createTempDirectory('readme');
        $readme_content = $this->generateTeamReadme($assignment);
        $readme_path = $temp_dir . '/README_TEAMS.md';
        
        file_put_contents($readme_path, $readme_content);
        $zip->addFile($readme_path, "README_TEAMS.md");
    }
    
    /**
     * Team-Info-File für einzelnes Team
     */
    private function addTeamInfoFile(\ZipArchive &$zip, string $team_path, ilExAssignmentTeam $team): void
    {
        $temp_dir = $this->createTempDirectory('team_info');
        $info_content = $this->generateTeamInfo($team);
        $info_path = $temp_dir . '/team_info.txt';
        
        file_put_contents($info_path, $info_content);
        $zip->addFile($info_path, $team_path . "/team_info.txt");
    }
    
    /**
     * Basis-Name für ZIP-Struktur generieren
     */
    private function generateBaseName(\ilExAssignment $assignment): string
    {
        $title = $assignment->getTitle();
        $id = $assignment->getId();
        $clean_title = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
        
        return "multi_feedback_" . $clean_title . "_" . $id;
    }
    
    /**
     * User-Directory-Name generieren
     */
    private function generateUserDirectory(int $user_id): ?string
    {
        $user_data = \ilObjUser::_lookupName($user_id);
        if (!$user_data || !$user_data['login']) return null;
        
        $dir_name = $user_data["lastname"] . "_" . 
                   $user_data["firstname"] . "_" . 
                   $user_data["login"] . "_" . 
                   $user_id;
        
        return $this->toAscii($dir_name);
    }
    
    /**
     * Team-Overview generieren
     */
    private function generateTeamOverview(\ilExAssignment $assignment): string
    {
        $teams = ilExAssignmentTeam::getInstancesFromMap($assignment->getId());
        $content = "TEAM OVERVIEW - Assignment: " . $assignment->getTitle() . "\n";
        $content .= "Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($teams as $team_id => $team) {
            $content .= "Team $team_id:\n";
            foreach ($team->getMembers() as $user_id) {
                $user_data = \ilObjUser::_lookupName($user_id);
                $content .= "  - " . $user_data['firstname'] . " " . $user_data['lastname'] . " (" . $user_data['login'] . ")\n";
            }
            $content .= "\n";
        }
        
        return $content;
    }
    
    /**
     * Team-Mapping generieren
     */
    private function generateTeamMapping(\ilExAssignment $assignment): array
    {
        $teams = ilExAssignmentTeam::getInstancesFromMap($assignment->getId());
        $mapping = [];
        
        foreach ($teams as $team_id => $team) {
            $mapping[$team_id] = [
                'team_id' => $team_id,
                'members' => []
            ];
            
            foreach ($team->getMembers() as $user_id) {
                $user_data = \ilObjUser::_lookupName($user_id);
                $mapping[$team_id]['members'][] = [
                    'user_id' => $user_id,
                    'login' => $user_data['login'],
                    'firstname' => $user_data['firstname'],
                    'lastname' => $user_data['lastname']
                ];
            }
        }
        
        return $mapping;
    }
    
    /**
     * Team-README generieren
     */
    private function generateTeamReadme(\ilExAssignment $assignment): string
    {
        return "# Team Multi-Feedback - " . $assignment->getTitle() . "\n\n" .
               "## Struktur\n\n" .
               "Dieses ZIP enthält eine team-basierte Struktur für Multi-Feedback:\n\n" .
               "- `status.xlsx` / `status.csv`: Status-Files für Updates\n" .
               "- `team_overview.txt`: Übersicht aller Teams\n" .
               "- `team_mapping.json`: Team-Mapping für Import\n" .
               "- `Team_X/`: Ordner für jedes Team\n" .
               "  - `Lastname_Firstname_Login_ID/`: Ordner für jedes Team-Mitglied\n" .
               "  - `team_info.txt`: Team-spezifische Informationen\n\n" .
               "## Usage\n\n" .
               "1. Bearbeite die Status-Files (Excel/CSV)\n" .
               "2. Füge Feedback-Files in die entsprechenden User-Ordner\n" .
               "3. ZIP wieder hochladen für automatische Verarbeitung\n\n" .
               "Generated: " . date('Y-m-d H:i:s') . "\n" .
               "Plugin: ExerciseStatusFile v1.1.0\n";
    }
    
    /**
     * Team-Info generieren
     */
    private function generateTeamInfo(ilExAssignmentTeam $team): string
    {
        $content = "TEAM INFO - Team ID: " . $team->getId() . "\n";
        $content .= "Members:\n";
        
        foreach ($team->getMembers() as $user_id) {
            $user_data = \ilObjUser::_lookupName($user_id);
            $content .= "- " . $user_data['firstname'] . " " . $user_data['lastname'] . " (" . $user_data['login'] . ") [ID: $user_id]\n";
        }
        
        return $content;
    }
    
    /**
     * Parameter-Validierung
     */
    private function validateDownloadParameters(array $parameters): bool
    {
        return isset($parameters['assignment']) && 
               isset($parameters['members']) && 
               isset($parameters['zip']) &&
               $parameters['assignment'] instanceof \ilExAssignment &&
               is_array($parameters['members']) &&
               $parameters['zip'] instanceof \ZipArchive;
    }
    
    /**
     * Temp-Directory erstellen
     */
    private function createTempDirectory(string $prefix): string
    {
        $temp_dir = sys_get_temp_dir() . '/plugin_' . $prefix . '_' . uniqid();
        mkdir($temp_dir, 0700, true);  // Nur Owner-Zugriff für Sicherheit
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