<?php
declare(strict_types=1);

/**
 * Proof of concept: team multi-feedback download UI built entirely from native
 * ILIAS 9 KitchenSink components instead of hand-built HTML/JavaScript.
 *
 * Design rationale (kept deliberately close to ILIAS core):
 *  - The dialog is a genuine KitchenSink RoundTrip modal.
 *  - The trigger is wired exactly like ilExerciseManagementGUI does it:
 *    a KS button whose onClick fires the modal's show signal
 *    (->withOnClick($modal->getShowSignal())). No AJAX, no custom JavaScript.
 *  - Team selection is a minimal native POST form that targets the existing
 *    "multi_feedback_download" backend (ass_id + team_ids). A native form is
 *    required because the backend streams a binary ZIP, which a KitchenSink
 *    async modal submit cannot trigger as a browser download.
 *
 * The rendered HTML (button + modal) is appended right after the exercise
 * toolbar through the "template_get" UIHook, so the KS onLoad bindings run at
 * page render time and ->withOnClick() works natively.
 *
 * @author Cornel Musielak
 */
class ilExKsMultiFeedbackModal
{
    private ilExerciseStatusFilePlugin $plugin;

    public function __construct(ilExerciseStatusFilePlugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /**
     * Build the KitchenSink button + RoundTrip modal for a TEAM assignment.
     *
     * Returns the button and the modal HTML *separately* so the caller can
     * place the button inline as a real toolbar item while the modal overlay
     * (position: fixed) can be appended anywhere. Returns an empty array when
     * there is nothing to show (no teams).
     *
     * @return array{button: string, modal: string}|array{}
     */
    public function renderTeamDownload(int $assignment_id): array
    {
        $teams = (new ilExTeamDataProvider())->getTeamsForAssignment($assignment_id);
        if (empty($teams)) {
            return [];
        }

        return $this->buildButtonAndModal(
            $this->plugin->txt('btn_multi_feedback_ks'),
            $this->plugin->txt('multi_feedback_download_intro'),
            $this->downloadSteps(),
            $this->buildTeamSelectionForm($assignment_id, $teams)
        );
    }

    /**
     * Build the KitchenSink button + RoundTrip modal for an INDIVIDUAL
     * assignment. Mirror of renderTeamDownload(), but with a user selection and
     * the individual download backend.
     *
     * @return array{button: string, modal: string}|array{}
     */
    public function renderIndividualDownload(int $assignment_id): array
    {
        $users = (new ilExUserDataProvider())->getUsersForAssignment($assignment_id);
        if (empty($users)) {
            return [];
        }

        return $this->buildButtonAndModal(
            $this->plugin->txt('btn_multi_feedback_ks'),
            $this->plugin->txt('multi_feedback_download_intro'),
            $this->downloadSteps(),
            $this->buildIndividualSelectionForm($assignment_id, $users)
        );
    }

    /**
     * Build the KitchenSink button + RoundTrip modal for the feedback UPLOAD.
     * Identical for team and individual assignments - the backend decides the
     * handling from the assignment type.
     *
     * @return array{button: string, modal: string}
     */
    public function renderUpload(int $assignment_id): array
    {
        return $this->buildButtonAndModal(
            $this->plugin->txt('btn_multi_feedback_upload_ks'),
            $this->plugin->txt('multi_feedback_upload_intro'),
            [],
            $this->buildUploadForm($assignment_id)
        );
    }

    /**
     * The three workflow steps shown as a native KS ordered listing in the
     * download modal, so tutors see the full round-trip before they start.
     *
     * @return string[]
     */
    private function downloadSteps(): array
    {
        return [
            $this->plugin->txt('multi_feedback_step_download'),
            $this->plugin->txt('multi_feedback_step_edit'),
            $this->plugin->txt('multi_feedback_step_upload'),
        ];
    }

    /**
     * Wrap the given form HTML in a native KitchenSink RoundTrip modal and a
     * button that opens it. Same pattern as ILIAS core (ilExerciseManagementGUI):
     * the button's onClick fires the modal's show signal - no AJAX, no custom JS.
     *
     * The modal leads with native KS guidance (an info message box and, for the
     * download, an ordered list of the round-trip steps) so tutors understand
     * the download -> edit -> upload workflow before they act. The selection /
     * upload form itself stays a legacy() form (streamed ZIP / file upload).
     *
     * @param string[] $steps Optional ordered workflow steps (empty = none).
     * @return array{button: string, modal: string}
     */
    private function buildButtonAndModal(string $label, string $intro, array $steps, string $form_html): array
    {
        global $DIC;

        $ui = $DIC->ui();
        $factory = $ui->factory();
        $renderer = $ui->renderer();

        $content = [];
        if ($intro !== '') {
            $content[] = $factory->messageBox()->info($intro);
        }
        if (!empty($steps)) {
            $content[] = $factory->listing()->ordered($steps);
        }
        $content[] = $factory->divider()->horizontal();
        $content[] = $factory->legacy($form_html);

        $modal = $factory->modal()->roundtrip($label, $content);
        $button = $factory->button()->standard($label, '#')
            ->withOnClick($modal->getShowSignal());

        return [
            'button' => $renderer->render($button),
            'modal' => $renderer->render($modal),
        ];
    }

    private function buildTeamSelectionForm(int $assignment_id, array $teams): string
    {
        $rows = '';
        foreach ($teams as $team) {
            $team_id = (int) ($team['team_id'] ?? 0);
            if ($team_id <= 0) {
                continue;
            }

            $names = array_filter(array_map(
                static fn(array $member): string => (string) ($member['fullname'] ?? ''),
                $team['members'] ?? []
            ));

            $label = sprintf(
                'Team %d — %s (%s)',
                $team_id,
                implode(', ', $names),
                (string) ($team['status'] ?? '')
            );

            $rows .= $this->checkboxRow('team_ids', $team_id, $label);
        }

        return $this->buildForm('multi_feedback_download', $assignment_id, $rows, 'team_select_for_download');
    }

    private function buildIndividualSelectionForm(int $assignment_id, array $users): string
    {
        $rows = '';
        foreach ($users as $user) {
            $user_id = (int) ($user['user_id'] ?? 0);
            if ($user_id <= 0) {
                continue;
            }

            $label = sprintf(
                '%s (%s) — %s',
                (string) ($user['fullname'] ?? ''),
                (string) ($user['login'] ?? ''),
                (string) ($user['status'] ?? '')
            );

            $rows .= $this->checkboxRow('user_ids', $user_id, $label);
        }

        return $this->buildForm('multi_feedback_download_individual', $assignment_id, $rows, 'individual_select_for_download');
    }

    /**
     * Native multipart upload form. Posts the ZIP to the existing
     * multi_feedback_upload backend; the ks_native flag makes the backend
     * answer with a redirect + ILIAS on-screen message instead of JSON.
     */
    private function buildUploadForm(int $assignment_id): string
    {
        $action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES);
        $intro = htmlspecialchars($this->plugin->txt('upload_select_file_desc'), ENT_QUOTES);
        $hint = htmlspecialchars($this->plugin->txt('upload_hint'), ENT_QUOTES);
        $submit_label = htmlspecialchars($this->plugin->txt('btn_start_upload'), ENT_QUOTES);

        return '<form method="post" action="' . $action . '" enctype="multipart/form-data">'
            . '<input type="hidden" name="plugin_action" value="multi_feedback_upload">'
            . '<input type="hidden" name="ks_native" value="1">'
            . '<input type="hidden" name="ass_id" value="' . $assignment_id . '">'
            . '<p>' . $intro . '</p>'
            . '<input type="file" name="zip_file" accept=".zip,application/zip" required style="margin-bottom:12px;">'
            . '<p style="color:#666;font-size:.9em;">' . $hint . '</p>'
            . '<div style="margin-top:8px;"><button type="submit" class="btn btn-primary">' . $submit_label . '</button></div>'
            . '</form>';
    }

    private function checkboxRow(string $field, int $value, string $label): string
    {
        return '<div class="form-check" style="margin-bottom:6px;">'
            . '<label style="font-weight:normal;cursor:pointer;">'
            . '<input type="checkbox" name="' . $field . '[]" value="' . $value . '"> '
            . htmlspecialchars($label, ENT_QUOTES)
            . '</label></div>';
    }

    /**
     * Minimal native selection form. This is the only remaining piece of custom
     * markup; in a full rollout it would become a KS-routed form. It is kept
     * native here because the download response is a streamed ZIP, which a KS
     * async modal submit cannot trigger as a browser download.
     */
    private function buildForm(string $plugin_action, int $assignment_id, string $rows, string $intro_key): string
    {
        $action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES);
        $intro = htmlspecialchars($this->plugin->txt($intro_key), ENT_QUOTES);
        $submit_label = htmlspecialchars($this->plugin->txt('btn_start_download'), ENT_QUOTES);

        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="plugin_action" value="' . htmlspecialchars($plugin_action, ENT_QUOTES) . '">'
            . '<input type="hidden" name="ass_id" value="' . $assignment_id . '">'
            . '<p>' . $intro . '</p>'
            . '<div style="margin-bottom:16px;">' . $rows . '</div>'
            . '<button type="submit" class="btn btn-primary">' . $submit_label . '</button>'
            . '</form>';
    }
}
