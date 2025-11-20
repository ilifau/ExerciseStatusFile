# Integration Tests - ExerciseStatusFile Plugin

Automated end-to-end tests for the Multi-Feedback upload workflow.

## Overview

These integration tests automate the complete tutor workflow:

1. **Create test data** - Exercises, assignments, users, teams, submissions
2. **Download multi-feedback ZIP** - Simulates tutor downloading student submissions
3. **Modify files** - Simulates tutor making corrections
4. **Upload modified ZIP** - Tests the upload handler
5. **Verify results** - Checks checksums, file renaming, status updates

## Quick Start

### Run all tests:
```bash
cd /var/www/StudOn/Customizing/global/plugins/Services/UIComponent/UserInterfaceHook/ExerciseStatusFile/tests/integration
php run-all-tests.php
```

### Run tests without cleanup (for debugging):
```bash
php run-all-tests.php --no-cleanup
```

### Clean up test data manually:
```bash
php cleanup.php
```

## Test Suites

### 1. Upload Workflow Test (`test-upload-workflow.php`)

Tests the complete multi-feedback upload cycle:

**Test 1: Individual Assignment Workflow**
- Creates exercise with individual upload assignment
- Creates 3 test users with submissions
- Downloads multi-feedback ZIP
- Modifies files (simulates corrections)
- Uploads modified ZIP
- Verifies files are renamed with `_korrigiert` suffix

**Test 2: Team Assignment Workflow**
- Creates exercise with team upload assignment
- Creates 6 users in 2 teams (3 members each)
- Creates team submissions
- Tests download/modify/upload cycle
- Verifies team file handling

**Test 3: Modified File Rename Detection**
- Tests that modified files get `_korrigiert` suffix
- Verifies checksum-based detection works
- Example: `essay.txt` → `essay_korrigiert.txt`

**Test 4: Checksum Validation**
- Tests that UNmodified files keep original name
- Verifies checksum matching prevents unnecessary renames
- Ensures efficiency (don't rename unchanged files)

## File Structure

```
tests/integration/
├── README.md                    # This file
├── run-all-tests.php           # Main test runner
├── test-upload-workflow.php    # Upload workflow tests
├── cleanup.php                 # Manual cleanup script
└── TestHelper.php              # Test utilities and helpers
```

## Test Helper Class

The `IntegrationTestHelper` class provides utilities for:

- `createTestExercise()` - Creates test exercise in repository
- `createTestAssignment()` - Creates individual/team assignments
- `createTestUsers()` - Creates test users with credentials
- `createTestTeam()` - Creates teams with members
- `createTestSubmission()` - Creates file submissions
- `downloadMultiFeedbackZip()` - Downloads feedback ZIP
- `modifyMultiFeedbackZip()` - Modifies files in ZIP
- `uploadMultiFeedbackZip()` - Uploads modified ZIP
- `verifyFileRenamed()` - Checks if file has `_korrigiert` suffix
- `cleanup()` - Removes all test data

## What Gets Created

### Test Objects:
- Exercises: `TEST_Exercise_<timestamp>`
- Assignments: `TEST_Assignment_<type>_<timestamp>`
- Teams: `TEST_Team_<timestamp>`

### Test Users:
- Username: `test_user_<timestamp>_<number>`
- Email: `test<number>@example.com`
- Password: `test123!`

### Test Files:
- Various file types (.txt, .md, .php, .pdf placeholders)
- Content designed to test checksum detection
- Modified/unmodified versions for testing

## Cleanup

**Automatic cleanup** (default):
- Runs after all tests complete
- Removes all test exercises, users, submissions

**Manual cleanup**:
```bash
php cleanup.php
```

This will prompt for confirmation and delete:
- All exercises starting with `TEST_`
- All users starting with `test_user_`

## Command Line Options

### run-all-tests.php

| Option | Description |
|--------|-------------|
| (none) | Run all tests with automatic cleanup |
| `--no-cleanup` | Run tests but keep test data |
| `--cleanup-only` | Only run cleanup (skip tests) |
| `--help` | Show help message |

### cleanup.php

Interactive script that:
1. Finds all test objects and users
2. Asks for confirmation
3. Deletes everything

## Expected Output

### Successful run:
```
═══════════════════════════════════════════════════════
  ExerciseStatusFile Plugin - Integration Tests
═══════════════════════════════════════════════════════

📦 Running Test Suite 1: Upload Workflow
───────────────────────────────────────────────────────

📝 Test 1: Individual Assignment Workflow
───────────────────────────────────────────────────────
✅ Created exercise, assignment, 3 users, and submissions
✅ Test 1 completed: 3/3 files renamed correctly

👥 Test 2: Team Assignment Workflow
───────────────────────────────────────────────────────
✅ Created exercise, team assignment, 6 users, 2 teams, and submissions
✅ Test 2 completed: Both team files renamed correctly

🏷️  Test 3: Modified File Rename Detection
───────────────────────────────────────────────────────
✅ File correctly renamed: essay.txt → essay_korrigiert.txt

🔐 Test 4: Checksum Validation (Unchanged Files)
───────────────────────────────────────────────────────
✅ Unchanged file correctly kept original name (checksum matched)

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

═══════════════════════════════════════════════════════
  Integration Test Run Complete
═══════════════════════════════════════════════════════

  Duration: 12.45s
  Status:   ✅ ALL TESTS PASSED
```

## Troubleshooting

### Tests fail with "Permission denied"
- Check ILIAS file permissions
- Ensure web server user can write to temp directories

### Tests fail with "Class not found"
- Check ILIAS bootstrap path in scripts
- Ensure all ILIAS core classes are available

### Test data not cleaned up
- Run `php cleanup.php` manually
- Check ILIAS logs for deletion errors

### Files not renamed
- Check logs: `/var/log/ilias/ilias.log`
- Verify checksum calculation is working
- Ensure upload handler is processing modifications

## Adding New Tests

To add a new test suite:

1. Create new test file: `test-your-feature.php`
2. Use `IntegrationTestHelper` for setup
3. Add test to `run-all-tests.php`
4. Document in this README

Example test structure:
```php
<?php
require_once __DIR__ . '/TestHelper.php';

class YourFeatureTest
{
    private IntegrationTestHelper $helper;

    public function __construct()
    {
        $this->helper = new IntegrationTestHelper();
    }

    public function runTests(): void
    {
        // Your test logic here
        $exercise = $this->helper->createTestExercise();
        // ... test your feature ...
    }
}

$test = new YourFeatureTest();
$test->runTests();
```

## CI/CD Integration

These tests can be integrated into CI/CD pipelines:

```yaml
# Example GitLab CI
integration-tests:
  script:
    - cd tests/integration
    - php run-all-tests.php
  artifacts:
    when: on_failure
    paths:
      - /var/log/ilias/ilias.log
```

## Performance Notes

- Individual test suite: ~10-15 seconds
- Full run (all suites): ~15-20 seconds
- Cleanup: ~2-5 seconds

Tests use real ILIAS database and file operations, so timing depends on:
- Database speed
- File system performance
- Number of test users/objects created

## Future Enhancements

Potential additions:
- Browser automation tests (Selenium/Playwright)
- SOAP API tests
- Performance benchmarking
- Stress tests (large files, many users)
- Error condition tests (corrupted ZIPs, missing files)

## Support

For issues or questions:
- Check ILIAS logs: `/var/log/ilias/ilias.log`
- Review test output for specific errors
- Enable debug logging in TestHelper class
