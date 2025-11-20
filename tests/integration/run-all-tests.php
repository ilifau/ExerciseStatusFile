<?php
declare(strict_types=1);

/**
 * Main Test Runner for Integration Tests
 *
 * Runs all integration tests in sequence:
 * 1. Upload workflow tests
 * 2. (Future: Add more test suites here)
 *
 * Usage:
 *   php run-all-tests.php
 *   php run-all-tests.php --no-cleanup  (keeps test data after run)
 *   php run-all-tests.php --cleanup-only (only runs cleanup)
 *
 * @author Integration Test Suite
 * @version 1.0.0
 */

// Parse command line arguments
$options = getopt('', ['no-cleanup', 'cleanup-only', 'help', 'parent-ref:']);

if (isset($options['help'])) {
    echo "\n";
    echo "Integration Test Runner\n";
    echo "═══════════════════════════════════════════════════════\n\n";
    echo "Usage:\n";
    echo "  php run-all-tests.php                      Run all tests (with cleanup)\n";
    echo "  php run-all-tests.php --no-cleanup         Run tests but keep test data\n";
    echo "  php run-all-tests.php --cleanup-only       Only run cleanup script\n";
    echo "  php run-all-tests.php --parent-ref=123     Create tests under RefID 123\n";
    echo "  php run-all-tests.php --help               Show this help\n\n";
    echo "Examples:\n";
    echo "  php run-all-tests.php --parent-ref=456 --no-cleanup\n";
    echo "  php run-all-tests.php --parent-ref=789\n\n";
    exit(0);
}

// If cleanup-only, just run cleanup and exit
if (isset($options['cleanup-only'])) {
    echo "Running cleanup only...\n";
    require_once __DIR__ . '/cleanup.php';
    exit(0);
}

// Bootstrap ILIAS
chdir('/var/www/StudOn');
require_once '/var/www/StudOn/libs/composer/vendor/autoload.php';
require_once '/var/www/StudOn/ilias.php';

// Get parent ref ID from command line
$parent_ref_id = isset($options['parent-ref']) ? (int)$options['parent-ref'] : 1;

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  ExerciseStatusFile Plugin - Integration Tests\n";
echo "═══════════════════════════════════════════════════════\n";
echo "\n";

if ($parent_ref_id !== 1) {
    echo "ℹ️  Parent Category: RefID $parent_ref_id\n\n";
}

echo "This will:\n";
echo "  1. Create test exercises, users, and teams\n";
echo "  2. Run multi-feedback upload workflow tests\n";
echo "  3. Verify checksums and file renaming\n";

if (!isset($options['no-cleanup'])) {
    echo "  4. Clean up all test data\n";
}

echo "\n";

// Timestamp for test run
$start_time = microtime(true);

$all_passed = true;

// ==================== Run Test Suites ====================

try {
    // Test Suite 1: Upload Workflow
    echo "📦 Running Test Suite 1: Upload Workflow\n";
    echo "───────────────────────────────────────────────────────\n\n";

    ob_start();
    require __DIR__ . '/test-upload-workflow.php';
    $output = ob_get_clean();
    echo $output;

    // Check if test failed (exit code or error output)
    if (strpos($output, '❌ FATAL ERROR') !== false || strpos($output, 'Some tests failed') !== false) {
        $all_passed = false;
    }

} catch (Exception $e) {
    echo "❌ Test suite failed with exception: " . $e->getMessage() . "\n";
    $all_passed = false;
}

// ==================== Cleanup ====================

if (!isset($options['no-cleanup'])) {
    echo "\n";
    echo "🧹 Cleaning up test data...\n";
    echo "───────────────────────────────────────────────────────\n\n";

    try {
        require_once __DIR__ . '/TestHelper.php';
        $helper = new IntegrationTestHelper();
        $helper->cleanup();
        echo "✅ Cleanup completed\n";
    } catch (Exception $e) {
        echo "⚠️  Cleanup failed: " . $e->getMessage() . "\n";
        echo "   Run 'php cleanup.php' manually if needed\n";
    }
} else {
    echo "\n⚠️  Test data NOT cleaned up (--no-cleanup flag)\n";
    echo "   Run 'php cleanup.php' to remove test data\n";
}

// ==================== Final Results ====================

$end_time = microtime(true);
$duration = round($end_time - $start_time, 2);

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Integration Test Run Complete\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "  Duration: {$duration}s\n";

if ($all_passed) {
    echo "  Status:   ✅ ALL TESTS PASSED\n\n";
    exit(0);
} else {
    echo "  Status:   ❌ SOME TESTS FAILED\n\n";
    echo "Check the output above for details.\n\n";
    exit(1);
}
