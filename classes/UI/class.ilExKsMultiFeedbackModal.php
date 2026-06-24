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
     * Build the KitchenSink button + RoundTrip modal for a team assignment.
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
        global $DIC;

        $provider = new ilExTeamDataProvider();
        $teams = $provider->getTeamsForAssignment($assignment_id);
        if (empty($teams)) {
            return [];
        }

        $ui = $DIC->ui();
        $factory = $ui->factory();
        $renderer = $ui->renderer();

        $label = $this->plugin->txt('btn_multi_feedback_ks');

        // Modal body: native POST form -> existing download backend -> ZIP.
        $form_html = $this->buildSelectionForm($assignment_id, $teams);

        $modal = $factory->modal()->roundtrip(
            $label,
            [$factory->legacy($form_html)]
        );

        // Same pattern as ILIAS core (ilExerciseManagementGUI): the button's
        // onClick fires the modal's show signal.
        $button = $factory->button()->standard($label, '#')
            ->withOnClick($modal->getShowSignal());

        return [
            'button' => $renderer->render($button),
            'modal' => $renderer->render($modal),
        ];
    }

    /**
     * Minimal native selection form. This is the only remaining piece of custom
     * markup; in a full rollout it would become a KS-routed form. It is kept
     * native here because the download response is a streamed ZIP.
     */
    private function buildSelectionForm(int $assignment_id, array $teams): string
    {
        $action = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES);
        $intro = htmlspecialchars($this->plugin->txt('team_select_for_download'), ENT_QUOTES);
        $submit_label = htmlspecialchars($this->plugin->txt('btn_start_download'), ENT_QUOTES);

        $rows = '';
        foreach ($teams as $team) {
            $team_id = (int) ($team['team_id'] ?? 0);
            if ($team_id <= 0) {
                continue;
            }

            $names = array_map(
                static fn(array $member): string => (string) ($member['fullname'] ?? ''),
                $team['members'] ?? []
            );
            $names = array_filter($names);

            $label = sprintf(
                'Team %d — %s (%s)',
                $team_id,
                implode(', ', $names),
                (string) ($team['status'] ?? '')
            );

            $rows .= '<div class="form-check" style="margin-bottom:6px;">'
                . '<label style="font-weight:normal;cursor:pointer;">'
                . '<input type="checkbox" name="team_ids[]" value="' . $team_id . '"> '
                . htmlspecialchars($label, ENT_QUOTES)
                . '</label></div>';
        }

        return '<form method="post" action="' . $action . '">'
            . '<input type="hidden" name="plugin_action" value="multi_feedback_download">'
            . '<input type="hidden" name="ass_id" value="' . $assignment_id . '">'
            . '<p>' . $intro . '</p>'
            . '<div style="margin-bottom:16px;">' . $rows . '</div>'
            . '<button type="submit" class="btn btn-primary">' . $submit_label . '</button>'
            . '</form>';
    }
}
