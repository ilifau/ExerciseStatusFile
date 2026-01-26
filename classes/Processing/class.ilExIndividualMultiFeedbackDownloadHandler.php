<?php
declare(strict_types=1);

require_once __DIR__ . '/class.ilExMultiFeedbackDownloadHandlerBase.php';

/**
 * Individual Multi-Feedback Download Handler
 *
 * Verarbeitet Multi-User-Downloads für Individual-Assignments
 *
 * @author Cornel Musielak
 * @version 1.2.0
 */
class ilExIndividualMultiFeedbackDownloadHandler extends ilExMultiFeedbackDownloadHandlerBase
{
    private ilExUserDataProvider $user_provider;

    public function __construct()
    {
        parent::__construct();
        $this->user_provider = new ilExUserDataProvider();
    }

    protected function getEntityType(): string
    {
        return 'user';
    }

    protected function getTempDirectoryPrefix(): string
    {
        return 'plugin_individual_multi_feedback_';
    }

    /**
     * Individual Multi-Feedback-Download für ausgewählte User generieren
     */
    public function generateIndividualMultiFeedbackDownload(int $assignment_id, array $user_ids): void
    {
        try {
            $this->logger->info("Individual Multi-Feedback download started - Assignment: $assignment_id, Users: " . count($user_ids));

            $assignment = new \ilExAssignment($assignment_id);

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
            $status_checksums = $this->addStatusFiles($zip, $assignment, $users, $temp_dir);
            $submission_checksums = $this->addUserSubmissionsFromArrays($zip, $assignment, $users);

            $all_checksums = array_merge($status_checksums, $submission_checksums);
            $this->addChecksumsFile($zip, $all_checksums, $temp_dir);
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
     * @return array Checksums aller hinzugefügten Dateien
     */
    private function addUserSubmissionsFromArrays(\ZipArchive &$zip, \ilExAssignment $assignment, array $users_data): array
    {
        $base_name = $this->toAscii("Multi_Feedback_Individual_" . $assignment->getTitle() . "_" . $assignment->getId());
        $checksums = [];

        foreach ($users_data as $user_data) {
            $user_id = $user_data['user_id'];
            $user_folder = $base_name . "/" . $this->generateUserFolderName($user_data);

            $zip->addEmptyDir($user_folder);

            $user_checksums = $this->addUserSubmissionsToZip($zip, $user_folder, $assignment, $user_id);
            $checksums = array_merge($checksums, $user_checksums);
        }

        return $checksums;
    }

    /**
     * User-Submissions zu ZIP hinzufügen
     * @return array Checksums der hinzugefügten Dateien
     */
    private function addUserSubmissionsToZip(\ZipArchive &$zip, string $user_folder, \ilExAssignment $assignment, int $user_id): array
    {
        $checksums = [];

        try {
            $this->logger->debug("Adding submissions for user $user_id to folder: $user_folder");

            $submitted_files = $this->getSubmittedFilesFromDB($assignment->getId(), $user_id);

            if (empty($submitted_files)) {
                $this->logger->debug("User $user_id has no submitted files");
                return $checksums;
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

                $clean_filename = $this->removeILIASTimestampPrefix($file_name);
                $safe_filename = $this->toAscii($clean_filename);
                $zip_file_path = $user_folder . "/" . $safe_filename;

                if ($zip->addFile($file_path, $zip_file_path)) {
                    $files_added++;
                    $this->logger->debug("Successfully added file: $safe_filename to $zip_file_path");

                    $checksums[$zip_file_path] = [
                        'md5' => md5_file($file_path),
                        'size' => filesize($file_path),
                        'type' => 'submission'
                    ];
                } else {
                    $this->logger->error("Failed to add file to ZIP: $file_path -> $zip_file_path");
                }
            }

            $this->logger->debug("Added $files_added files for user $user_id");

        } catch (Exception $e) {
            $this->logger->error("Error adding submissions for user $user_id: " . $e->getMessage());
            $this->logger->error("Stack trace: " . $e->getTraceAsString());
        }

        return $checksums;
    }

    /**
     * Generiert den README-Inhalt für Individual-Downloads
     */
    protected function generateReadmeContent(\ilExAssignment $assignment, array $users): string
    {
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
               "├── checksums.json             # Checksums for submission integrity\n" .
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
               "## " . $this->plugin->txt('readme_modified_submission_section') . "\n\n" .
               $this->plugin->txt('readme_modified_submission_info') . "\n\n" .
               $this->plugin->txt('readme_modified_submission_recommendation') . "\n\n" .
               "## " . $this->plugin->txt('readme_user_overview') . "\n\n" .
               $this->generateEntityOverviewForReadme($users) . "\n";
    }

    /**
     * README-Content ohne Plugin (Fallback)
     */
    protected function generateReadmeContentFallback(\ilExAssignment $assignment, array $users): string
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
               "2. **Add feedback:** Place feedback files in the corresponding user folders.\n" .
               "3. **Re-upload:** Upload the complete ZIP again.\n\n";
    }

    /**
     * User-Overview für README
     */
    protected function generateEntityOverviewForReadme(array $users): string
    {
        $overview = "";
        foreach ($users as $user_data) {
            $overview .= "### " . $user_data['fullname'] . " (" . $user_data['login'] . ")\n";

            if ($this->plugin) {
                $overview .= "- **" . $this->plugin->txt('readme_status') . ":** " . $user_data['status'] . "\n";

                if (!empty($user_data['mark'])) {
                    $overview .= "- **" . $this->plugin->txt('readme_note') . ":** " . $user_data['mark'] . "\n";
                }

                $submission_text = $user_data['has_submission'] ? $this->plugin->txt('readme_yes') : $this->plugin->txt('readme_no');
                $overview .= "- **" . $this->plugin->txt('readme_submission') . ":** $submission_text\n";
            } else {
                $overview .= "- **Status:** " . $user_data['status'] . "\n";

                if (!empty($user_data['mark'])) {
                    $overview .= "- **Grade:** " . $user_data['mark'] . "\n";
                }

                $submission_text = $user_data['has_submission'] ? "Yes" : "No";
                $overview .= "- **Submission:** $submission_text\n";
            }

            $overview .= "\n";
        }

        return $overview;
    }
}
?>
