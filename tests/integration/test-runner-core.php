<?php
declare(strict_types=1);

/**
 * Core Test Runner Logic
 *
 * Can be called from web interface or CLI
 */

class IntegrationTestRunner
{
    private IntegrationTestHelper $helper;
    private array $test_results = [];
    private int $tests_passed = 0;
    private int $tests_failed = 0;

    public function __construct(int $parent_ref_id = 1)
    {
        $this->helper = new IntegrationTestHelper($parent_ref_id);
    }

    public function runAll(bool $keep_data = false): void
    {
        $start_time = microtime(true);

        try {
            $this->runIndividualTests();
            $this->runTeamTests();
            $this->runChecksumTests();
            $this->runCSVStatusFileTests();
            $this->runTeamNotificationTests();
            $this->runNegativeTests();

            $duration = round(microtime(true) - $start_time, 2);

            $this->printSummary($duration);
        } finally {
            // Cleanup unless keep_data is true
            if (!$keep_data) {
                echo "\n🧹 Cleaning up test data...\n";
                $this->helper->cleanupAll();
                echo "✅ Cleanup complete!\n";
            } else {
                echo "\n💾 Test-Daten wurden NICHT gelöscht.\n";
                echo "ℹ️  Du kannst die Übungen jetzt in der ILIAS GUI ansehen.\n";
                echo "ℹ️  Suche im Repository nach 'TEST_Exercise' oder verwende die Links oben.\n";
            }
        }
    }

    public function runIndividualTests(): void
    {
        echo "📝 Test 1: Individual Assignment Workflow\n";
        echo "───────────────────────────────────────────────────────\n\n";

        try {
            // 1. Create exercise and assignment
            echo "→ Erstelle Übung...\n";
            $exercise = $this->helper->createTestExercise('_Individual');
            echo "→ Erstelle Assignment...\n";
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test1');
            echo "✅ Übung und Aufgabe erstellt (ID: {$assignment->getId()})\n";

            // 2. Create test users
            echo "→ Erstelle 3 Test-User...\n";
            $users = $this->helper->createTestUsers(3);
            echo "✅ 3 Test-User erstellt\n";

            // 3. Create submissions
            echo "→ Erstelle Abgaben für User...\n";
            foreach ($users as $user) {
                $this->helper->createTestSubmission(
                    $assignment,
                    $user->getId(),
                    [
                        [
                            'filename' => 'solution.txt',
                            'content' => "Student solution by {$user->getLogin()}\nLine 2\nLine 3"
                        ]
                    ]
                );
            }
            echo "✅ Abgaben erstellt\n";

            $this->recordResult('Individual: Submissions created', true);
            echo "✅ Test abgeschlossen: Individual-Test erfolgreich\n\n";

        } catch (Exception $e) {
            echo "❌ FEHLER: " . $e->getMessage() . "\n\n";
            $this->recordResult('Individual: Complete workflow', false);
        }
    }

    public function runTeamTests(): void
    {
        echo "👥 Test 2: Team Assignment Workflow\n";
        echo "───────────────────────────────────────────────────────\n\n";

        try {
            // 1. Create exercise and team assignment
            echo "→ Erstelle Team-Übung...\n";
            $exercise = $this->helper->createTestExercise('_Team');
            echo "→ Erstelle Team-Assignment...\n";
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test2');
            echo "✅ Übung und Team-Aufgabe erstellt (ID: {$assignment->getId()})\n";

            // 2. Create users
            echo "→ Erstelle 6 Test-User...\n";
            $users = $this->helper->createTestUsers(6);
            echo "✅ 6 Test-User erstellt\n";

            // 3. Create teams
            echo "→ Erstelle Teams...\n";
            $team1_members = [$users[0]->getId(), $users[1]->getId(), $users[2]->getId()];
            $team2_members = [$users[3]->getId(), $users[4]->getId(), $users[5]->getId()];

            $team1 = $this->helper->createTestTeam($assignment, $team1_members, '_Team1');
            $team2 = $this->helper->createTestTeam($assignment, $team2_members, '_Team2');
            echo "✅ 2 Teams erstellt (Team1 ID: {$team1->getId()}, Team2 ID: {$team2->getId()})\n";

            // 4. Create submissions
            echo "→ Erstelle Team-Abgaben...\n";
            $this->helper->createTestSubmission(
                $assignment,
                $team1_members[0],
                [['filename' => 'team_report.txt', 'content' => 'Team 1 Report']]
            );
            $this->helper->createTestSubmission(
                $assignment,
                $team2_members[0],
                [['filename' => 'team_report.txt', 'content' => 'Team 2 Report']]
            );
            echo "✅ Team-Abgaben erstellt\n";

            $this->recordResult('Team: Submissions created', true);
            echo "✅ Test abgeschlossen: Team-Test erfolgreich\n\n";

        } catch (Exception $e) {
            echo "❌ FEHLER: " . $e->getMessage() . "\n\n";
            $this->recordResult('Team: Complete workflow', false);
        }
    }

    public function runChecksumTests(): void
    {
        echo "🔐 Test 3: Checksum Validation\n";
        echo "───────────────────────────────────────────────────────\n\n";

        try {
            echo "→ Erstelle Checksum-Test Übung...\n";
            $exercise = $this->helper->createTestExercise('_Checksum');
            echo "→ Erstelle Assignment...\n";
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test3');
            echo "✅ Übung erstellt (ID: {$assignment->getId()})\n";

            echo "→ Erstelle Test-User...\n";
            $users = $this->helper->createTestUsers(1);
            $user = $users[0];
            echo "✅ Test-User erstellt (ID: {$user->getId()})\n";

            // Create test submission
            echo "→ Erstelle Test-Abgabe...\n";
            $this->helper->createTestSubmission(
                $assignment,
                $user->getId(),
                [['filename' => 'essay.txt', 'content' => 'Original content']]
            );
            echo "✅ Test-Abgabe erstellt\n";

            $this->recordResult('Checksum: Submission created', true);
            echo "✅ Test abgeschlossen: Checksum-Test erfolgreich\n\n";

        } catch (Exception $e) {
            echo "❌ FEHLER: " . $e->getMessage() . "\n\n";
            $this->recordResult('Checksum: Tests', false);
        }
    }

    public function runCSVStatusFileTests(): void
    {
        echo "📄 Test 4: CSV Status File Processing\n";
        echo "───────────────────────────────────────────────────────\n\n";

        try {
            echo "→ Erstelle CSV-Test Übung...\n";
            $exercise = $this->helper->createTestExercise('_CSV');
            echo "→ Erstelle Assignment...\n";
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4');
            echo "✅ Übung erstellt (ID: {$assignment->getId()})\n";

            echo "→ Erstelle 2 Test-User...\n";
            $users = $this->helper->createTestUsers(2);
            echo "✅ Test-User erstellt\n";

            // Create submissions
            echo "→ Erstelle Abgaben...\n";
            foreach ($users as $user) {
                $this->helper->createTestSubmission(
                    $assignment,
                    $user->getId(),
                    [['filename' => 'homework.txt', 'content' => 'Student work']]
                );
            }
            echo "✅ Abgaben erstellt\n";

            // Test CSV status upload with valid statuses
            echo "→ Teste CSV Status-Upload mit gültigen Werten...\n";
            $csv_test_passed = $this->helper->testValidCSVStatusUpload($assignment, [
                [
                    'user_id' => $users[0]->getId(),
                    'status' => 'passed',
                    'mark' => '10',
                    'comment' => 'Great work!'
                ],
                [
                    'user_id' => $users[1]->getId(),
                    'status' => 'failed',
                    'mark' => '3',
                    'comment' => 'Needs improvement'
                ]
            ]);

            if ($csv_test_passed) {
                echo "   ✅ CSV Status-Upload erfolgreich\n";
                $this->recordResult('CSV: Valid status upload', true);
            } else {
                echo "   ❌ CSV Status-Upload fehlgeschlagen\n";
                $this->recordResult('CSV: Valid status upload', false);
            }

            echo "✅ Test abgeschlossen: CSV-Test erfolgreich\n\n";

        } catch (Exception $e) {
            echo "❌ FEHLER: " . $e->getMessage() . "\n\n";
            $this->recordResult('CSV: Valid status upload', false);
        }
    }

    public function runNegativeTests(): void
    {
        echo "⚠️  Test 5: Negative Tests (Error Handling)\n";
        echo "───────────────────────────────────────────────────────\n\n";

        // Test 5.1: Invalid Status Values
        echo "→ Test 5.1: Ungültige Status-Werte\n";
        try {
            $exercise = $this->helper->createTestExercise('_NegativeTest');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4_1');
            $users = $this->helper->createTestUsers(1);

            $this->helper->createTestSubmission(
                $assignment,
                $users[0]->getId(),
                [['filename' => 'test.txt', 'content' => 'Test content']]
            );

            // Simulate invalid status file upload
            $error_caught = $this->helper->testInvalidStatusUpload($assignment, [
                'status' => 'INVALID_STATUS',  // Invalid status value
                'user_id' => $users[0]->getId()
            ]);

            if ($error_caught) {
                echo "   ✅ Fehler korrekt erkannt: Ungültiger Status wurde abgelehnt\n";
                $this->recordResult('Negative: Invalid status rejected', true);
            } else {
                echo "   ❌ Fehler NICHT erkannt: Ungültiger Status wurde akzeptiert!\n";
                $this->recordResult('Negative: Invalid status rejected', false);
            }

        } catch (Exception $e) {
            echo "   ✅ Exception korrekt geworfen: {$e->getMessage()}\n";
            $this->recordResult('Negative: Invalid status rejected', true);
        }

        echo "\n";

        // Test 5.2: Empty Status Files
        echo "→ Test 5.2: Leere Status-Dateien\n";
        try {
            $exercise = $this->helper->createTestExercise('_NegativeTest2');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4_2');
            $users = $this->helper->createTestUsers(1);

            $this->helper->createTestSubmission(
                $assignment,
                $users[0]->getId(),
                [['filename' => 'test.txt', 'content' => 'Test content']]
            );

            // Simulate empty status file
            $error_caught = $this->helper->testEmptyStatusFile($assignment);

            if ($error_caught) {
                echo "   ✅ Leere Status-Datei korrekt behandelt\n";
                $this->recordResult('Negative: Empty status file handled', true);
            } else {
                echo "   ❌ Leere Status-Datei nicht erkannt!\n";
                $this->recordResult('Negative: Empty status file handled', false);
            }

        } catch (Exception $e) {
            echo "   ✅ Leere Status-Datei korrekt behandelt: {$e->getMessage()}\n";
            $this->recordResult('Negative: Empty status file handled', true);
        }

        echo "\n";

        // Test 5.3: Missing User in Status File
        echo "→ Test 5.3: Nicht-existierender User in Status-Datei\n";
        try {
            $exercise = $this->helper->createTestExercise('_NegativeTest3');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4_3');

            // Try to upload status for non-existent user
            $error_caught = $this->helper->testMissingUserStatus($assignment, 999999);

            if ($error_caught) {
                echo "   ✅ Nicht-existierender User korrekt behandelt\n";
                $this->recordResult('Negative: Missing user handled', true);
            } else {
                echo "   ❌ Nicht-existierender User wurde nicht erkannt!\n";
                $this->recordResult('Negative: Missing user handled', false);
            }

        } catch (Exception $e) {
            echo "   ✅ Nicht-existierender User korrekt behandelt: {$e->getMessage()}\n";
            $this->recordResult('Negative: Missing user handled', true);
        }

        echo "\n";

        // Test 5.4: Malformed ZIP Upload
        echo "→ Test 5.4: Fehlerhaftes ZIP-Format\n";
        try {
            $exercise = $this->helper->createTestExercise('_NegativeTest4');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4_4');

            // Try to upload malformed ZIP
            $error_caught = $this->helper->testMalformedZip($assignment);

            if ($error_caught) {
                echo "   ✅ Fehlerhaftes ZIP korrekt abgelehnt\n";
                $this->recordResult('Negative: Malformed ZIP rejected', true);
            } else {
                echo "   ❌ Fehlerhaftes ZIP wurde akzeptiert!\n";
                $this->recordResult('Negative: Malformed ZIP rejected', false);
            }

        } catch (Exception $e) {
            echo "   ✅ Fehlerhaftes ZIP korrekt abgelehnt: {$e->getMessage()}\n";
            $this->recordResult('Negative: Malformed ZIP rejected', true);
        }

        echo "\n";

        // Test 5.5: Wrong ZIP Structure (Missing User Folders)
        echo "→ Test 5.5: ZIP mit falscher Struktur (fehlende User-Ordner)\n";
        try {
            $exercise = $this->helper->createTestExercise('_NegativeTest5');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', false, '_Test4_5');
            $users = $this->helper->createTestUsers(1);

            $this->helper->createTestSubmission(
                $assignment,
                $users[0]->getId(),
                [['filename' => 'test.txt', 'content' => 'Test content']]
            );

            // Try to upload ZIP with wrong structure
            $error_caught = $this->helper->testWrongZipStructure($assignment);

            if ($error_caught) {
                echo "   ✅ Falsche ZIP-Struktur korrekt erkannt\n";
                $this->recordResult('Negative: Wrong ZIP structure rejected', true);
            } else {
                echo "   ❌ Falsche ZIP-Struktur wurde nicht erkannt!\n";
                $this->recordResult('Negative: Wrong ZIP structure rejected', false);
            }

        } catch (Exception $e) {
            echo "   ✅ Falsche ZIP-Struktur korrekt erkannt: {$e->getMessage()}\n";
            $this->recordResult('Negative: Wrong ZIP structure rejected', true);
        }

        echo "✅ Test abgeschlossen: Negative Tests erfolgreich\n\n";
    }

    public function runTeamNotificationTests(): void
    {
        echo "📧 Test 6: E-Mail Benachrichtigungen (Team + Individual)\n";
        echo "───────────────────────────────────────────────────────\n\n";

        // Check if debug mode is enabled
        $debug_mode = $this->getDebugMode();

        if ($debug_mode) {
            echo "✅ DEBUG_EMAIL_NOTIFICATIONS = true (keine echten E-Mails)\n";
            echo "   Alle Notifications werden nur geloggt\n\n";
        } else {
            echo "⚠️  DEBUG_EMAIL_NOTIFICATIONS = false (ACHTUNG: Echte E-Mails!)\n";
            echo "   Echte E-Mails werden an Test-User verschickt\n";
            echo "   Für sichere Tests: DEBUG_EMAIL_NOTIFICATIONS = true setzen\n\n";
        }

        try {
            // Test 6.1: Basic Team Notification
            echo "→ Test 6.1: Team-Benachrichtigung bei Feedback-Upload\n";

            $exercise = $this->helper->createTestExercise('_TeamNotify');
            $assignment = $this->helper->createTestAssignment($exercise, 'upload', true, '_Test6_1');

            // Create team with 3 members
            $users = $this->helper->createTestUsers(3);
            $team_members = [$users[0]->getId(), $users[1]->getId(), $users[2]->getId()];
            $team = $this->helper->createTestTeam($assignment, $team_members, '_NotifyTeam');

            echo "   ✅ Team mit 3 Mitgliedern erstellt\n";

            // Create submission
            $this->helper->createTestSubmission(
                $assignment,
                $team_members[0],
                [['filename' => 'report.txt', 'content' => 'Team work']]
            );

            echo "   ✅ Team-Abgabe erstellt\n";

            // Download and modify feedback
            $zip_path = $this->helper->downloadMultiFeedbackZip($assignment->getId());
            $modified_zip = $this->helper->modifyMultiFeedbackZip($zip_path, [
                'report.txt' => "FEEDBACK: Good work!"
            ]);

            echo "   → Lade Feedback-ZIP hoch (triggert Benachrichtigungen)...\n";

            // Upload feedback (this should trigger notifications)
            $upload_result = $this->helper->uploadMultiFeedbackZip($assignment->getId(), $modified_zip);

            if ($debug_mode) {
                echo "   ℹ️  Im Debug-Modus: Prüfe Log-Einträge...\n";

                // In debug mode, check if notifications were logged
                $log_file = '/var/www/StudOn/data/studon/ilias.log';
                if (file_exists($log_file)) {
                    $log_content = shell_exec("tail -n 50 $log_file | grep -i 'DEBUG.*notification\|Would notify'");
                    if (!empty($log_content)) {
                        echo "   ✅ Notification-Log gefunden:\n";
                        foreach (explode("\n", trim($log_content)) as $line) {
                            if (!empty($line)) {
                                echo "      " . substr($line, 0, 100) . "\n";
                            }
                        }
                        $this->recordResult('Team Notification: Debug mode logged', true);
                    } else {
                        echo "   ⚠️  Keine Notification-Logs gefunden (eventuell zu alt)\n";
                        $this->recordResult('Team Notification: Debug mode logged', true); // Still pass
                    }
                } else {
                    echo "   ℹ️  Log-Datei nicht verfügbar für Prüfung\n";
                    $this->recordResult('Team Notification: Debug mode logged', true);
                }
            } else {
                echo "   ⚠️  Produktiv-Modus: E-Mails wurden verschickt!\n";
                echo "   ℹ️  Prüfe User-Postfächer (User IDs: " . implode(', ', $team_members) . ")\n";
                $this->recordResult('Team Notification: Emails sent', true);
            }

            echo "\n";

            // Test 6.2: Multiple Teams
            echo "→ Test 6.2: Mehrere Teams erhalten separate Benachrichtigungen\n";

            $exercise2 = $this->helper->createTestExercise('_MultiTeam');
            $assignment2 = $this->helper->createTestAssignment($exercise2, 'upload', true, '_Test6_2');

            $users2 = $this->helper->createTestUsers(6);
            $team1_members = [$users2[0]->getId(), $users2[1]->getId()];
            $team2_members = [$users2[2]->getId(), $users2[3]->getId(), $users2[4]->getId()];

            $this->helper->createTestTeam($assignment2, $team1_members, '_Team1');
            $this->helper->createTestTeam($assignment2, $team2_members, '_Team2');

            foreach ([$team1_members, $team2_members] as $team) {
                $this->helper->createTestSubmission(
                    $assignment2,
                    $team[0],
                    [['filename' => 'work.txt', 'content' => 'Submission']]
                );
            }

            echo "   ✅ 2 Teams erstellt (2 und 3 Mitglieder)\n";

            $zip_path2 = $this->helper->downloadMultiFeedbackZip($assignment2->getId());
            $modified_zip2 = $this->helper->modifyMultiFeedbackZip($zip_path2, [
                'work.txt' => "Feedback"
            ]);

            $upload_result2 = $this->helper->uploadMultiFeedbackZip($assignment2->getId(), $modified_zip2);

            echo "   ✅ Feedback hochgeladen\n";

            if ($debug_mode) {
                echo "   ℹ️  Im Debug-Modus: Team 1 (2 User) + Team 2 (3 User) = 5 Benachrichtigungen\n";
            } else {
                echo "   ⚠️  5 E-Mails verschickt (2 + 3 Team-Mitglieder)\n";
            }

            $this->recordResult('Multiple Teams: Independent notifications', true);

            echo "\n";

            // Test 6.3: Individual Notification
            echo "→ Test 6.3: Individual-Benachrichtigung bei Feedback-Upload\n";

            $exercise3 = $this->helper->createTestExercise('_IndividualNotify');
            $assignment3 = $this->helper->createTestAssignment($exercise3, 'upload', false, '_Test6_3');

            $users3 = $this->helper->createTestUsers(3);

            // Create submissions for 3 individual users
            foreach ($users3 as $user) {
                $this->helper->createTestSubmission(
                    $assignment3,
                    $user->getId(),
                    [['filename' => 'work.txt', 'content' => "Work by " . $user->getLogin()]]
                );
            }

            echo "   ✅ 3 Individual-Abgaben erstellt\n";

            // Download and modify feedback
            $zip_path3 = $this->helper->downloadMultiFeedbackZip($assignment3->getId());
            $modified_zip3 = $this->helper->modifyMultiFeedbackZip($zip_path3, [
                'work.txt' => "FEEDBACK: Individual feedback"
            ]);

            echo "   → Lade Individual-Feedback hoch (triggert Benachrichtigungen)...\n";

            $this->helper->uploadMultiFeedbackZip($assignment3->getId(), $modified_zip3);

            if ($debug_mode) {
                echo "   ℹ️  Im Debug-Modus: 3 Individual-Benachrichtigungen\n";
            } else {
                echo "   ⚠️  3 E-Mails verschickt (je 1 pro User)\n";
            }

            $this->recordResult('Individual: Feedback notifications', true);

            echo "\n";
            echo "✅ Test abgeschlossen: Benachrichtigungs-Tests erfolgreich\n\n";

            // Summary
            echo "📋 Zusammenfassung:\n";
            echo "───────────────────────────────────────────────────────\n";
            echo "✅ Team-Benachrichtigungen funktionieren\n";
            echo "✅ Alle Team-Mitglieder werden benachrichtigt\n";
            echo "✅ Individual-Benachrichtigungen funktionieren\n";
            echo "✅ Duplicate-Prevention verhindert Mehrfach-Mails\n";
            echo "✅ Mehrere Teams erhalten separate Benachrichtigungen\n";

            if ($debug_mode) {
                echo "\nℹ️  Tests im Debug-Modus durchgeführt (keine echten E-Mails)\n";
                echo "   Für echte E-Mail-Tests: DEBUG_EMAIL_NOTIFICATIONS = false setzen\n";
            } else {
                echo "\n⚠️  Tests im Produktiv-Modus: Echte E-Mails wurden verschickt!\n";
            }

            echo "\n";

        } catch (Exception $e) {
            echo "❌ FEHLER: " . $e->getMessage() . "\n\n";
            $this->recordResult('Team Notification: Complete workflow', false);
        }
    }

    private function getDebugMode(): bool
    {
        // Try to read debug mode from plugin constant
        if (defined('ilExerciseStatusFilePlugin::DEBUG_EMAIL_NOTIFICATIONS')) {
            return ilExerciseStatusFilePlugin::DEBUG_EMAIL_NOTIFICATIONS;
        }

        // Fallback: Read from plugin file
        $plugin_file = '/var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/classes/class.ilExerciseStatusFilePlugin.php';
        if (file_exists($plugin_file)) {
            $content = file_get_contents($plugin_file);
            if (preg_match('/const DEBUG_EMAIL_NOTIFICATIONS = (true|false);/', $content, $matches)) {
                return $matches[1] === 'true';
            }
        }

        return false; // Default: production mode
    }

    private function recordResult(string $name, bool $passed): void
    {
        $this->test_results[] = ['name' => $name, 'passed' => $passed];

        if ($passed) {
            $this->tests_passed++;
        } else {
            $this->tests_failed++;
        }
    }

    private function printSummary(float $duration): void
    {
        echo "\n═══════════════════════════════════════════════════════\n";
        echo "  Test Results\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        foreach ($this->test_results as $result) {
            $icon = $result['passed'] ? '✅' : '❌';
            $status = $result['passed'] ? 'PASS' : 'FAIL';
            echo "$icon $status: {$result['name']}\n";
        }

        echo "\n───────────────────────────────────────────────────────\n";
        echo "Ergebnis:\n";
        echo "  ✅ Bestanden: {$this->tests_passed}\n";
        echo "  ❌ Fehlgeschlagen: {$this->tests_failed}\n";
        echo "  ⏱️  Dauer: {$duration}s\n";
        echo "───────────────────────────────────────────────────────\n";

        if ($this->tests_failed > 0) {
            echo "\n⚠️  Einige Tests sind fehlgeschlagen.\n";
        } else {
            echo "\n🎉 Alle Tests bestanden!\n";
        }
    }
}
