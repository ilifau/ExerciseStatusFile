<?php
declare(strict_types=1);

/**
 * Cleanup Script for Integration Tests
 *
 * Removes all test data created by integration tests:
 * - Test exercises (TEST_Exercise_*)
 * - Test users (test_user_*)
 * - Test files and submissions
 *
 * @author Integration Test Suite
 * @version 1.0.0
 */

// Bootstrap ILIAS
chdir('/var/www/StudOn');
require_once '/var/www/StudOn/libs/composer/vendor/autoload.php';
require_once '/var/www/StudOn/ilias.php';

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
        echo "   Test objects start with: TEST_Exercise_, TEST_Assignment_\n";
        echo "   Test users start with: test_user_\n\n";

        echo "Continue? [y/N]: ";
        $confirmation = trim(fgets(STDIN));

        if (strtolower($confirmation) !== 'y') {
            echo "Cancelled.\n";
            exit(0);
        }

        echo "\n";

        try {
            $this->cleanupTestExercises();
            $this->cleanupTestUsers();
            $this->printResults();

        } catch (Exception $e) {
            echo "❌ ERROR: " . $e->getMessage() . "\n";
            exit(1);
        }
    }

    /**
     * Delete all test exercises
     */
    private function cleanupTestExercises(): void
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
     * Delete all test users
     */
    private function cleanupTestUsers(): void
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
