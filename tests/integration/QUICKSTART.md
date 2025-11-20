# Integration Tests - Quick Start Guide

## What These Tests Do

These tests **automate your entire manual workflow**:

```
Manual Process (Before):
1. Download multi-feedback ZIP
2. Extract ZIP
3. Edit files with corrections
4. Re-zip the folder
5. Upload via ILIAS
6. Check if files were renamed
7. Repeat for different scenarios...

Automated Process (Now):
1. Run: php run-all-tests.php
2. Done! ✅
```

## Installation - Zero Setup Required!

All dependencies are already in ILIAS. Just run the tests:

```bash
cd /var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration
php run-all-tests.php
```

## What Gets Tested

### ✅ Individual Assignments
- Creates 3 test users
- Each submits files
- Downloads multi-feedback ZIP
- Modifies files (simulates corrections)
- Uploads modified ZIP
- Verifies `_korrigiert` suffix appears

### ✅ Team Assignments
- Creates 6 users in 2 teams (3 members each)
- Teams submit files
- Tests download/modify/upload cycle
- Verifies team file handling

### ✅ Checksum Detection
- **Modified files** → Get `_korrigiert` suffix
- **Unmodified files** → Keep original name
- Ensures efficiency (only rename what changed)

### ✅ File Types Tested
- `.txt` files (text submissions)
- `.md` files (markdown documents)
- `.php` files (code submissions)
- `.pdf` files (reports)

## Test Results You'll See

```
═══════════════════════════════════════════════════════
  Multi-Feedback Upload Workflow - Integration Test
═══════════════════════════════════════════════════════

📝 Test 1: Individual Assignment Workflow
───────────────────────────────────────────────────────
✅ Created exercise, assignment, 3 users, and submissions
✅ Test 1 completed: 3/3 files renamed correctly

👥 Test 2: Team Assignment Workflow
───────────────────────────────────────────────────────
✅ Created exercise, team assignment, 6 users, 2 teams
✅ Test 2 completed: Both team files renamed correctly

🏷️  Test 3: Modified File Rename Detection
───────────────────────────────────────────────────────
✅ File correctly renamed: essay.txt → essay_korrigiert.txt

🔐 Test 4: Checksum Validation (Unchanged Files)
───────────────────────────────────────────────────────
✅ Unchanged file kept original name (checksum matched)

═══════════════════════════════════════════════════════
  Test Results
═══════════════════════════════════════════════════════

✅ PASS: Individual: Download ZIP
✅ PASS: Individual: Modify ZIP
✅ PASS: Individual: Upload ZIP
✅ PASS: Individual: Files renamed with _korrigiert
✅ PASS: Team: Download ZIP
✅ PASS: Team: Modify ZIP
✅ PASS: Team: Upload ZIP
✅ PASS: Team: Files renamed for both teams
✅ PASS: Rename: Modified file gets _korrigiert suffix
✅ PASS: Checksum: Unchanged file keeps original name

───────────────────────────────────────────────────────
Results:
  ✅ Passed:   10
  ❌ Failed:   0
───────────────────────────────────────────────────────

🎉 All tests passed!

  Duration: 12.45s
  Status:   ✅ ALL TESTS PASSED
```

## Common Commands

### Run all tests (recommended)
```bash
php run-all-tests.php
```

### Run tests but keep test data (for debugging)
```bash
php run-all-tests.php --no-cleanup
```

### Only run cleanup (if something went wrong)
```bash
php cleanup.php
```

### See all options
```bash
php run-all-tests.php --help
```

## What Happens Behind the Scenes

### Test Data Created:
- **Exercises**: `TEST_Exercise_<timestamp>`
- **Assignments**: `TEST_Assignment_Individual_<timestamp>`, etc.
- **Users**: `test_user_<timestamp>_1`, `test_user_<timestamp>_2`, ...
- **Teams**: `TEST_Team_<timestamp>`
- **Submissions**: Various files (.txt, .md, .php, .pdf)

### Test Data Cleaned Up:
- Automatically after tests complete
- Or manually with `php cleanup.php`
- All test objects removed from ILIAS
- All test users deleted
- File system cleaned up

## Debugging Failed Tests

### If tests fail:

1. **Check ILIAS logs**:
   ```bash
   tail -f /var/log/ilias/ilias.log
   ```

2. **Run without cleanup** to inspect:
   ```bash
   php run-all-tests.php --no-cleanup
   ```
   Then login to ILIAS and check test exercises manually.

3. **Clean up manually** when done:
   ```bash
   php cleanup.php
   ```

### Common issues:

| Problem | Solution |
|---------|----------|
| "Permission denied" | Check file/folder permissions |
| "Class not found" | Verify ILIAS bootstrap path |
| Tests timeout | Increase PHP `max_execution_time` |
| Files not renamed | Check logs for checksum errors |

## File Structure

```
tests/integration/
├── QUICKSTART.md               ← You are here!
├── README.md                   ← Full documentation
├── run-all-tests.php          ← Main test runner
├── test-upload-workflow.php   ← Upload workflow tests
├── cleanup.php                ← Manual cleanup
└── TestHelper.php             ← Test utilities
```

## Next Steps

### Want to add more tests?

Use the `TestHelper` class to easily create test scenarios:

```php
<?php
require_once 'TestHelper.php';

$helper = new IntegrationTestHelper();

// Create test exercise
$exercise = $helper->createTestExercise();

// Create assignment
$assignment = $helper->createTestAssignment($exercise, 'upload', false);

// Create users
$users = $helper->createTestUsers(5);

// Create submissions
foreach ($users as $user) {
    $helper->createTestSubmission($assignment, $user->getId(), [
        ['filename' => 'test.txt', 'content' => 'Test content']
    ]);
}

// Download, modify, upload
$zip = $helper->downloadMultiFeedbackZip($assignment->getId());
$modified = $helper->modifyMultiFeedbackZip($zip, ['test.txt' => 'CORRECTED']);
$helper->uploadMultiFeedbackZip($assignment->getId(), $modified);

// Verify
$renamed = $helper->verifyFileRenamed($assignment->getId(), $users[0]->getId(), 'test.txt');
echo $renamed ? "✅ Success\n" : "❌ Failed\n";

// Cleanup
$helper->cleanup();
```

## Performance

- Full test run: **~15 seconds**
- Individual test: **~10 seconds**
- Cleanup only: **~3 seconds**

Fast enough to run frequently during development!

## Why Use These Tests?

### Before (Manual Testing):
- ⏰ 10-15 minutes per test cycle
- 😰 Easy to forget edge cases
- 🐛 Bugs slip through
- 😴 Boring and repetitive

### After (Automated Tests):
- ⚡ 15 seconds per test cycle
- ✅ All scenarios tested every time
- 🎯 Catch bugs immediately
- 🤖 Run automatically

## Need Help?

1. Read [README.md](README.md) for full documentation
2. Check ILIAS logs: `/var/log/ilias/ilias.log`
3. Run with `--no-cleanup` to debug
4. Inspect test code in `test-upload-workflow.php`

## That's It!

You now have fully automated integration tests for your multi-feedback workflow. Just run `php run-all-tests.php` whenever you make changes to ensure everything still works! 🚀
