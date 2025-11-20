<?php
/**
 * Simple Integration Test - No GUI needed
 *
 * Run via CLI: php simple-test.php
 *
 * This creates real test data and shows you what was created!
 */

// Prevent timeout
set_time_limit(300);

// Bootstrap ILIAS with minimal context
chdir('/var/www/StudOn');

// We need to fake some context for ILIAS to work in CLI mode
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/ilias.php';

require_once '/var/www/StudOn/libs/composer/vendor/autoload.php';

// Initialize ILIAS
define('IL_COOKIE_HTTPONLY', true);
define('IL_COOKIE_EXPIRE', 0);
define('IL_COOKIE_PATH', '/');
define('IL_COOKIE_DOMAIN', '');

require_once './Services/Init/classes/class.ilInitialisation.php';
ilInitialisation::initILIAS();

global $DIC;

echo "\n";
echo "═══════════════════════════════════════════════════════\n";
echo "  Simple Integration Test\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "✅ ILIAS initialized successfully\n";
echo "   Database: Connected\n";
echo "   User: " . $DIC->user()->getLogin() . " (ID: " . $DIC->user()->getId() . ")\n\n";

// Load test helper
require_once __DIR__ . '/TestHelper.php';
$helper = new IntegrationTestHelper();

echo "🔨 Creating test data...\n\n";

try {
    // 1. Create exercise
    echo "📚 Creating test exercise...\n";
    $exercise = $helper->createTestExercise('_SimpleTest');
    echo "   ✅ Exercise created: '{$exercise->getTitle()}' (ID: {$exercise->getId()}, RefID: {$exercise->getRefId()})\n\n";

    // 2. Create assignment
    echo "📝 Creating individual assignment...\n";
    $assignment = $helper->createTestAssignment($exercise, 'upload', false, '_SimpleTest');
    echo "   ✅ Assignment created: '{$assignment->getTitle()}' (ID: {$assignment->getId()})\n\n";

    // 3. Create test users
    echo "👥 Creating 2 test users...\n";
    $users = $helper->createTestUsers(2);
    foreach ($users as $user) {
        echo "   ✅ User: {$user->getLogin()} (ID: {$user->getId()})\n";
    }
    echo "\n";

    // 4. Create submissions
    echo "📤 Creating submissions...\n";
    foreach ($users as $user) {
        $helper->createTestSubmission(
            $assignment,
            $user->getId(),
            [
                [
                    'filename' => 'test_submission.txt',
                    'content' => "This is a test submission from {$user->getLogin()}\n\nLine 2\nLine 3"
                ]
            ]
        );
        echo "   ✅ Submission created for {$user->getLogin()}\n";
    }
    echo "\n";

    echo "═══════════════════════════════════════════════════════\n";
    echo "  Test Data Created Successfully!\n";
    echo "═══════════════════════════════════════════════════════\n\n";

    echo "🔍 You can now check in ILIAS:\n\n";
    echo "1. Login to ILIAS\n";
    echo "2. Go to Repository → Search for: TEST_Exercise\n";
    echo "3. You should see: {$exercise->getTitle()}\n";
    echo "4. Check Users → Search for: test_user\n";
    echo "5. You should see 2 users\n\n";

    echo "📊 Test Data Summary:\n";
    echo "   • Exercise: {$exercise->getTitle()}\n";
    echo "   • Assignment: {$assignment->getTitle()}\n";
    echo "   • Users: " . count($users) . "\n";
    echo "   • Submissions: " . count($users) . "\n\n";

    // Ask if cleanup should run
    echo "═══════════════════════════════════════════════════════\n";
    echo "  Cleanup Options\n";
    echo "═══════════════════════════════════════════════════════\n\n";

    echo "What do you want to do?\n";
    echo "  [1] Keep test data (check in ILIAS GUI)\n";
    echo "  [2] Clean up now\n";
    echo "  [3] I'll cleanup manually later\n\n";

    if (php_sapi_name() === 'cli') {
        echo "Choice [1-3]: ";
        $choice = trim(fgets(STDIN));

        if ($choice === '2') {
            echo "\n🧹 Cleaning up test data...\n";
            $helper->cleanupAll();
            echo "✅ Cleanup complete!\n\n";
        } elseif ($choice === '3') {
            echo "\n💡 To cleanup later, run:\n";
            echo "   php cleanup.php\n\n";
        } else {
            echo "\n✅ Test data kept. Check ILIAS GUI now!\n";
            echo "\n💡 To cleanup later, run:\n";
            echo "   php cleanup.php\n\n";
        }
    } else {
        echo "💡 To cleanup, run: php cleanup.php\n\n";
    }

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "🎉 Done!\n\n";
