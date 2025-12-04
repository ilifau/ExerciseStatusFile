<?php
declare(strict_types=1);

/**
 * Cleanup Script for Integration Tests
 *
 * Removes all test data created by integration tests.
 * Can be used after --keep-data tests to clean up manually.
 *
 * Uses TestHelper's emergencyCleanupByPrefix() which searches for:
 * - Test exercises: AUTOTEST_ExStatusFile_*
 * - Test users: autotest_exstatusfile_*
 *
 * Also cleans up legacy test data:
 * - Old exercises: TEST_Exercise_*
 * - Old users: test_user_*
 *
 * @author Integration Test Suite
 * @version 2.0.0
 */

// Bootstrap ILIAS
chdir(__DIR__ . '/../../../../../../../../../');
require_once './ilias.php';

// Initialize ILIAS
ilInitialisation::initILIAS();

// Load TestHelper
require_once __DIR__ . '/TestHelper.php';

class TestDataCleanup
{
    private ilLogger $logger;
    private ilDBInterface $db;
    private int $deleted_objects = 0;
    private int $deleted_users = 0;

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->db = $DIC->database();
    }

    public function run(): void
    {
        echo "\n";
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Integration Test Data Cleanup\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "⚠️  This will delete ALL test data (exercises, users, etc.)\n";
        echo "   New prefixes:\n";
        echo "     - Übungen: AUTOTEST_ExStatusFile_*\n";
        echo "     - User: autotest_exstatusfile_*\n";
        echo "   Legacy prefixes (falls vorhanden):\n";
        echo "     - Übungen: TEST_Exercise_*\n";
        echo "     - User: test_user_*\n\n";

        // Check if running from CLI or web
        $is_cli = php_sapi_name() === 'cli';

        if ($is_cli) {
            echo "Continue? [y/N]: ";
            $confirmation = trim(fgets(STDIN));

            if (strtolower($confirmation) !== 'y') {
                echo "Cancelled.\n";
                exit(0);
            }
        } else {
            // Web interface - require confirmation
            if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
                echo "<h2>Bestätigung erforderlich</h2>\n";
                echo "<p>Bist du sicher, dass du alle Test-Daten löschen möchtest?</p>\n";
                echo "<p><a href='?confirm=yes' style='color: red; font-weight: bold;'>JA, ALLE TEST-DATEN LÖSCHEN</a></p>\n";
                echo "<p><a href='../'>Abbrechen</a></p>\n";
                exit;
            }
        }

        echo "\n";

        try {
            // Use TestHelper's emergency cleanup for new prefixes
            $helper = new IntegrationTestHelper(1);
            $helper->emergencyCleanupByPrefix();

            echo "\n";

            // Also clean up legacy test data
            $this->cleanupLegacyTestData();

            $this->printResults();

        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Clean up old test data with legacy prefixes
     */
    public function cleanupLegacyTestData(): void
    {
        echo "🔍 Suche nach Legacy-Test-Daten...\n\n";
        $this->cleanupLegacyTestExercises();
        $this->cleanupLegacyTestUsers();
    }

    /**
     * Delete all legacy test exercises (TEST_Exercise_* prefix)
     */
    private function cleanupLegacyTestExercises(): void
    {
        echo "🗑️  Cleaning up test exercises...\n";

        // Find all exercises with TEST_ prefix
        $query = "SELECT obj_id, title FROM object_data
                  WHERE type = 'exc'
                  AND title LIKE 'TEST_%'";

        $result = $this->db->query($query);

        $exercises = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $exercises[] = [
                'obj_id' => (int)$row['obj_id'],
                'title' => $row['title']
            ];
        }

        if (empty($exercises)) {
            echo "   No test exercises found.\n\n";
            return;
        }

        echo "   Found " . count($exercises) . " test exercise(s):\n";

        foreach ($exercises as $exercise_data) {
            try {
                // Get ref_id
                $ref_query = "SELECT ref_id FROM object_reference
                              WHERE obj_id = " . $this->db->quote($exercise_data['obj_id'], 'integer') . "
                              AND deleted IS NULL";
                $ref_result = $this->db->query($ref_query);
                $ref_row = $this->db->fetchAssoc($ref_result);

                if ($ref_row) {
                    $ref_id = (int)$ref_row['ref_id'];

                    // Delete exercise
                    $exercise = new ilObjExercise($ref_id);
                    $exercise->delete();

                    echo "   ✅ Deleted: {$exercise_data['title']} (ID: {$exercise_data['obj_id']})\n";
                    $this->deleted_objects++;
                } else {
                    echo "   ⚠️  No ref_id found for: {$exercise_data['title']}\n";
                }

            } catch (Exception $e) {
                echo "   ❌ Failed to delete {$exercise_data['title']}: " . $e->getMessage() . "\n";
            }
        }

        echo "\n";
    }

    /**
     * Delete all legacy test users (test_user_* prefix)
     */
    private function cleanupLegacyTestUsers(): void
    {
        echo "🗑️  Cleaning up test users...\n";

        // Find all users with test_user_ prefix
        $query = "SELECT usr_id, login FROM usr_data
                  WHERE login LIKE 'test_user_%'";

        $result = $this->db->query($query);

        $users = [];
        while ($row = $this->db->fetchAssoc($result)) {
            $users[] = [
                'usr_id' => (int)$row['usr_id'],
                'login' => $row['login']
            ];
        }

        if (empty($users)) {
            echo "   No test users found.\n\n";
            return;
        }

        echo "   Found " . count($users) . " test user(s):\n";

        foreach ($users as $user_data) {
            try {
                $user = new ilObjUser($user_data['usr_id']);
                $user->delete();

                echo "   ✅ Deleted: {$user_data['login']} (ID: {$user_data['usr_id']})\n";
                $this->deleted_users++;

            } catch (Exception $e) {
                echo "   ❌ Failed to delete {$user_data['login']}: " . $e->getMessage() . "\n";
            }
        }

        echo "\n";
    }

    /**
     * Print cleanup results
     */
    private function printResults(): void
    {
        echo "═══════════════════════════════════════════════════════\n";
        echo "  Cleanup Results\n";
        echo "═══════════════════════════════════════════════════════\n\n";

        echo "  🗑️  Deleted objects: {$this->deleted_objects}\n";
        echo "  👤 Deleted users:   {$this->deleted_users}\n";

        echo "\n✅ Cleanup completed!\n";
    }
}

// Run cleanup
try {
    $cleanup = new TestDataCleanup();
    $cleanup->run();
} catch (Exception $e) {
    echo "❌ FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
