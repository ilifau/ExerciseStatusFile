<?php
declare(strict_types=1);

require_once __DIR__ . '/class.ilExDataProviderBase.php';

/**
 * Team Data Provider
 *
 * Stellt Team-Daten für AJAX-Requests bereit
 * Verwendet nur stabile ILIAS-APIs
 *
 * @author Cornel Musielak
 * @version 1.2.0
 */
class ilExTeamDataProvider extends ilExDataProviderBase
{
    protected function getEntityType(): string
    {
        return 'team';
    }

    protected function getErrorLoadingKey(): string
    {
        return 'team_error_loading';
    }

    /**
     * Teams für Assignment laden
     */
    public function getTeamsForAssignment(int $assignment_id): array
    {
        try {
            $assignment = new \ilExAssignment($assignment_id);
            if (!$assignment->getAssignmentType()->usesTeams()) {
                return [];
            }

            $teams = ilExAssignmentTeam::getInstancesFromMap($assignment_id);
            if (empty($teams)) {
                return [];
            }

            // PERFORMANCE: Sammle zuerst alle User-IDs aus allen Teams
            $all_user_ids = [];
            foreach ($teams as $team) {
                $member_ids = $team->getMembers();
                $all_user_ids = array_merge($all_user_ids, $member_ids);
            }
            $all_user_ids = array_unique($all_user_ids);

            // PERFORMANCE: Lade alle User-Daten in 1 Query statt N Queries
            $users_data_map = $this->getUserDataBatch($all_user_ids);

            // PERFORMANCE: Lade alle Status-Daten in 1 Query statt N Queries
            $statuses_map = $this->getStatusesBatch($assignment_id, $all_user_ids);

            // Baue Team-Daten mit vorbereiteten User-Daten
            $teams_data = [];
            foreach ($teams as $team_id => $team) {
                $team_data = $this->buildTeamDataOptimized($team, $assignment, $users_data_map, $statuses_map);
                if ($team_data) {
                    $teams_data[] = $team_data;
                }
            }

            return $teams_data;

        } catch (Exception $e) {
            $this->logger->error("Team data provider error: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Team-Daten erstellen (optimiert mit vorgeladenen User-Daten)
     *
     * @param array $users_data_map Vorgeladene User-Daten (user_id => data)
     * @param array $statuses_map Vorgeladene Status-Daten (user_id => status)
     */
    private function buildTeamDataOptimized(ilExAssignmentTeam $team, \ilExAssignment $assignment, array $users_data_map, array $statuses_map = []): ?array
    {
        try {
            $team_id = $team->getId();
            $member_ids = $team->getMembers();

            if (empty($member_ids)) {
                return null;
            }

            // PERFORMANCE: Nutze vorgeladene User-Daten statt einzelner Queries
            $members_data = [];
            foreach ($member_ids as $user_id) {
                if (isset($users_data_map[$user_id])) {
                    $members_data[] = $users_data_map[$user_id];
                }
            }

            if (empty($members_data)) {
                return null;
            }

            // PERFORMANCE: Nutze vorgeladene Status-Daten wenn verfügbar
            $first_member_id = reset($member_ids);
            if (!empty($statuses_map) && isset($statuses_map[$first_member_id])) {
                $team_status = $statuses_map[$first_member_id];
            } else {
                // Fallback: Lade einzeln (Legacy)
                $team_status = $this->getTeamStatus($team, $assignment);
            }

            return [
                'team_id' => $team_id,
                'member_count' => count($members_data),
                'members' => $members_data,
                'status' => $team_status['status'],
                'mark' => $team_status['mark'],
                'notice' => $team_status['notice'],
                'comment' => $team_status['comment'],
                'last_submission' => null,
                'has_submissions' => false
            ];

        } catch (Exception $e) {
            $this->logger->error("Error building team data for team " . $team->getId() . ": " . $e->getMessage());
            return null;
        }
    }

    /**
     * Team-Daten erstellen (Legacy - ohne Batch-Loading)
     */
    private function buildTeamData(ilExAssignmentTeam $team, \ilExAssignment $assignment): ?array
    {
        try {
            $team_id = $team->getId();
            $member_ids = $team->getMembers();

            if (empty($member_ids)) {
                return null;
            }

            $members_data = [];
            foreach ($member_ids as $user_id) {
                $member_data = $this->getMemberData($user_id);
                if ($member_data) {
                    $members_data[] = $member_data;
                }
            }

            if (empty($members_data)) {
                return null;
            }

            $team_status = $this->getTeamStatus($team, $assignment);

            return [
                'team_id' => $team_id,
                'member_count' => count($members_data),
                'members' => $members_data,
                'status' => $team_status['status'],
                'mark' => $team_status['mark'],
                'notice' => $team_status['notice'],
                'comment' => $team_status['comment'],
                'last_submission' => null,
                'has_submissions' => false
            ];

        } catch (Exception $e) {
            $this->logger->error("Error building team data for team " . $team->getId() . ": " . $e->getMessage());
            return null;
        }
    }

    /**
     * Team-Status ermitteln
     */
    private function getTeamStatus(ilExAssignmentTeam $team, \ilExAssignment $assignment): array
    {
        try {
            $member_ids = $team->getMembers();
            if (empty($member_ids)) {
                return $this->getDefaultStatus();
            }

            $first_member_id = reset($member_ids);

            try {
                $member_status = $assignment->getMemberStatus($first_member_id);

                if ($member_status) {
                    return [
                        'status' => $this->translateStatus($member_status->getStatus()),
                        'mark' => $member_status->getMark() ?: '',
                        'notice' => $member_status->getNotice() ?: '',
                        'comment' => $member_status->getComment() ?: ''
                    ];
                }
            } catch (Exception $e) {
                // Fallback bei Problemen
            }

            return $this->getDefaultStatus();

        } catch (Exception $e) {
            $this->logger->error("Error getting team status: " . $e->getMessage());
            return $this->getDefaultStatus();
        }
    }

    /**
     * JSON-Response für AJAX generieren
     */
    public function generateJSONResponse(int $assignment_id): void
    {
        try {
            $teams_data = $this->getTeamsForAssignment($assignment_id);

            $this->sendJSONHeaders();
            $this->startGzipCompression();

            echo json_encode($teams_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ob_end_flush();
            exit;

        } catch (Exception $e) {
            $this->logger->error("Error generating JSON response: " . $e->getMessage());
            $this->sendJSONErrorResponse($this->txt('team_error_loading'), $e->getMessage());
        }
    }

    /**
     * Team-Daten für Debugging
     */
    public function getTeamsDebugInfo(int $assignment_id): array
    {
        return [
            'assignment_id' => $assignment_id,
            'teams_count' => count($this->getTeamsForAssignment($assignment_id)),
            'teams_data' => $this->getTeamsForAssignment($assignment_id),
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.2.0'
        ];
    }
}
?>
