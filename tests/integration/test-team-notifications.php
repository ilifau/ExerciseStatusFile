<?php
declare(strict_types=1);

/**
 * Integration Test: Team Feedback E-Mail Notifications
 *
 * Tests email notification behavior when uploading feedback for teams:
 * 1. Creates a team assignment with multiple teams
 * 2. Uploads feedback for the teams
 * 3. Verifies that each team member receives exactly ONE notification
 * 4. Checks that duplicate notifications are prevented
 *
 * @author Integration Test Suite
 * @version 1.0.0
 */

// Bootstrap ILIAS
chdir('/var/www/StudOn');
require_once '/var/www/StudOn/libs/composer/vendor/autoload.php';
require_once '/var/www/StudOn/ilias.php';

// Load test helper
require_once __DIR__ . '/TestHelper.php';

class TeamNotificationTest
{
    private IntegrationTestHelper $helper;
    private ilLogger $logger;
    private array $test_results = [];
    private int $log_position = 0;
    private ?string $log_file = null;

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->helper = new IntegrationTestHelper();
        $this->log_file = $this->detectLogFile();
    }

    /**
     * Detect the ILIAS log file path from settings
     */
    private function detectLogFile(): ?string
    {
        global $DIC;

        // Try to get from ILIAS settings
        try {
            if (isset($DIC['ilSetting'])) {
                $log_path = $DIC['ilSetting']->get('log_path');
                $log_file = $DIC['ilSetting']->get('log_file');
                if ($log_path && $log_file) {
                    $full_path = rtrim($log_path, '/') . '/' . $log_file;
                    if (file_exists($full_path) && is_readable($full_path)) {
                        return $full_path;
                    }
                }
            }
        } catch (Exception $e) {
            // Ignore, try fallback
        }

        // Try environment variable
        $env_log = getenv('ILIAS_LOG_FILE');
        if ($env_log && file_exists($env_log) && is_readable($env_log)) {
            return $env_log;
        }

        return null;
    }

    public function runAllTests(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Team Feedback E-Mail Notification Test\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        // Show log file status
        if ($this->log_file) {
            echo "📁 Log file: {$this->log_file}\n\n";
        } else {
            echo "⚠️  Log file not detected - log-based verification will be skipped\n";
            echo "   Set ILIAS_LOG_FILE environment variable or check ILIAS log settings\n\n";
        }

        try {
            // Enable debug mode to capture notification attempts without sending real emails
            $this->testTeamNotificationDebugMode();
            $this->testMultipleTeamsNotification();
            $this->testDuplicatePreventionWithinRequest();

            $this->printResults();

        } catch (Exception $e) {
            echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
            echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
            $this->helper->cleanup();
            exit(1);
        }
    }

    /**
     * Test 1: Team notification in DEBUG mode
     * Verifies that all team members would be notified (logs only, no real emails)
     */
    private function testTeamNotificationDebugMode(): void
    {
        echo "📧 Test 1: Team Notification (Debug Mode)\n";
        echo "───────────────────────────────────────────────────────\n";

        // 1. Temporarily enable debug mode
        $original_debug_setting = $this->setDebugMode(true);

        try {
            // 2. Create exercise and team assignment
            $exercise = $this->helper->createTestExercise('_TeamNotify');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test1');

            // 3. Create 2 teams with 3 members each
            $users = $this->helper->createTestUsers(6);

            $team1_members = [$users[0]->getId(), $users[1]->getId(), $users[2]->getId()];
            $team2_members = [$users[3]->getId(), $users[4]->getId(), $users[5]->getId()];

            $team1 = $this->helper->createTestTeam($assignment, $team1_members, '_Team1');
            $team2 = $this->helper->createTestTeam($assignment, $team2_members, '_Team2');

            // 4. Create submissions for both teams
            $this->helper->createTestSubmission(
                $assignment,
                $team1_members[0],
                [['filename' => 'team1_report.txt', 'content' => 'Team 1 work']]
            );

            $this->helper->createTestSubmission(
                $assignment,
                $team2_members[0],
                [['filename' => 'team2_report.txt', 'content' => 'Team 2 work']]
            );

            echo "✅ Created teams and submissions\n";

            // 5. Download multi-feedback ZIP
            $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());

            // 6. Modify files (add feedback)
            $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
                'team1_report.txt' => "FEEDBACK: Great work!",
                'team2_report.txt' => "FEEDBACK: Good job!"
            ]);

            // 7. Mark log position before upload
            $this->markLogPosition();

            // 8. Upload modified ZIP (this triggers notifications)
            $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

            // 9. Get new log entries since upload
            $new_logs = $this->getNewLogEntries();

            echo "\n📋 Debug Log Analysis:\n";

            // 10. Analyze logs for notification entries
            $notification_entries = $this->analyzeNotificationLogs($new_logs);

            foreach ($notification_entries as $entry) {
                echo "  • {$entry}\n";
            }

            // 11. Check expectations
            $has_team1_notification = $this->logsContainTeamNotification($new_logs, $team1_members);
            $has_team2_notification = $this->logsContainTeamNotification($new_logs, $team2_members);

            $this->recordResult('Team 1 members would be notified', $has_team1_notification);
            $this->recordResult('Team 2 members would be notified', $has_team2_notification);

            // 12. Check for duplicate prevention
            $duplicate_warnings = $this->countDuplicateWarnings($new_logs);
            echo "\n  Duplicate prevention triggered: $duplicate_warnings times\n";

            $this->recordResult('No duplicates sent (same request)', $duplicate_warnings === 0);

            echo "\n✅ Test 1 completed\n\n";

        } finally {
            // Restore original debug setting
            $this->setDebugMode($original_debug_setting);
        }
    }

    /**
     * Test 2: Multiple teams get notifications independently
     */
    private function testMultipleTeamsNotification(): void
    {
        echo "👥 Test 2: Multiple Teams Get Independent Notifications\n";
        echo "───────────────────────────────────────────────────────\n";

        $original_debug_setting = $this->setDebugMode(true);

        try {
            // Create exercise with multiple teams
            $exercise = $this->helper->createTestExercise('_MultiTeam');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test2');

            // Create 3 teams with different sizes
            $users = $this->helper->createTestUsers(8);

            $team1_members = [$users[0]->getId(), $users[1]->getId()]; // 2 members
            $team2_members = [$users[2]->getId(), $users[3]->getId(), $users[4]->getId()]; // 3 members
            $team3_members = [$users[5]->getId(), $users[6]->getId(), $users[7]->getId()]; // 3 members

            $this->helper->createTestTeam($assignment, $team1_members, '_TeamA');
            $this->helper->createTestTeam($assignment, $team2_members, '_TeamB');
            $this->helper->createTestTeam($assignment, $team3_members, '_TeamC');

            // Create submissions
            foreach ([$team1_members, $team2_members, $team3_members] as $team) {
                $this->helper->createTestSubmission(
                    $assignment,
                    $team[0],
                    [['filename' => 'report.txt', 'content' => 'Team submission']]
                );
            }

            echo "✅ Created 3 teams with different sizes (2, 3, 3 members)\n";

            // Download and upload feedback
            $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());
            $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
                'report.txt' => "Feedback content"
            ]);

            $this->markLogPosition();
            $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);
            $new_logs = $this->getNewLogEntries();

            // Verify each team's members would be notified
            $team1_notified = $this->logsContainTeamNotification($new_logs, $team1_members);
            $team2_notified = $this->logsContainTeamNotification($new_logs, $team2_members);
            $team3_notified = $this->logsContainTeamNotification($new_logs, $team3_members);

            echo "  Team 1 (2 members): " . ($team1_notified ? "✅ Would notify" : "❌ Missing") . "\n";
            echo "  Team 2 (3 members): " . ($team2_notified ? "✅ Would notify" : "❌ Missing") . "\n";
            echo "  Team 3 (3 members): " . ($team3_notified ? "✅ Would notify" : "❌ Missing") . "\n";

            $this->recordResult('All teams would receive notifications',
                $team1_notified && $team2_notified && $team3_notified);

            echo "\n✅ Test 2 completed\n\n";

        } finally {
            $this->setDebugMode($original_debug_setting);
        }
    }

    /**
     * Test 3: Duplicate prevention within the same request
     */
    private function testDuplicatePreventionWithinRequest(): void
    {
        echo "🔒 Test 3: Duplicate Prevention (Same Request)\n";
        echo "───────────────────────────────────────────────────────\n";

        $original_debug_setting = $this->setDebugMode(true);

        try {
            // Create scenario where duplicate prevention is critical
            $exercise = $this->helper->createTestExercise('_DupePrev');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test3');

            $users = $this->helper->createTestUsers(3);
            $team_members = [$users[0]->getId(), $users[1]->getId(), $users[2]->getId()];

            $this->helper->createTestTeam($assignment, $team_members, '_TeamDupe');
            $this->helper->createTestSubmission(
                $assignment,
                $team_members[0],
                [['filename' => 'work.txt', 'content' => 'Original work']]
            );

            echo "✅ Created team with 3 members: " . implode(', ', $team_members) . "\n";

            // Mark log position before upload
            $this->markLogPosition();

            // Upload feedback
            $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());
            $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
                'work.txt' => "Modified feedback"
            ]);

            $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

            // Read new log entries since upload
            $new_logs = $this->getNewLogEntries();

            // Count notifications per user from logs
            // Pattern: "DEBUG: Would notify X user(s): user_id1, user_id2, ..."
            $notification_counts = $this->countNotificationsFromLogs($new_logs, $team_members);

            echo "\n📊 Notification count per user (from logs):\n";
            foreach ($team_members as $user_id) {
                $count = $notification_counts[$user_id] ?? 0;
                $status = $count === 1 ? "✅" : "❌";
                echo "  $status User $user_id: $count notification(s)\n";
            }

            // Each user should appear exactly once
            $all_exactly_once = true;
            foreach ($team_members as $user_id) {
                if (($notification_counts[$user_id] ?? 0) !== 1) {
                    $all_exactly_once = false;
                    break;
                }
            }

            $this->recordResult('Each team member notified exactly once', $all_exactly_once);

            echo "\n✅ Test 3 completed\n\n";

        } finally {
            $this->setDebugMode($original_debug_setting);
        }
    }

    // Helper methods

    private function setDebugMode(bool $enabled): bool
    {
        // Read current plugin file
        $plugin_file = '/var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/classes/class.ilExerciseStatusFilePlugin.php';
        $content = file_get_contents($plugin_file);

        // Extract current setting
        preg_match('/const DEBUG_EMAIL_NOTIFICATIONS = (true|false);/', $content, $matches);
        $current_setting = isset($matches[1]) && $matches[1] === 'true';

        // Update setting
        $new_value = $enabled ? 'true' : 'false';
        $new_content = preg_replace(
            '/const DEBUG_EMAIL_NOTIFICATIONS = (true|false);/',
            "const DEBUG_EMAIL_NOTIFICATIONS = $new_value;",
            $content
        );
        file_put_contents($plugin_file, $new_content);

        // Clear opcode cache to ensure change takes effect
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        return $current_setting;
    }

    /**
     * Mark current log file position
     */
    private function markLogPosition(): void
    {
        if ($this->log_file && file_exists($this->log_file)) {
            $this->log_position = filesize($this->log_file);
        } else {
            $this->log_position = 0;
        }
    }

    /**
     * Get log entries added since last markLogPosition() call
     */
    private function getNewLogEntries(): array
    {
        if (!$this->log_file || !file_exists($this->log_file)) {
            echo "  ⚠️  Log file not available, skipping log analysis\n";
            return [];
        }

        $handle = fopen($this->log_file, 'r');
        if (!$handle) {
            return [];
        }

        fseek($handle, $this->log_position);
        $content = fread($handle, filesize($this->log_file) - $this->log_position);
        fclose($handle);

        if (empty($content)) {
            return [];
        }

        return explode("\n", trim($content));
    }

    /**
     * Count notifications per user from log entries
     * Parses "DEBUG: Would notify X user(s): user_id1, user_id2, ..." entries
     */
    private function countNotificationsFromLogs(array $logs, array $user_ids): array
    {
        $counts = array_fill_keys($user_ids, 0);

        foreach ($logs as $log) {
            // Match DEBUG mode log: "DEBUG: Would notify X user(s): 123, 456, 789"
            if (preg_match('/DEBUG: Would notify \d+ user\(s\): ([\d, ]+)/', $log, $matches)) {
                $notified_ids = array_map('intval', explode(', ', $matches[1]));
                foreach ($notified_ids as $notified_id) {
                    if (isset($counts[$notified_id])) {
                        $counts[$notified_id]++;
                    }
                }
            }
            // Match production mode log: "Sent X feedback notification(s) for assignment"
            // This doesn't list individual users, so we check Mail system task logs
            if (preg_match('/New mail system task: To: (\w+)/', $log, $matches)) {
                // This captures username, not user_id - would need additional lookup
                // For now, focus on DEBUG mode which logs user IDs
            }
        }

        return $counts;
    }

    /**
     * Filter logs for notification-related entries
     */
    private function analyzeNotificationLogs(array $logs): array
    {
        $entries = [];
        foreach ($logs as $log) {
            if (stripos($log, 'notification') !== false ||
                stripos($log, 'DEBUG MODE') !== false ||
                stripos($log, 'Would notify') !== false ||
                stripos($log, 'mail system task') !== false) {
                $entries[] = $log;
            }
        }
        return $entries;
    }

    private function logsContainTeamNotification(array $logs, array $member_ids): bool
    {
        $counts = $this->countNotificationsFromLogs($logs, $member_ids);
        foreach ($member_ids as $member_id) {
            if (($counts[$member_id] ?? 0) > 0) {
                return true;
            }
        }
        return false;
    }

    private function countDuplicateWarnings(array $logs): int
    {
        $count = 0;
        foreach ($logs as $log) {
            if (stripos($log, 'duplicate') !== false || stripos($log, 'skipped') !== false) {
                $count++;
            }
        }
        return $count;
    }

    private function recordResult(string $test_name, bool $passed): void
    {
        $this->test_results[] = [
            'name' => $test_name,
            'passed' => $passed
        ];
    }

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

        echo "\n💡 Note: This test runs in DEBUG mode (no real emails sent)\n";
        echo "   Real email behavior depends on ILIAS notification settings\n";
        echo "   and user preferences.\n";

        if ($failed > 0) {
            echo "\n⚠️  Some tests failed. Check implementation.\n";
            exit(1);
        } else {
            echo "\n🎉 All tests passed!\n";
        }
    }
}

// Run tests
try {
    $test = new TeamNotificationTest();
    $test->runAllTests();
} catch (Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
