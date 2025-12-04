<?php
/**
 * Emergency Cleanup Script
 *
 * Löscht ALLE Test-Objekte mit den Präfixen:
 * - AUTOTEST_ExStatusFile_* (Übungen)
 * - autotest_exstatusfile_* (User)
 *
 * ACHTUNG: Dieses Skript sollte nur verwendet werden, wenn:
 * - Ein Test abgestürzt ist und Daten nicht aufgeräumt wurden
 * - Du alle Test-Daten manuell löschen möchtest
 *
 * Verwendung:
 * - Via Browser: https://your-ilias.de/Customizing/.../emergency-cleanup.php
 * - Via CLI: php emergency-cleanup.php
 */

// Bootstrap ILIAS
chdir(__DIR__ . '/../../../../../../../../../');
require_once './ilias.php';

// Initialize ILIAS
ilInitialisation::initILIAS();

// Load TestHelper
require_once __DIR__ . '/TestHelper.php';

echo "═══════════════════════════════════════════════════════\n";
echo "  ExerciseStatusFile Plugin - Notfall-Cleanup\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "⚠️  WARNUNG: Dieses Skript löscht ALLE Test-Objekte!\n";
echo "   - Präfix für Übungen: AUTOTEST_ExStatusFile_*\n";
echo "   - Präfix für User: autotest_exstatusfile_*\n\n";

// Check if running from CLI or web
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    // Web interface - require confirmation
    if (!isset($_GET['confirm']) || $_GET['confirm'] !== 'yes') {
        echo "<h2>Bestätigung erforderlich</h2>\n";
        echo "<p>Bist du sicher, dass du alle Test-Daten löschen möchtest?</p>\n";
        echo "<p><a href='?confirm=yes'>JA, ALLE TEST-DATEN LÖSCHEN</a></p>\n";
        echo "<p><a href='../'>Abbrechen</a></p>\n";
        exit;
    }
}

// Create helper instance (parent_ref_id doesn't matter for cleanup)
$helper = new IntegrationTestHelper(1);

// Run emergency cleanup
$helper->emergencyCleanupByPrefix();

echo "\n═══════════════════════════════════════════════════════\n";
echo "  Cleanup abgeschlossen!\n";
echo "═══════════════════════════════════════════════════════\n";

if (!$is_cli) {
    echo "<p><a href='../'>Zurück</a></p>\n";
}
