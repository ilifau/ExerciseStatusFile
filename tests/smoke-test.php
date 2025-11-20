#!/usr/bin/env php
<?php
/**
 * Smoke Tests für ExerciseStatusFile Plugin
 *
 * Führt grundlegende Checks aus OHNE ILIAS-Abhängigkeiten
 *
 * Verwendung:
 *   php tests/smoke-test.php
 *
 * Exit Codes:
 *   0 = Alle Tests bestanden
 *   1 = Tests fehlgeschlagen
 */

define('PLUGIN_DIR', dirname(__DIR__));

class SmokeTests
{
    private $passed = 0;
    private $failed = 0;
    private $warnings = 0;

    public function run(): int
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  ExerciseStatusFile Plugin - Smoke Tests\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        $this->testFileStructure();
        $this->testPhpSyntax();
        $this->testClassStructure();
        $this->testTeamDetection();
        $this->testSecurityFunctions();
        $this->testAssignmentDetection();
        $this->testChecksumFeature();
        $this->testSystemFileFiltering();
        $this->testPerformanceOptimizations();

        echo "\n";
        echo "───────────────────────────────────────────────────────\n";
        echo "Results:\n";
        echo "  ✅ Passed:   {$this->passed}\n";
        echo "  ❌ Failed:   {$this->failed}\n";
        echo "  ⚠️  Warnings: {$this->warnings}\n";
        echo "───────────────────────────────────────────────────────\n\n";

        return $this->failed > 0 ? 1 : 0;
    }

    private function test(string $name, callable $check, bool $critical = true): void
    {
        try {
            $result = $check();
            if ($result === true) {
                echo "✅ PASS: $name\n";
                $this->passed++;
            } else {
                if ($critical) {
                    echo "❌ FAIL: $name\n";
                    if (is_string($result)) {
                        echo "   → $result\n";
                    }
                    $this->failed++;
                } else {
                    echo "⚠️  WARN: $name\n";
                    if (is_string($result)) {
                        echo "   → $result\n";
                    }
                    $this->warnings++;
                }
            }
        } catch (Exception $e) {
            echo "❌ ERROR: $name\n";
            echo "   → Exception: {$e->getMessage()}\n";
            $this->failed++;
        }
    }

    private function testFileStructure(): void
    {
        echo "\n📁 File Structure Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $required_files = [
            'plugin.php',
            'README.md',
            'classes/class.ilExerciseStatusFileUIHookGUI.php',
            'classes/Processing/class.ilExFeedbackUploadHandler.php',
            'classes/Processing/class.ilExMultiFeedbackDownloadHandler.php',
            'classes/Processing/class.ilExIndividualMultiFeedbackDownloadHandler.php',
        ];

        foreach ($required_files as $file) {
            $this->test(
                "File exists: $file",
                fn() => file_exists(PLUGIN_DIR . '/' . $file)
            );
        }

        $this->test(
            ".gitignore excludes ki_infos/",
            function() {
                $gitignore = @file_get_contents(PLUGIN_DIR . '/.gitignore');
                return $gitignore && strpos($gitignore, 'ki_infos/') !== false;
            },
            false
        );
    }

    private function testPhpSyntax(): void
    {
        echo "\n🔍 PHP Syntax Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $php_files = $this->findPhpFiles(PLUGIN_DIR . '/classes');

        foreach ($php_files as $file) {
            $relative = str_replace(PLUGIN_DIR . '/', '', $file);
            $this->test(
                "PHP syntax: $relative",
                function() use ($file) {
                    $output = [];
                    $return_var = 0;
                    exec("php -l " . escapeshellarg($file) . " 2>&1", $output, $return_var);
                    return $return_var === 0;
                }
            );
        }
    }

    private function testClassStructure(): void
    {
        echo "\n🏗️  Class Structure Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        // Test dass wichtige Methoden existieren (ohne ILIAS zu laden)
        $upload_handler = file_get_contents(PLUGIN_DIR . '/classes/Processing/class.ilExFeedbackUploadHandler.php');

        $this->test(
            "handleFeedbackUpload method exists",
            fn() => strpos($upload_handler, 'function handleFeedbackUpload') !== false
        );

        $this->test(
            "extractZipContents method exists",
            fn() => strpos($upload_handler, 'function extractZipContents') !== false
        );

        $this->test(
            "Security: Path traversal prevention in extractZipContents",
            fn() => strpos($upload_handler, 'Path traversal') !== false &&
                    strpos($upload_handler, 'realpath') !== false
        );

        $this->test(
            "processTeamFeedbackFiles method exists",
            fn() => strpos($upload_handler, 'function processTeamFeedbackFiles') !== false
        );

        $this->test(
            "processIndividualFeedbackFiles method exists",
            fn() => strpos($upload_handler, 'function processIndividualFeedbackFiles') !== false
        );

        $this->test(
            "filterNewFeedbackFiles method exists",
            fn() => strpos($upload_handler, 'function filterNewFeedbackFiles') !== false
        );

        $this->test(
            "processUserSpecificFeedback method exists",
            fn() => strpos($upload_handler, 'function processUserSpecificFeedback') !== false
        );

        $this->test(
            "Dead method isUserMarkedForUpdate removed",
            fn() => strpos($upload_handler, 'function isUserMarkedForUpdate') === false,
            false
        );
    }

    private function testTeamDetection(): void
    {
        echo "\n👥 Team Detection Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $ui_hook = file_get_contents(PLUGIN_DIR . '/classes/class.ilExerciseStatusFileUIHookGUI.php');

        $this->test(
            "getAssignmentInfo uses usesTeams() method",
            fn() => strpos($ui_hook, 'usesTeams()') !== false
        );

        $this->test(
            "Team detection doesn't rely on hardcoded type == 4 only",
            function() use ($ui_hook) {
                // Check that usesTeams() is used BEFORE the fallback
                $uses_teams_pos = strpos($ui_hook, 'usesTeams()');
                $type_check_pos = strpos($ui_hook, '$type == 4');

                if ($uses_teams_pos === false) {
                    return "usesTeams() not found in code";
                }

                // If type == 4 exists, it should be AFTER usesTeams() (in fallback)
                if ($type_check_pos !== false && $type_check_pos < $uses_teams_pos) {
                    return "type == 4 check appears before usesTeams() - wrong order";
                }

                return true;
            }
        );

        $this->test(
            "getAssignmentInfo has proper exception handling",
            fn() => strpos($ui_hook, 'try {') !== false &&
                    strpos($ui_hook, 'catch (Exception') !== false &&
                    strpos($ui_hook, 'getAssignmentInfo') !== false
        );

        $this->test(
            "Fallback mechanism exists for type detection",
            fn() => strpos($ui_hook, 'Fallback') !== false ||
                    strpos($ui_hook, 'fallback') !== false
        );

        $this->test(
            "Team detection uses ILIAS Assignment API",
            fn() => strpos($ui_hook, 'new \ilExAssignment') !== false ||
                    strpos($ui_hook, 'new \\ilExAssignment') !== false
        );

        $this->test(
            "Team vs Individual modal routing exists",
            fn() => strpos($ui_hook, 'renderTeamButton') !== false &&
                    strpos($ui_hook, 'renderIndividualButton') !== false
        );
    }

    private function testSecurityFunctions(): void
    {
        echo "\n🔒 Security Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $upload_handler = file_get_contents(PLUGIN_DIR . '/classes/Processing/class.ilExFeedbackUploadHandler.php');

        $this->test(
            "Path traversal prevention: ../ filtering",
            fn() => strpos($upload_handler, "str_replace(['../', '..\\\\']") !== false ||
                    strpos($upload_handler, "../") !== false
        );

        $this->test(
            "Path traversal prevention: realpath() check",
            fn() => strpos($upload_handler, 'realpath($extract_dir)') !== false &&
                    strpos($upload_handler, 'realpath($extracted_path)') !== false
        );

        $this->test(
            "Null-byte protection",
            fn() => strpos($upload_handler, '\\0') !== false ||
                    strpos($upload_handler, 'null byte') !== false
        );

        $this->test(
            "Security logging for suspicious files",
            fn() => strpos($upload_handler, 'Suspicious filename') !== false ||
                    strpos($upload_handler, 'Path traversal attempt') !== false
        );

        $this->test(
            "File deletion on security violation",
            fn() => strpos($upload_handler, '@unlink') !== false ||
                    strpos($upload_handler, 'unlink($extracted_path)') !== false
        );
    }

    private function testAssignmentDetection(): void
    {
        echo "\n🎯 Assignment Detection Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $detector_file = PLUGIN_DIR . '/classes/Detection/class.ilExAssignmentDetector.php';
        $detector_content = file_get_contents($detector_file);

        $this->test(
            "Assignment detection: saveToSession method exists",
            fn() => strpos($detector_content, 'private function saveToSession') !== false
        );

        $this->test(
            "Assignment detection: Session storage implementation",
            fn() => strpos($detector_content, "exc_status_file_last_assignment") !== false
        );

        $this->test(
            "Assignment detection: Session detection checks custom key",
            fn() => strpos($detector_content, "['exc_status_file_last_assignment']") !== false
        );

        $this->test(
            "Assignment detection: saveToSession called on direct params",
            fn() => strpos($detector_content, '$this->saveToSession($direct_result)') !== false
        );
    }

    private function testChecksumFeature(): void
    {
        echo "\n🔐 Checksum Feature Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $upload_handler = file_get_contents(PLUGIN_DIR . '/classes/Processing/class.ilExFeedbackUploadHandler.php');

        $this->test(
            "checkIfFileModified method exists",
            fn() => strpos($upload_handler, 'function checkIfFileModified') !== false
        );

        $this->test(
            "renameModifiedSubmission method exists",
            fn() => strpos($upload_handler, 'function renameModifiedSubmission') !== false
        );

        $this->test(
            "Checksum validation uses MD5",
            fn() => strpos($upload_handler, 'md5_file') !== false
        );

        $this->test(
            "Modified files are renamed with '_korrigiert' suffix",
            fn() => strpos($upload_handler, '_korrigiert') !== false
        );

        $this->test(
            "Checksum feature logs file modifications",
            fn() => strpos($upload_handler, 'was MODIFIED') !== false ||
                    strpos($upload_handler, 'hash mismatch') !== false
        );

        $this->test(
            "Checksum feature logs identical files",
            fn() => strpos($upload_handler, 'identical hash') !== false ||
                    strpos($upload_handler, 'FILTERED OUT') !== false
        );

        $this->test(
            "loadChecksumsFromExtractedFiles method exists",
            fn() => strpos($upload_handler, 'function loadChecksumsFromExtractedFiles') !== false
        );

        $this->test(
            "Checksums are loaded from checksums.json",
            fn() => strpos($upload_handler, 'checksums.json') !== false
        );
    }

    private function testSystemFileFiltering(): void
    {
        echo "\n📋 System File Filtering Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $upload_handler = file_get_contents(PLUGIN_DIR . '/classes/Processing/class.ilExFeedbackUploadHandler.php');

        $this->test(
            "System filenames array includes all required files",
            fn() => strpos($upload_handler, "['status.xlsx', 'status.csv', 'status.xls', 'checksums.json', 'README.md']") !== false
        );

        $this->test(
            "User folder detection pattern exists",
            fn() => strpos($upload_handler, '$is_in_user_folder') !== false
        );

        $this->test(
            "System files in root are filtered",
            fn() => strpos($upload_handler, 'Skipping root system file') !== false
        );

        $this->test(
            "System files in user folders are NOT filtered",
            fn() => strpos($upload_handler, '&& !$is_in_user_folder') !== false
        );

        $this->test(
            "Log message for system files in user folders",
            fn() => strpos($upload_handler, 'Found system filename') !== false &&
                    strpos($upload_handler, 'in user folder') !== false
        );

        $this->test(
            "README.md in user folders is processed normally",
            fn() => strpos($upload_handler, 'will be processed normally') !== false
        );

        $this->test(
            "User folder pattern matches expected format",
            fn() => strpos($upload_handler, '/\/[^\/]+_[^\/]+_[^\/]+_\d+\/[^\/]+$/') !== false
        );
    }

    private function testPerformanceOptimizations(): void
    {
        echo "\n⚡ Performance Optimization Tests\n";
        echo "───────────────────────────────────────────────────────\n";

        $user_provider = file_get_contents(PLUGIN_DIR . '/classes/Processing/class.ilExUserDataProvider.php');

        $this->test(
            "Batch user data loading method exists",
            fn() => strpos($user_provider, 'function getUserDataBatch') !== false
        );

        $this->test(
            "Batch user data uses single query with IN clause",
            fn() => strpos($user_provider, '$this->db->in(\'usr_id\', $user_ids') !== false &&
                    strpos($user_provider, 'getUserDataBatch') !== false
        );

        $this->test(
            "Batch submission check method exists",
            fn() => strpos($user_provider, 'function checkSubmissionsExistBatch') !== false
        );

        $this->test(
            "Batch submission check uses single query",
            fn() => strpos($user_provider, 'FROM exc_returned') !== false &&
                    strpos($user_provider, 'GROUP BY user_id') !== false &&
                    strpos($user_provider, 'checkSubmissionsExistBatch') !== false
        );

        $this->test(
            "Batch user status loading method exists",
            fn() => strpos($user_provider, 'function getUserStatusesBatch') !== false
        );

        $this->test(
            "Batch user status uses single query",
            fn() => strpos($user_provider, 'FROM exc_mem_ass_status') !== false &&
                    strpos($user_provider, 'getUserStatusesBatch') !== false
        );

        $this->test(
            "Optimized user data builder method exists",
            fn() => strpos($user_provider, 'function buildUserDataOptimized') !== false
        );

        $this->test(
            "Main method uses batch loading (N+1 fix)",
            fn() => strpos($user_provider, '$users_data_map = $this->getUserDataBatch') !== false &&
                    strpos($user_provider, '$submissions_map = $this->checkSubmissionsExistBatch') !== false &&
                    strpos($user_provider, '$statuses_map = $this->getUserStatusesBatch') !== false
        );

        $this->test(
            "Gzip compression for AJAX responses",
            fn() => strpos($user_provider, 'ob_gzhandler') !== false &&
                    strpos($user_provider, 'generateJSONResponse') !== false
        );

        $this->test(
            "JSON response includes proper headers",
            fn() => strpos($user_provider, 'Content-Type: application/json') !== false &&
                    strpos($user_provider, 'Cache-Control: no-cache') !== false
        );
    }

    private function findPhpFiles(string $dir): array
    {
        $files = [];
        $items = scandir($dir);

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                $files = array_merge($files, $this->findPhpFiles($path));
            } elseif (substr($item, -4) === '.php') {
                $files[] = $path;
            }
        }

        return $files;
    }
}

// Run tests
$tests = new SmokeTests();
exit($tests->run());
