<?php
declare(strict_types=1);

require_once __DIR__ . '/class.ilExMultiFeedbackDownloadHandlerBase.php';

/**
 * Team Multi-Feedback Download Handler
 *
 * Verarbeitet Multi-Team-Downloads und generiert strukturierte ZIPs
 *
 * @author Cornel Musielak
 * @version 1.2.0
 */
class ilExTeamMultiFeedbackDownloadHandler extends ilExMultiFeedbackDownloadHandlerBase
{
    private ilExTeamDataProvider $team_provider;

    public function __construct()
    {
        parent::__construct();
        $this->team_provider = new ilExTeamDataProvider();
    }

    protected function getEntityType(): string
    {
        return 'team';
    }

    protected function getTempDirectoryPrefix(): string
    {
        return 'plugin_team_multi_feedback_';
    }

    /**
     * Multi-Feedback-Download für ausgewählte Teams generieren
     */
    public function generateMultiFeedbackDownload(int $assignment_id, array $team_ids): void
    {
        try {
            $this->logger->info("Team Multi-Feedback download started - Assignment: $assignment_id, Teams: " . count($team_ids));

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
            $this->logger->error("Team Multi-Feedback download error: " . $e->getMessage());
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
            $status_checksums = $this->addStatusFiles($zip, $assignment, $teams, $temp_dir);
            $submission_checksums = $this->addTeamSubmissionsFromArrays($zip, $assignment, $teams);

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

            // Sammle ALLE Submissions von ALLEN Team-Mitgliedern
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
        $seen_files = [];

        foreach ($members as $member_data) {
            $user_id = $member_data['user_id'];
            $user_files = $this->getSubmittedFilesFromDB($assignment_id, $user_id);

            foreach ($user_files as $file_data) {
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

                if (!file_exists($file_path)) {
                    $this->logger->warning("File does not exist: $file_path");
                    continue;
                }

                if (!is_readable($file_path)) {
                    $this->logger->warning("File is not readable: $file_path");
                    continue;
                }

                $clean_filename = $this->removeILIASTimestampPrefix($file_name);
                $safe_filename = $this->toAscii($clean_filename);
                $zip_file_path = $user_folder . "/" . $safe_filename;

                if ($zip->addFile($file_path, $zip_file_path)) {
                    $files_added++;
                    $this->logger->debug("Successfully added team file: $safe_filename (uploaded by user $uploaded_by)");

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
     * Generiert den README-Inhalt für Team-Downloads
     */
    protected function generateReadmeContent(\ilExAssignment $assignment, array $teams): string
    {
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
               $this->generateEntityOverviewForReadme($teams) . "\n";
    }

    /**
     * README-Content ohne Plugin (Fallback)
     */
    protected function generateReadmeContentFallback(\ilExAssignment $assignment, array $teams): string
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
               "2. **Add feedback:** Place feedback files in the corresponding user folders.\n" .
               "3. **Re-upload:** Upload the complete ZIP again.\n\n";
    }

    /**
     * Team-Overview für README
     */
    protected function generateEntityOverviewForReadme(array $teams): string
    {
        $overview = "";
        foreach ($teams as $team_data) {
            $overview .= "### Team " . $team_data['team_id'] . "\n";

            if ($this->plugin) {
                $overview .= "- **" . $this->plugin->txt('readme_status') . ":** " . $team_data['status'] . "\n";
                $overview .= "- **" . $this->plugin->txt('readme_members') . ":** ";
            } else {
                $overview .= "- **Status:** " . $team_data['status'] . "\n";
                $overview .= "- **Members:** ";
            }

            $member_names = [];
            foreach ($team_data['members'] as $member) {
                $member_names[] = $member['fullname'] . " (" . $member['login'] . ")";
            }
            $overview .= implode(', ', $member_names) . "\n";

            if (!empty($team_data['mark'])) {
                if ($this->plugin) {
                    $overview .= "- **" . $this->plugin->txt('readme_note') . ":** " . $team_data['mark'] . "\n";
                } else {
                    $overview .= "- **Grade:** " . $team_data['mark'] . "\n";
                }
            }

            $overview .= "\n";
        }

        return $overview;
    }
}
?>
