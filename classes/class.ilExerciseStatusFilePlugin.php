<?php
declare(strict_types=1);

class ilExerciseStatusFilePlugin extends ilUserInterfaceHookPlugin
{
    const PLUGIN_ID = "exstatusfile";
    const PLUGIN_NAME = "ExerciseStatusFile";

    /**
     * Debug-Modus für E-Mail-Benachrichtigungen
     *
     * true = Debug-Modus aktiv (nur Logs, keine echten E-Mails)
     * false = Produktiv-Modus (echte E-Mails werden verschickt)
     *
     * Debug-Infos sind nur für Admins sichtbar
     */
    const DEBUG_EMAIL_NOTIFICATIONS = false;

    public function getPluginName(): string
    {
        return self::PLUGIN_NAME;
    }

    public function getPluginDirectory(): string
    {
        return "ExerciseStatusFile";
    }

    protected function init(): void
    {
        parent::init();
    }

    public function getUIClassInstance(): ilExerciseStatusFileUIHookGUI
    {
        return new ilExerciseStatusFileUIHookGUI($this);
    }
    
    public function isActive(): bool
    {
        return parent::isActive();
    }

    /**
     * Dependency Checks vor Plugin-Aktivierung
     */
    protected function beforeActivation(): bool
    {
        global $DIC;
        $logger = $DIC->logger()->root();
        
        $required_classes = [
            'ilExAssignment' => 'Exercise Assignment Core',
            'ilExcel' => 'Excel Processing',
            'ilExAssignmentMemberStatus' => 'Assignment Member Status',
            'ilExAssignmentTeam' => 'Team Assignment Support',
            'ilObjExercise' => 'Exercise Object',
            'ilExerciseException' => 'Exercise Exception Handling'
        ];
        
        $missing_classes = [];
        
        foreach ($required_classes as $class_name => $description) {
            if (!class_exists($class_name)) {
                $missing_classes[] = "$class_name ($description)";
                $logger->error("Plugin dependency check failed: Missing class $class_name");
            }
        }
        
        if (!empty($missing_classes)) {
            $logger->error("Plugin activation failed - missing dependencies: " . 
                          implode(', ', $missing_classes));
            return false;
        }
        
        // Zusätzliche System-Prüfungen
        try {
            // ZipArchive verfügbar?
            if (!class_exists('ZipArchive')) {
                $logger->error("Plugin activation failed: ZipArchive extension not available");
                return false;
            }
            
            // PhpSpreadsheet verfügbar? (Optional)
            if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
                $logger->warning("PhpSpreadsheet not available - Excel support limited");
            }
            
            // Temp-Directory-Zugriff
            $temp_test = sys_get_temp_dir() . '/plugin_test_' . uniqid();
            if (!@mkdir($temp_test, 0777, true)) {
                $logger->error("Plugin activation failed: Cannot create temporary directories");
                return false;
            }
            @rmdir($temp_test);
            
            return true;
            
        } catch (Exception $e) {
            $logger->error("Plugin dependency check error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Nach erfolgreicher Aktivierung
     */
    protected function afterActivation(): void
    {
        global $DIC;
        $logger = $DIC->logger()->root();
        $logger->info("ExerciseStatusFile Plugin v1.1.0 activated successfully");
    }

    /**
     * Nach Deaktivierung - Cleanup
     */
    protected function afterDeactivation(): void
    {
        global $DIC;
        $logger = $DIC->logger()->root();
        
        // Session-Cleanup
        if (isset($_SESSION['exc_status_files_processed'])) {
            unset($_SESSION['exc_status_files_processed']);
        }
        
        $logger->info("ExerciseStatusFile Plugin deactivated and cleaned up");
    }
}