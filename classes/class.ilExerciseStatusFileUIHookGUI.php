<?php
declare(strict_types=1);

// Includes für alle Plugin-Klassen
require_once __DIR__ . '/Detection/class.ilExAssignmentDetector.php';
require_once __DIR__ . '/UI/class.ilExTeamButtonRenderer.php';
require_once __DIR__ . '/Processing/class.ilExFeedbackDownloadHandler.php';
require_once __DIR__ . '/Processing/class.ilExFeedbackUploadHandler.php';
require_once __DIR__ . '/Processing/class.ilExTeamDataProvider.php';
require_once __DIR__ . '/Processing/class.ilExMultiFeedbackDownloadHandler.php';
require_once __DIR__ . '/Processing/class.ilExUserDataProvider.php';
require_once __DIR__ . '/Processing/class.ilExIndividualMultiFeedbackDownloadHandler.php';

/**
 * Exercise Status File UI Hook
 * 
 * Hauptklasse für UI-Integration mit Team Multi-Feedback
 * Features: Assignment Detection, Team Multi-Feedback, Upload/Download
 * 
 * @author Cornel Musielak
 * @version 1.1.0
 */
class ilExerciseStatusFileUIHookGUI extends ilUIHookPluginGUI
{
    protected ilExerciseStatusFilePlugin $plugin;
    protected ilLogger $logger;

    public function __construct(ilExerciseStatusFilePlugin $plugin)
    {
        $this->plugin = $plugin;

        global $DIC;
        $this->logger = $DIC->logger()->root();
    }

    /**
     * HTML-Hook Processing mit Multi-Feedback Download Support
     */
    public function getHTML(string $a_comp, string $a_part, array $a_par = []): array
    {
        $return = ["mode" => ilUIHookPluginGUI::KEEP, "html" => ""];

        // AJAX-Requests werden bereits in modifyGUI() -> handleAJAXRequests() abgefangen
        // Hier nur noch die Hook-spezifischen Hooks verarbeiten

        if ($a_comp === "Modules/Exercise") {
            switch ($a_part) {
                case "tutor_feedback_download":
                    $this->handleFeedbackDownload($a_par);
                    break;
                case "tutor_feedback_processing":
                    $this->handleFeedbackProcessing($a_par);
                    break;
            }
        }

        return $return;
    }

    /**
     * GUI Modifikation mit AJAX-Support - FIXED VERSION
     */
    public function modifyGUI(string $a_comp, string $a_part, array $a_par = []): void
    {
        try {
            // AJAX-Requests IMMER ZUERST abfangen (vor allen Checks!)
            // AJAX-Requests brauchen kein vollständiges Template
            if ($this->handleAJAXRequests()) {
                return;
            }
            
            global $DIC;
            
            // Prüfe ob DIC vollständig initialisiert ist (nur für UI-Rendering)
            if (!isset($DIC['ilCtrl']) || !isset($DIC['ui.factory'])) {
                // Zu früh im Init-Prozess - ignorieren (aber AJAX wurde schon verarbeitet)
                return;
            }
            
            $ctrl = $DIC->ctrl();
            $class = strtolower($ctrl->getCmdClass());
            $cmd = $ctrl->getCmd();
            
            // Nur in Exercise Management -> Members
            if ($class !== 'ilexercisemanagementgui' || $cmd !== 'members') {
                return;
            }
            
            // Assignment Detection
            $detector = new ilExAssignmentDetector();
            $assignment_id = $detector->detectAssignmentId();

            // Nur rendern wenn Assignment-ID gefunden wurde
            if ($assignment_id === null) {
                $this->logger->debug("No assignment ID found - skipping Multi-Feedback button rendering");
                return;
            }

            // UI-Rendering nur wenn Template verfügbar
            if (isset($DIC['tpl'])) {
                $this->renderUI($assignment_id);
            }
            
        } catch (Exception $e) {
            // Fehler nur loggen, nicht werfen (würde sonst ILIAS-Init unterbrechen)
            $this->logger->error("UI Hook error: " . $e->getMessage());
        }
    }
    
    /**
     * AJAX-Requests verarbeiten
     */
    private function handleAJAXRequests(): bool
    {
        // Accept AJAX GET requests with X-Requested-With header OR plugin_action parameter
        $is_ajax_get = ($_SERVER['REQUEST_METHOD'] === 'GET') &&
                       (
                           (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') ||
                           isset($_GET['plugin_action'])
                       );

        $is_ajax_post = isset($_POST['plugin_action']);

        if (!$is_ajax_get && !$is_ajax_post) {
            return false;
        }
        
        $plugin_action = $_GET['plugin_action'] ?? $_POST['plugin_action'] ?? null;
        
        switch ($plugin_action) {
            case 'get_teams':
                $this->handleGetTeamsRequest();
                return true;

            case 'multi_feedback_download':
                $this->handleMultiFeedbackDownloadRequest();
                return true;

            case 'multi_feedback_upload':
                $this->handleMultiFeedbackUploadRequest();
                return true;

            case 'get_individual_users':
                $this->handleGetIndividualUsersRequest();
                return true;

            case 'multi_feedback_download_individual':
                $this->handleMultiFeedbackDownloadIndividualRequest();
                return true;

            case 'run_integration_tests':
                $this->handleRunIntegrationTestsRequest();
                return true;

            case 'cleanup_test_data':
                $this->handleCleanupTestDataRequest();
                return true;

            default:
                return false;
        }
    }
    
    /**
     * Team-Daten für AJAX-Request
     */
    private function handleGetTeamsRequest(): void
    {
        try {
            $assignment_id = $_GET['ass_id'] ?? $_POST['ass_id'] ?? null;
            
            if (!$assignment_id || !is_numeric($assignment_id)) {
                throw new Exception("Invalid or missing assignment ID");
            }
            
            $team_provider = new ilExTeamDataProvider();
            $team_provider->generateJSONResponse((int)$assignment_id);
            
        } catch (Exception $e) {
            $this->logger->error("Get teams request error: " . $e->getMessage());
            
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');
            
            echo json_encode([
                'error' => true,
                'message' => 'Fehler beim Laden der Team-Daten',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * Multi-Feedback Upload Request Handler
     */
    private function handleMultiFeedbackUploadRequest(): void
    {
        try {
            $assignment_id = $_POST['ass_id'] ?? null;

            if (!$assignment_id || !is_numeric($assignment_id)) {
                throw new Exception("Invalid assignment ID: " . var_export($assignment_id, true));
            }
            
            if (!isset($_FILES['zip_file']) || $_FILES['zip_file']['error'] !== UPLOAD_ERR_OK) {
                $upload_error = $_FILES['zip_file']['error'] ?? 'unknown';
                throw new Exception("No valid ZIP file uploaded. Upload error: " . $upload_error);
            }
            
            $uploaded_file = $_FILES['zip_file'];
            
            // Upload-Handler verwenden
            $upload_handler = new ilExFeedbackUploadHandler();
            $upload_handler->handleFeedbackUpload([
                'assignment_id' => (int)$assignment_id,
                'tutor_id' => $GLOBALS['DIC']->user()->getId(),
                'zip_path' => $uploaded_file['tmp_name']
            ]);

            // Processing Stats und Warnungen abrufen
            $stats = $upload_handler->getProcessingStats();
            $warnings = $upload_handler->getWarnings();

            // Response-Daten zusammenstellen
            $response = [
                'success' => true,
                'message' => 'Multi-Feedback Upload erfolgreich verarbeitet',
                'file' => $uploaded_file['name'],
                'size' => $uploaded_file['size']
            ];

            // Füge Details über umbenannte Dateien hinzu, falls vorhanden
            if (!empty($stats['renamed_files'])) {
                $response['renamed_files'] = $stats['renamed_files'];
                $response['renamed_count'] = $stats['renamed_count'];

                // Erweiterte Message für User
                $renamed_msg = sprintf(
                    $this->plugin->txt('upload_modified_submissions_renamed'),
                    $stats['renamed_count']
                );
                $response['message'] .= ' ' . $renamed_msg;
            }

            // Füge Warnungen hinzu, falls vorhanden
            if (!empty($warnings)) {
                $response['warnings'] = $warnings;
            }

            // Success Response
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
            exit;
            
        } catch (Exception $e) {
            $this->logger->error("Multi-Feedback upload error: " . $e->getMessage());

            // Error Response mit detaillierter Fehlermeldung
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 400 Bad Request');

            echo json_encode([
                'success' => false,
                'error' => true,
                'message' => $e->getMessage(),
                'error_details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * Multi-Feedback Download Request Handler
     */
    private function handleMultiFeedbackDownloadRequest(): void
    {
        try {
            $assignment_id = $_POST['ass_id'] ?? null;
            $team_ids_string = $_POST['team_ids'] ?? '';

            if (!$assignment_id || !is_numeric($assignment_id)) {
                throw new Exception("Ungültige Assignment-ID");
            }

            if (empty($team_ids_string)) {
                throw new Exception("Keine Teams ausgewählt");
            }

            $team_ids = array_map('intval', explode(',', $team_ids_string));
            $team_ids = array_filter($team_ids, function($id) { return $id > 0; });

            if (empty($team_ids)) {
                throw new Exception("Keine gültigen Team-IDs");
            }

            $multi_feedback_handler = new ilExMultiFeedbackDownloadHandler();
            $multi_feedback_handler->generateMultiFeedbackDownload((int)$assignment_id, $team_ids);

        } catch (Exception $e) {
            $this->logger->error("Multi-Feedback download error: " . $e->getMessage());

            // JSON Error Response für AJAX
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');

            echo json_encode([
                'success' => false,
                'message' => 'Fehler beim Multi-Feedback-Download',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    /**
     * UI Rendering - nur wenn Template verfügbar
     */
    private function renderUI(?int $assignment_id): void
    {
        global $DIC;
        
        // Prüfe ob Template verfügbar ist
        if (!isset($DIC['tpl']) || !isset($DIC['ui.factory'])) {
            return;
        }
        
        try {
            $renderer = new ilExTeamButtonRenderer();
            
            // JavaScript-Funktionen registrieren
            $renderer->registerGlobalJavaScriptFunctions();
            $renderer->addCustomCSS();
            
            if ($assignment_id === null) {
                $renderer->renderDebugBox();
                return;
            }
            
            // Assignment-Info prüfen
            $assignment_info = $this->getAssignmentInfo($assignment_id);
            
            if (strpos($assignment_info, '✅ IS TEAM') !== false) {
                // Team Assignment -> Multi-Feedback Button
                $renderer->renderTeamButton($assignment_id);
            } else if (strpos($assignment_info, '❌ NOT TEAM') !== false) {
                // Individual Assignment -> Individual Multi-Feedback Button
                $renderer->renderIndividualButton($assignment_id);
            }

            // Admin-only: Integration Test Button (rendered by renderer)
            $renderer->renderIntegrationTestButton();

        } catch (Exception $e) {
            $this->logger->error("UI rendering error: " . $e->getMessage());
        }
    }
    
    /**
     * Assignment-Info aus Datenbank
     *
     * FIXED: Verwendet jetzt usesTeams() statt hardcoded type == 4
     * um auch Custom Assignment Types (wie ExAutoScore) zu unterstützen
     */
    private function getAssignmentInfo(int $assignment_id): string
    {
        try {
            global $DIC;
            $db = $DIC->database();

            $query = "SELECT exc_id, type FROM exc_assignment WHERE id = " . $db->quote($assignment_id, 'integer');
            $result = $db->query($query);

            if ($result->numRows() > 0) {
                $row = $db->fetchAssoc($result);
                $type = $row['type'];

                // FIXED: Lade Assignment-Objekt und prüfe usesTeams()
                // Dies funktioniert auch mit Custom Assignment Types (z.B. ExAutoScore)
                try {
                    $assignment = new \ilExAssignment($assignment_id);
                    $assignment_type = $assignment->getAssignmentType();
                    $is_team_assignment = $assignment_type->usesTeams();
                } catch (Exception $e) {
                    // Fallback auf Type 4 wenn Assignment-Objekt nicht geladen werden kann
                    $this->logger->warning("Could not load assignment type object, falling back to type check: " . $e->getMessage());
                    $is_team_assignment = ($type == 4);
                }

                $team_status = $is_team_assignment ? "✅ IS TEAM" : "❌ NOT TEAM";

                return "DB OK: type=$type ($team_status)";
            }

            return "DB: Assignment not found";

        } catch (Exception $e) {
            $this->logger->error("Assignment info DB error: " . $e->getMessage());
            return "DB Error";
        }
    }
    
    /**
     * Feedback Download Handler
     */
    protected function handleFeedbackDownload(array $parameters): void
    {
        $handler = new ilExFeedbackDownloadHandler();
        $handler->handleFeedbackDownload($parameters);
    }

    /**
     * Feedback Upload Handler
     */
    protected function handleFeedbackProcessing(array $parameters): void
    {
        $handler = new ilExFeedbackUploadHandler();
        $handler->handleFeedbackUpload($parameters);
    }
    
    /**
     * Plugin-Ressourcen aufräumen
     */
    public function cleanup(): void
    {
        try {
            $renderer = new ilExTeamButtonRenderer();
            $renderer->cleanup();
        } catch (Exception $e) {
            // Ignoriere Cleanup-Fehler
        }
    }
    
    /**
     * Plugin-Status und -Statistiken
     */
    public function getPluginStatus(): array
    {
        $status = [
            'version' => '1.1.0',
            'phase' => 'Complete Team Multi-Feedback',
            'features' => [
                'assignment_detection' => 'Multi-Strategy Detection',
                'team_multi_feedback' => 'Full AJAX + Multi-Feedback Download',
                'multi_feedback_upload' => 'ZIP Upload with Status Processing',
                'ui_rendering' => 'Modular Button Renderer',
                'download_processing' => 'Team + Individual Support',
                'upload_processing' => 'Status File Import/Export'
            ],
            'classes_loaded' => [
                'detector' => class_exists('ilExAssignmentDetector'),
                'button_renderer' => class_exists('ilExTeamButtonRenderer'),
                'download_handler' => class_exists('ilExFeedbackDownloadHandler'),
                'upload_handler' => class_exists('ilExFeedbackUploadHandler'),
                'team_data_provider' => class_exists('ilExTeamDataProvider'),
                'multi_feedback_download_handler' => class_exists('ilExMultiFeedbackDownloadHandler')
            ]
        ];
        
        // Live Assignment Detection Test
        try {
            $detector = new ilExAssignmentDetector();
            $detected_id = $detector->detectAssignmentId();
            $status['current_detection'] = [
                'assignment_id' => $detected_id,
                'detection_stats' => $detector->getDetectionStats()
            ];
        } catch (Exception $e) {
            $status['current_detection'] = [
                'error' => $e->getMessage()
            ];
        }
        
        return $status;
    }
    
    /**
     * Individual-User-Daten für AJAX-Request
     */
    private function handleGetIndividualUsersRequest(): void
    {
        try {
            $assignment_id = $_GET['ass_id'] ?? $_POST['ass_id'] ?? null;
            
            if (!$assignment_id || !is_numeric($assignment_id)) {
                throw new Exception("Invalid or missing assignment ID");
            }
            
            $user_provider = new ilExUserDataProvider();
            $user_provider->generateJSONResponse((int)$assignment_id);
            
        } catch (Exception $e) {
            $this->logger->error("Get individual users request error: " . $e->getMessage());
            
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');
            
            echo json_encode([
                'success' => false,
                'error' => true,
                'message' => 'Fehler beim Laden der User-Daten',
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    /**
     * Multi-Feedback Download Individual Request Handler
     */
    private function handleMultiFeedbackDownloadIndividualRequest(): void
    {
        try {
            $assignment_id = $_POST['ass_id'] ?? null;
            $user_ids_string = $_POST['user_ids'] ?? '';
            
            if (!$assignment_id || !is_numeric($assignment_id)) {
                throw new Exception("Invalid assignment ID");
            }
            
            if (empty($user_ids_string)) {
                throw new Exception("No users selected");
            }
            
            // User-IDs parsen
            $user_ids = array_map('intval', explode(',', $user_ids_string));
            $user_ids = array_filter($user_ids, function($id) { return $id > 0; });
            
            if (empty($user_ids)) {
                throw new Exception("No valid user IDs provided");
            }
            
            // Individual Multi-Feedback Download Handler verwenden
            $individual_handler = new ilExIndividualMultiFeedbackDownloadHandler();
            $individual_handler->generateIndividualMultiFeedbackDownload((int)$assignment_id, $user_ids);
            
        } catch (Exception $e) {
            $this->logger->error("Individual Multi-Feedback download error: " . $e->getMessage());

            // JSON Error Response für AJAX (KEIN Redirect!)
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 400 Bad Request');

            echo json_encode([
                'success' => false,
                'error' => true,
                'message' => "Fehler beim Individual Multi-Feedback-Download: " . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }
    }

    /**
     * Integration Tests Runner (Admin only)
     */
    private function handleRunIntegrationTestsRequest(): void
    {
        global $DIC;

        // Security: Only allow for administrators
        if (!$DIC->rbac()->system()->checkAccess('visible', 9)) { // SYSTEM_FOLDER_ID
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }

        try {
            // Set headers for streaming output
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Accel-Buffering: no');
            ob_implicit_flush(true);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', 0);

            // Note: Template not initialized for test context
            // Tests will create data but skip actual file operations that need template

            // Load test components
            require_once __DIR__ . '/../tests/integration/TestHelper.php';
            require_once __DIR__ . '/../tests/integration/test-runner-core.php';

            echo "═══════════════════════════════════════════════════════\n";
            echo "  Integration Tests - Multi-Feedback Plugin\n";
            echo "═══════════════════════════════════════════════════════\n\n";

            echo "User: " . $DIC->user()->getLogin() . " (ID: " . $DIC->user()->getId() . ")\n";

            // Check if keep_data parameter is set
            $keep_data = isset($_GET['keep_data']) && $_GET['keep_data'] == '1';

            // Get parent_ref_id parameter (default to 1 if not provided)
            $parent_ref_id = isset($_GET['parent_ref_id']) ? (int)$_GET['parent_ref_id'] : 1;

            if ($parent_ref_id < 1) {
                echo "❌ Error: Invalid parent_ref_id. Must be >= 1\n";
                exit;
            }

            // Validate that ref_id exists and user has access
            if (!\ilObject::_exists($parent_ref_id, true)) {
                echo "❌ Error: Ref-ID $parent_ref_id does not exist\n";
                exit;
            }

            // Check if user has create permission for exercises in this location
            if (!$DIC->access()->checkAccess('create', '', $parent_ref_id, 'exc')) {
                echo "❌ Error: No permission to create exercises at Ref-ID $parent_ref_id\n";
                exit;
            }

            $parent_obj_type = \ilObject::_lookupType($parent_ref_id, true);
            $parent_title = \ilObject::_lookupTitle(\ilObject::_lookupObjId($parent_ref_id));

            echo "Parent Ref-ID: $parent_ref_id ($parent_obj_type: '$parent_title')\n";

            if ($keep_data) {
                echo "Mode: 💾 Test-Daten werden NICHT gelöscht\n";
            } else {
                echo "Mode: 🧹 Test-Daten werden nach Tests gelöscht\n";
            }

            echo "Starting tests...\n\n";

            $start_time = microtime(true);

            // Run tests
            $runner = new IntegrationTestRunner($parent_ref_id);
            $runner->runAll($keep_data);

            $duration = round(microtime(true) - $start_time, 2);

            echo "\n═══════════════════════════════════════════════════════\n";
            echo "  Tests completed in {$duration}s\n";
            echo "═══════════════════════════════════════════════════════\n";

            exit;

        } catch (Exception $e) {
            echo "\n\n❌ FATAL ERROR:\n";
            echo $e->getMessage() . "\n\n";
            echo "Stack Trace:\n";
            echo $e->getTraceAsString() . "\n";
            exit;
        }
    }

    /**
     * Handle cleanup of all test data
     */
    private function handleCleanupTestDataRequest(): void
    {
        global $DIC;

        // Security: Only allow for administrators
        if (!$DIC->rbac()->system()->checkAccess('visible', 9)) {
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Insufficient permissions']);
            exit;
        }

        try {
            // Set headers for streaming output
            header('Content-Type: text/plain; charset=utf-8');
            header('X-Accel-Buffering: no');
            ob_implicit_flush(true);
            @ini_set('output_buffering', 'off');
            @ini_set('zlib.output_compression', 0);

            echo "═══════════════════════════════════════════════════════\n";
            echo "  Test-Daten Cleanup\n";
            echo "═══════════════════════════════════════════════════════\n\n";

            echo "Suche nach Test-Daten...\n\n";

            $db = $DIC->database();
            $deleted_exercises = 0;
            $deleted_users = 0;

            // Find and delete test exercises
            echo "🔍 Suche Test-Übungen (mit 'TEST_Exercise' im Namen)...\n";
            $query = "SELECT od.obj_id, oref.ref_id, od.title FROM object_data od
                      JOIN object_reference oref ON od.obj_id = oref.obj_id
                      WHERE od.type = 'exc'
                      AND od.title LIKE '%TEST_Exercise%'
                      AND oref.deleted IS NULL";
            $result = $db->query($query);

            $exercises = [];
            while ($row = $db->fetchAssoc($result)) {
                $exercises[] = $row;
            }

            echo "   → Gefunden: " . count($exercises) . " Test-Übungen\n\n";

            foreach ($exercises as $ex) {
                try {
                    // Delete from object_reference
                    $db->manipulate("DELETE FROM object_reference WHERE ref_id = " . $db->quote($ex['ref_id'], 'integer'));
                    // Delete from object_data
                    $db->manipulate("DELETE FROM object_data WHERE obj_id = " . $db->quote($ex['obj_id'], 'integer'));

                    echo "   ✓ Gelöscht: {$ex['title']} (RefID: {$ex['ref_id']}, ObjID: {$ex['obj_id']})\n";
                    $deleted_exercises++;
                } catch (Exception $e) {
                    echo "   ✗ Fehler bei {$ex['title']}: " . $e->getMessage() . "\n";
                }
            }

            echo "\n";

            // Find and delete test users
            echo "🔍 Suche Test-User (mit 'test_user' im Login)...\n";
            $query = "SELECT usr_id, login, firstname, lastname FROM usr_data
                      WHERE login LIKE '%test_user%'";
            $result = $db->query($query);

            $users = [];
            while ($row = $db->fetchAssoc($result)) {
                $users[] = $row;
            }

            echo "   → Gefunden: " . count($users) . " Test-User\n\n";

            foreach ($users as $user) {
                try {
                    // Delete from usr_data
                    $db->manipulate("DELETE FROM usr_data WHERE usr_id = " . $db->quote($user['usr_id'], 'integer'));
                    // Delete from object_data
                    $db->manipulate("DELETE FROM object_data WHERE obj_id = " . $db->quote($user['usr_id'], 'integer'));

                    echo "   ✓ Gelöscht: {$user['login']} - {$user['firstname']} {$user['lastname']} (ID: {$user['usr_id']})\n";
                    $deleted_users++;
                } catch (Exception $e) {
                    echo "   ✗ Fehler bei {$user['login']}: " . $e->getMessage() . "\n";
                }
            }

            echo "\n═══════════════════════════════════════════════════════\n";
            echo "  Cleanup abgeschlossen!\n";
            echo "═══════════════════════════════════════════════════════\n\n";
            echo "Zusammenfassung:\n";
            echo "  • Übungen gelöscht: $deleted_exercises\n";
            echo "  • User gelöscht: $deleted_users\n";
            echo "═══════════════════════════════════════════════════════\n";

            exit;

        } catch (Exception $e) {
            echo "\n\n❌ FATAL ERROR:\n";
            echo $e->getMessage() . "\n\n";
            echo "Stack Trace:\n";
            echo $e->getTraceAsString() . "\n";
            exit;
        }
    }
}
?>