<?php
/**
 * Web-Based Integration Test Runner
 *
 * Access via browser: /Customizing/global/plugins/.../tests/integration/web-runner.php
 *
 * This provides a simple web interface to run integration tests
 */

// Bootstrap ILIAS (web context)
$ilias_root = '/var/www/StudOn';
chdir($ilias_root);
require_once $ilias_root . '/ilias.php';

// Check if user is logged in and has admin rights
global $DIC;
$user = $DIC->user();

if (!$user || $user->isAnonymous()) {
    die("❌ Error: You must be logged in to run tests");
}

// Nur für Admins oder bestimmte Rechte
if (!$DIC->rbac()->system()->checkAccess('visible', SYSTEM_FOLDER_ID)) {
    die("❌ Error: Insufficient permissions");
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>Integration Tests - ExerciseStatusFile Plugin</title>
    <style>
        body {
            font-family: 'Monaco', 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #252526;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        h1 {
            color: #4ec9b0;
            border-bottom: 2px solid #4ec9b0;
            padding-bottom: 10px;
        }
        h2 {
            color: #569cd6;
            margin-top: 30px;
        }
        .button {
            background: #0e639c;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            margin: 5px;
            font-family: inherit;
        }
        .button:hover {
            background: #1177bb;
        }
        .button.danger {
            background: #d73a49;
        }
        .button.danger:hover {
            background: #cb2431;
        }
        .button.success {
            background: #28a745;
        }
        .button.success:hover {
            background: #1e7e34;
        }
        .output {
            background: #1e1e1e;
            border: 1px solid #3c3c3c;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
            white-space: pre-wrap;
            font-family: inherit;
            max-height: 600px;
            overflow-y: auto;
        }
        .info {
            background: #264f78;
            padding: 15px;
            border-left: 4px solid #569cd6;
            margin: 20px 0;
            border-radius: 4px;
        }
        .warning {
            background: #4d3800;
            padding: 15px;
            border-left: 4px solid #ce9178;
            margin: 20px 0;
            border-radius: 4px;
        }
        .success {
            background: #1a3d1a;
            padding: 15px;
            border-left: 4px solid #4ec9b0;
            margin: 20px 0;
            border-radius: 4px;
        }
        code {
            background: #1e1e1e;
            padding: 2px 6px;
            border-radius: 3px;
            color: #ce9178;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🧪 Integration Tests - ExerciseStatusFile Plugin</h1>

    <div class="info">
        <strong>ℹ️ Info:</strong> Diese Tests erstellen echte ILIAS-Objekte (Übungen, User, Teams) und testen den kompletten Multi-Feedback Workflow.
        <br><br>
        <strong>⚠️ Wichtig:</strong> Tests laufen ca. 15-30 Sekunden. Die Test-Daten werden automatisch aufgeräumt.
    </div>

    <h2>📋 Verfügbare Tests</h2>

    <form method="POST" action="" style="margin: 20px 0;">
        <div style="margin-bottom: 15px;">
            <label for="parent_ref_id" style="display: inline-block; width: 200px; color: #d4d4d4;">
                📁 Parent Category (RefID):
            </label>
            <input type="number"
                   id="parent_ref_id"
                   name="parent_ref_id"
                   value="1"
                   min="1"
                   style="padding: 8px; width: 120px; background: #1e1e1e; color: #d4d4d4; border: 1px solid #3c3c3c; border-radius: 4px;"
                   title="RefID des Eltern-Ordners (1 = Root)">
            <span style="color: #888; font-size: 12px; margin-left: 10px;">
                (1 = Root, oder eine andere RefID für Unterordner)
            </span>
        </div>

        <button type="submit" name="action" value="run_all" class="button success">
            ▶️ Alle Tests ausführen
        </button>

        <button type="submit" name="action" value="run_individual" class="button">
            👤 Nur Individual-Tests
        </button>

        <button type="submit" name="action" value="run_team" class="button">
            👥 Nur Team-Tests
        </button>

        <button type="submit" name="action" value="cleanup" class="button danger">
            🗑️ Test-Daten aufräumen
        </button>
    </form>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $action = $_POST['action'];

        echo '<div class="output" id="test-output">';

        // Flush output immediately
        ob_implicit_flush(true);

        // Get optional parent_ref_id from POST
        $parent_ref_id = isset($_POST['parent_ref_id']) ? (int)$_POST['parent_ref_id'] : 1;

        try {
            switch ($action) {
                case 'run_all':
                    echo "═══════════════════════════════════════════════════════\n";
                    echo "  Starte ALLE Integration Tests\n";
                    echo "═══════════════════════════════════════════════════════\n\n";
                    if ($parent_ref_id !== 1) {
                        echo "ℹ️  Parent Category: RefID $parent_ref_id\n\n";
                    }
                    require_once __DIR__ . '/TestHelper.php';
                    require_once __DIR__ . '/test-runner-core.php';
                    $runner = new IntegrationTestRunner($parent_ref_id);
                    $runner->runAll();
                    break;

                case 'run_individual':
                    echo "Running individual assignment tests...\n\n";
                    if ($parent_ref_id !== 1) {
                        echo "ℹ️  Parent Category: RefID $parent_ref_id\n\n";
                    }
                    require_once __DIR__ . '/TestHelper.php';
                    require_once __DIR__ . '/test-runner-core.php';
                    $runner = new IntegrationTestRunner($parent_ref_id);
                    $runner->runIndividualTests();
                    break;

                case 'run_team':
                    echo "Running team assignment tests...\n\n";
                    if ($parent_ref_id !== 1) {
                        echo "ℹ️  Parent Category: RefID $parent_ref_id\n\n";
                    }
                    require_once __DIR__ . '/TestHelper.php';
                    require_once __DIR__ . '/test-runner-core.php';
                    $runner = new IntegrationTestRunner($parent_ref_id);
                    $runner->runTeamTests();
                    break;

                case 'cleanup':
                    echo "═══════════════════════════════════════════════════════\n";
                    echo "  Räume Test-Daten auf\n";
                    echo "═══════════════════════════════════════════════════════\n\n";
                    require_once __DIR__ . '/TestHelper.php';
                    $helper = new IntegrationTestHelper($parent_ref_id);
                    $helper->cleanupAll();
                    echo "\n✅ Cleanup abgeschlossen!\n";
                    break;
            }
        } catch (Exception $e) {
            echo "\n❌ FEHLER: " . htmlspecialchars($e->getMessage()) . "\n";
            echo "\nStack Trace:\n" . htmlspecialchars($e->getTraceAsString()) . "\n";
        }

        echo '</div>';
    }
    ?>

    <h2>📖 Was wird getestet?</h2>

    <div class="info">
        <strong>✅ Individual Assignment Workflow:</strong>
        <ul>
            <li>Erstellt 3 Test-User mit Abgaben</li>
            <li>Download Multi-Feedback ZIP</li>
            <li>Dateien ändern (simuliert Tutor-Korrekturen)</li>
            <li>ZIP hochladen</li>
            <li>Prüft: Dateien haben <code>_korrigiert</code> Suffix</li>
        </ul>

        <strong>✅ Team Assignment Workflow:</strong>
        <ul>
            <li>Erstellt 6 User in 2 Teams (je 3 Mitglieder)</li>
            <li>Team-Abgaben erstellen</li>
            <li>Download/Ändern/Upload-Zyklus testen</li>
            <li>Team-Datei-Behandlung verifizieren</li>
        </ul>

        <strong>✅ Checksum Detection:</strong>
        <ul>
            <li>Geänderte Dateien → bekommen <code>_korrigiert</code></li>
            <li>Unveränderte Dateien → behalten Original-Namen</li>
        </ul>
    </div>

    <h2>🧹 Cleanup</h2>

    <div class="warning">
        <strong>⚠️ Test-Daten:</strong><br>
        Tests erstellen Objekte mit Präfix <code>TEST_</code>:<br>
        • Übungen: <code>TEST_Exercise_*</code><br>
        • User: <code>test_user_*</code><br>
        • Teams: <code>TEST_Team_*</code><br>
        <br>
        Diese werden normalerweise automatisch aufgeräumt. Falls nicht, nutze den "Test-Daten aufräumen" Button.
    </div>

    <p style="margin-top: 40px; color: #888; font-size: 12px;">
        ExerciseStatusFile Plugin v1.1.1 | Integration Test Suite
    </p>
</div>

<script>
// Auto-scroll output to bottom as it updates
const output = document.getElementById('test-output');
if (output) {
    const observer = new MutationObserver(() => {
        output.scrollTop = output.scrollHeight;
    });
    observer.observe(output, { childList: true, subtree: true });
}
</script>

</body>
</html>
