<?php
declare(strict_types=1);

/**
 * Integration Test: Multi-Feedback Upload Workflow
 *
 * Tests the complete workflow:
 * 1. Create exercise with assignments
 * 2. Create users and teams
 * 3. Create submissions
 * 4. Download multi-feedback ZIP
 * 5. Modify files (simulate tutor edits)
 * 6. Upload modified ZIP
 * 7. Verify results (checksums, renames, etc.)
 * 8. Test status file detection (xlsx vs csv)
 * 9. Test user warnings system
 *
 * @author Integration Test Suite
 * @version 1.1.0 (2026-01-16: Added status file and warning tests)
 */

// Bootstrap ILIAS
chdir('/var/www/StudOn');
require_once '/var/www/StudOn/libs/composer/vendor/autoload.php';
require_once '/var/www/StudOn/ilias.php';

// Load test helper
require_once __DIR__ . '/TestHelper.php';

class MultiFeedbackUploadWorkflowTest
{
    private IntegrationTestHelper $helper;
    private ilLogger $logger;
    private array $test_results = [];

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->helper = new IntegrationTestHelper();
    }

    public function runAllTests(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Multi-Feedback Upload Workflow - Integration Test\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        try {
            $this->testIndividualAssignmentWorkflow();
            $this->testTeamAssignmentWorkflow();
            $this->testModifiedFileRename();
            $this->testChecksumValidation();
            $this->testStatusFileChecksums();
            $this->testStatusFileDetection();
            $this->testWarningsInResponse();

            $this->printResults();

        } catch (Exception $e) {
            echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            $this->helper->cleanup();
            exit(1);
        }
    }

    /**
     * Test 1: Individual Assignment Workflow
     */
    private function testIndividualAssignmentWorkflow(): void
    {
        echo "📝 Test 1: Individual Assignment Workflow\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create exercise and assignment
        $exercise = $this->helper->createTestExercise('_Individual');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test1');

        // 2. Create test users
        $users = $this->helper->createTestUsers(3);

        // 3. Create submissions
        foreach ($users as $user) {
            $this->helper->createTestSubmission(
                $assignment,
                $user->getId(),
                [
                    [
                        'filename' => 'solution.txt',
                        'content' => "Student solution by {$user->getLogin()}\nLine 2\nLine 3"
                    ],
                    [
                        'filename' => 'notes.md',
                        'content' => "# Notes\n\nBy {$user->getLogin()}"
                    ]
                ]
            );
        }

        echo "✅ Created exercise, assignment, 3 users, and submissions\n";

        // 4. Download multi-feedback ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());
        $this->recordResult('Individual: Download ZIP', file_exists($zip_path));

        // 5. Modify files in ZIP (simulate tutor corrections)
        $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
            'solution.txt' => "CORRECTED: This is the tutor's feedback\nGood work!",
            'notes.md' => "# Tutor Feedback\n\nExcellent notes!"
        ]);
        $this->recordResult('Individual: Modify ZIP', file_exists($modified_zip));

        // 6. Upload modified ZIP
        $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);
        $this->recordResult('Individual: Upload ZIP', isset($upload_result['success']));

        // 7. Verify files were renamed (should have _korrigiert suffix)
        $renamed_count = 0;
        foreach ($users as $user) {
            if ($this->helper->verifyFileRenamed($assignment->getId(), $user->getId(), 'solution.txt')) {
                $renamed_count++;
            }
        }
        $this->recordResult('Individual: Files renamed with _korrigiert', $renamed_count === 3);

        echo "✅ Test 1 completed: $renamed_count/3 files renamed correctly\n\n";
    }

    /**
     * Test 2: Team Assignment Workflow
     */
    private function testTeamAssignmentWorkflow(): void
    {
        echo "👥 Test 2: Team Assignment Workflow\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create exercise and team assignment
        $exercise = $this->helper->createTestExercise('_Team');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test2');

        // 2. Create test users
        $users = $this->helper->createTestUsers(6);

        // 3. Create 2 teams with 3 members each
        $team1_members = [$users[0]->getId(), $users[1]->getId(), $users[2]->getId()];
        $team2_members = [$users[3]->getId(), $users[4]->getId(), $users[5]->getId()];

        $team1 = $this->helper->createTestTeam($assignment, $team1_members, '_Team1');
        $team2 = $this->helper->createTestTeam($assignment, $team2_members, '_Team2');

        // 4. Create team submissions
        $this->helper->createTestSubmission(
            $assignment,
            $team1_members[0], // Submit as first team member
            [
                [
                    'filename' => 'team_report.pdf',
                    'content' => "Team 1 Report Content (Binary placeholder)"
                ],
                [
                    'filename' => 'code.php',
                    'content' => "<?php\n// Team 1 code\necho 'Hello';\n"
                ]
            ]
        );

        $this->helper->createTestSubmission(
            $assignment,
            $team2_members[0],
            [
                [
                    'filename' => 'team_report.pdf',
                    'content' => "Team 2 Report Content (Binary placeholder)"
                ]
            ]
        );

        echo "✅ Created exercise, team assignment, 6 users, 2 teams, and submissions\n";

        // 5. Download multi-feedback ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());
        $this->recordResult('Team: Download ZIP', file_exists($zip_path));

        // 6. Modify files
        $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
            'team_report.pdf' => "CORRECTED REPORT: Team feedback from tutor",
            'code.php' => "<?php\n// CORRECTED\necho 'Hello World!';\n"
        ]);
        $this->recordResult('Team: Modify ZIP', file_exists($modified_zip));

        // 7. Upload modified ZIP
        $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);
        $this->recordResult('Team: Upload ZIP', isset($upload_result['success']));

        // 8. Verify files renamed for team submissions
        $team1_renamed = $this->helper->verifyFileRenamed($assignment->getId(), $team1_members[0], 'team_report.pdf');
        $team2_renamed = $this->helper->verifyFileRenamed($assignment->getId(), $team2_members[0], 'team_report.pdf');

        $this->recordResult('Team: Files renamed for both teams', $team1_renamed && $team2_renamed);

        echo "✅ Test 2 completed: Both team files renamed correctly\n\n";
    }

    /**
     * Test 3: Modified File Rename (_korrigiert suffix)
     */
    private function testModifiedFileRename(): void
    {
        echo "🏷️  Test 3: Modified File Rename Detection\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create simple test case
        $exercise = $this->helper->createTestExercise('_Rename');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test3');

        $users = $this->helper->createTestUsers(1);
        $user = $users[0];

        // 2. Create submission with specific content
        $original_content = "Original student content\nLine 2\nLine 3";
        $this->helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [
                [
                    'filename' => 'essay.txt',
                    'content' => $original_content
                ]
            ]
        );

        // 3. Download ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

        // 4. Modify with DIFFERENT content (should trigger rename)
        $modified_content = "CORRECTED by tutor\nCompletely different content";
        $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
            'essay.txt' => $modified_content
        ]);

        // 5. Upload
        $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

        // 6. Verify rename occurred
        $renamed = $this->helper->verifyFileRenamed($assignment->getId(), $user->getId(), 'essay.txt');
        $this->recordResult('Rename: Modified file gets _korrigiert suffix', $renamed);

        if ($renamed) {
            echo "✅ File correctly renamed: essay.txt → essay_korrigiert.txt\n";
        } else {
            echo "❌ File NOT renamed (checksum detection may have failed)\n";
        }

        echo "\n";
    }

    /**
     * Test 4: Checksum Validation (unmodified files should NOT be renamed)
     */
    private function testChecksumValidation(): void
    {
        echo "🔐 Test 4: Checksum Validation (Unchanged Files)\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create test case
        $exercise = $this->helper->createTestExercise('_Checksum');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4');

        $users = $this->helper->createTestUsers(1);
        $user = $users[0];

        // 2. Create submission
        $original_content = "Unchanged content\nThis will not be modified";
        $this->helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [
                [
                    'filename' => 'unchanged.txt',
                    'content' => $original_content
                ]
            ]
        );

        // 3. Download ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

        // 4. Do NOT modify the file - upload as-is
        $this->helper->uploadMultiFeedbackZip($assignment->getId(), $zip_path);

        // 5. Verify file was NOT renamed (checksum should match)
        $submission = new ilExSubmission(new ilExAssignment($assignment->getId()), $user->getId());
        $files = $submission->getFiles();

        $has_unchanged_name = false;
        foreach ($files as $file) {
            if ($file['name'] === 'unchanged.txt') {
                $has_unchanged_name = true;
                break;
            }
        }

        $this->recordResult('Checksum: Unchanged file keeps original name', $has_unchanged_name);

        if ($has_unchanged_name) {
            echo "✅ Unchanged file correctly kept original name (checksum matched)\n";
        } else {
            echo "❌ File was renamed even though it wasn't modified\n";
        }

        echo "\n";
    }

    /**
     * Test 5: Status File Checksums in ZIP
     * Verifies that status.xlsx and status.csv have checksums in checksums.json
     */
    private function testStatusFileChecksums(): void
    {
        echo "📋 Test 5: Status File Checksums\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create simple test case
        $exercise = $this->helper->createTestExercise('_StatusChecksum');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test5');

        $users = $this->helper->createTestUsers(1);
        $user = $users[0];

        // 2. Create submission
        $this->helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [['filename' => 'test.txt', 'content' => 'Test content']]
        );

        // 3. Download ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

        // 4. Extract and check checksums.json
        $zip = new ZipArchive();
        $zip->open($zip_path);

        $checksums_content = $zip->getFromName('checksums.json');
        $zip->close();

        $checksums = json_decode($checksums_content, true);

        // 5. Verify status file checksums exist
        $has_xlsx_checksum = isset($checksums['status.xlsx']) &&
                             isset($checksums['status.xlsx']['sha256']) &&
                             isset($checksums['status.xlsx']['type']) &&
                             $checksums['status.xlsx']['type'] === 'status_file';

        $has_csv_checksum = isset($checksums['status.csv']) &&
                            isset($checksums['status.csv']['sha256']) &&
                            isset($checksums['status.csv']['type']) &&
                            $checksums['status.csv']['type'] === 'status_file';

        $this->recordResult('StatusChecksum: status.xlsx has checksum with sha256', $has_xlsx_checksum);
        $this->recordResult('StatusChecksum: status.csv has checksum with sha256', $has_csv_checksum);

        if ($has_xlsx_checksum && $has_csv_checksum) {
            echo "✅ Both status files have checksums in checksums.json\n";
        } else {
            echo "❌ Missing status file checksums\n";
        }

        echo "\n";
    }

    /**
     * Test 6: Status File Detection (xlsx vs csv)
     * Verifies that the system correctly detects which status file was modified
     */
    private function testStatusFileDetection(): void
    {
        echo "🔍 Test 6: Status File Detection (xlsx vs csv)\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create test case
        $exercise = $this->helper->createTestExercise('_StatusDetect');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test6');

        $users = $this->helper->createTestUsers(1);
        $user = $users[0];

        // 2. Create submission
        $this->helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [['filename' => 'solution.txt', 'content' => 'Student solution']]
        );

        // 3. Download ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

        // 4. Modify ONLY status.csv (not xlsx)
        $modified_zip = $this->helper->modifyStatusFileInZip($zip_path, 'csv', [
            ['user_id' => $user->getId(), 'update' => 1, 'status' => 'passed']
        ]);

        // 5. Upload and check result
        $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

        // 6. Verify that CSV was used (status should be updated)
        $member_status = ilExerciseMembers::_lookupStatus($assignment->getExerciseId(), $user->getId());

        // Note: This test verifies the status was updated, indicating CSV was correctly detected
        $csv_was_used = ($member_status === 'passed');

        $this->recordResult('StatusDetection: CSV correctly detected as modified', $csv_was_used);

        if ($csv_was_used) {
            echo "✅ System correctly detected CSV as the modified status file\n";
        } else {
            echo "❌ Status not updated - CSV may not have been detected correctly\n";
        }

        echo "\n";
    }

    /**
     * Test 7: Warnings in Upload Response
     * Verifies that warnings are returned in the JSON response
     */
    private function testWarningsInResponse(): void
    {
        echo "⚠️  Test 7: Warnings in Upload Response\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Create test case
        $exercise = $this->helper->createTestExercise('_Warnings');
        $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test7');

        $users = $this->helper->createTestUsers(1);
        $user = $users[0];

        // 2. Create submission
        $this->helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [['filename' => 'work.txt', 'content' => 'Student work']]
        );

        // 3. Download ZIP
        $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

        // 4. Upload WITHOUT any modifications (should trigger warning)
        $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $zip_path);

        // 5. Check for warnings in response
        $has_warnings = isset($upload_result['warnings']) && !empty($upload_result['warnings']);

        $this->recordResult('Warnings: Unmodified upload returns warnings', $has_warnings);

        if ($has_warnings) {
            echo "✅ Warnings returned in response:\n";
            foreach ($upload_result['warnings'] as $warning) {
                echo "   - $warning\n";
            }
        } else {
            echo "❌ No warnings in response (expected warning for unmodified files)\n";
        }

        // 6. Test warning for "no updates" (all update=0)
        $modified_zip = $this->helper->modifyStatusFileInZip($zip_path, 'csv', [
            ['user_id' => $user->getId(), 'update' => 0, 'status' => 'passed']
        ]);

        $upload_result2 = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

        $has_no_updates_warning = false;
        if (isset($upload_result2['warnings'])) {
            foreach ($upload_result2['warnings'] as $warning) {
                if (strpos($warning, 'update') !== false || strpos($warning, 'Updates') !== false) {
                    $has_no_updates_warning = true;
                    break;
                }
            }
        }

        $this->recordResult('Warnings: No-updates warning when all update=0', $has_no_updates_warning);

        if ($has_no_updates_warning) {
            echo "✅ Warning for 'no updates found' correctly returned\n";
        } else {
            echo "❌ Missing warning for 'no updates found'\n";
        }

        echo "\n";
    }

    /**
     * Record test result
     */
    private function recordResult(string $test_name, bool $passed): void
    {
        $this->test_results[] = [
            'name' => $test_name,
            'passed' => $passed
        ];
    }

    /**
     * Print final test results
     */
    private function printResults(): void
    {
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Test Results\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        $passed = 0;
        $failed = 0;

        foreach ($this->test_results as $result) {
            $icon = $result['passed'] ? '✅' : '❌';
            $status = $result['passed'] ? 'PASS' : 'FAIL';

            echo "$icon $status: {$result['name']}\n";

            if ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        echo "\n───────────────────────────────────────────────────────\n";
        echo "Results:\n";
        echo "  ✅ Passed:   $passed\n";
        echo "  ❌ Failed:   $failed\n";
        echo "───────────────────────────────────────────────────────\n";

        if ($failed > 0) {
            echo "\n⚠️  Some tests failed. Check logs for details.\n";
            exit(1);
        } else {
            echo "\n🎉 All tests passed!\n";
        }
    }
}

// Run tests
try {
    $test = new MultiFeedbackUploadWorkflowTest();
    $test->runAllTests();
} catch (Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
