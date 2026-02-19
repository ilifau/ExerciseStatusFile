<?php
declare(strict_types=1);

/**
 * Plugin configuration GUI for ExerciseStatusFile
 *
 * Provides a configuration interface in ILIAS administration:
 * Administration → Plugins → ExerciseStatusFile → Configure
 *
 * @ilCtrl_isCalledBy ilExerciseStatusFileConfigGUI: ilObjComponentSettingsGUI
 *
 * @author Cornel Musielak <cornel.musielak@fau.de>
 */
class ilExerciseStatusFileConfigGUI extends ilPluginConfigGUI
{
    protected ilGlobalTemplateInterface $tpl;
    protected ilCtrlInterface $ctrl;
    protected ilLanguage $lng;

    private const SETTING_MODULE = 'exc_status_file';

    public function __construct()
    {
        global $DIC;
        $this->tpl = $DIC->ui()->mainTemplate();
        $this->ctrl = $DIC->ctrl();
        $this->lng = $DIC->language();
    }

    /**
     * Execute command
     */
    public function performCommand(string $cmd): void
    {
        switch ($cmd) {
            case 'configure':
            case 'save':
                $this->$cmd();
                break;
            default:
                $this->configure();
        }
    }

    /**
     * Show configuration form
     */
    protected function configure(): void
    {
        $form = $this->buildForm();
        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Save configuration
     */
    protected function save(): void
    {
        $form = $this->buildForm();

        if ($form->checkInput()) {
            $settings = new ilSetting(self::SETTING_MODULE);

            // Debug-Modus speichern
            $debug_enabled = (bool) $form->getInput('debug_email');
            $settings->set('debug_email_notifications', $debug_enabled ? '1' : '0');

            $this->tpl->setOnScreenMessage('success', $this->lng->txt('saved_successfully'), true);
            $this->ctrl->redirect($this, 'configure');
        }

        $form->setValuesByPost();
        $this->tpl->setContent($form->getHTML());
    }

    /**
     * Build configuration form
     */
    protected function buildForm(): ilPropertyFormGUI
    {
        $form = new ilPropertyFormGUI();
        $form->setFormAction($this->ctrl->getFormAction($this, 'save'));
        $form->setTitle($this->getPluginObject()->txt('plugin_configuration'));

        // Section: E-Mail Notifications
        $section = new ilFormSectionHeaderGUI();
        $section->setTitle($this->getPluginObject()->txt('config_section_notifications'));
        $form->addItem($section);

        // Debug-Modus Checkbox
        $debug = new ilCheckboxInputGUI(
            $this->getPluginObject()->txt('config_debug_email'),
            'debug_email'
        );
        $debug->setInfo($this->getPluginObject()->txt('config_debug_email_info'));

        // Aktuellen Wert laden
        $settings = new ilSetting(self::SETTING_MODULE);
        $current_value = $settings->get('debug_email_notifications', '1'); // Default: true (sicher)
        $debug->setChecked($current_value === '1');

        $form->addItem($debug);

        // Info-Box mit Erklärung (ilCustomInputGUI erlaubt HTML)
        $info = new ilCustomInputGUI($this->getPluginObject()->txt('config_current_status'), '');
        if ($current_value === '1') {
            $info->setHtml(
                '<span style="color: #155724; background: #d4edda; padding: 5px 10px; border-radius: 3px; display: inline-block;">' .
                '✓ ' . $this->getPluginObject()->txt('config_debug_mode_active') .
                '</span>'
            );
        } else {
            $info->setHtml(
                '<span style="color: #856404; background: #fff3cd; padding: 5px 10px; border-radius: 3px; display: inline-block;">' .
                '⚠ ' . $this->getPluginObject()->txt('config_production_mode_active') .
                '</span>'
            );
        }
        $form->addItem($info);

        $form->addCommandButton('save', $this->lng->txt('save'));

        return $form;
    }

    /**
     * Static helper to get debug mode setting
     * Can be called from other classes without instantiating ConfigGUI
     */
    public static function isDebugModeEnabled(): bool
    {
        $settings = new ilSetting(self::SETTING_MODULE);
        $value = $settings->get('debug_email_notifications', '1'); // Default: true (safe)

        return $value === '1';
    }
}
