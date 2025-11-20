<?php
declare(strict_types=1);

/**
 * Team Data Provider
 * 
 * Stellt Team-Daten für AJAX-Requests bereit
 * Verwendet nur stabile ILIAS-APIs
 * 
 * @author Cornel Musielak
 * @version 1.1.1
 */
class ilExTeamDataProvider
{
    private ilLogger $logger;
    private ilDBInterface $db;
    private ?ilExerciseStatusFilePlugin $plugin = null;
    
    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
        $this->db = $DIC->database();
        
        // Plugin-Instanz für Übersetzungen - mit Fallback
        try {
            $plugin_id = 'exstatusfile';
            $repo = $DIC['component.repository'];
            $factory = $DIC['component.factory'];

            $info = $repo->getPluginById($plugin_id);
            if ($info !== null && $info->isActive()) {
                $this->plugin = $factory->getPlugin($plugin_id);
            }
        } catch (Exception $e) {
            $this->logger->warning("Could not load plugin for translations: " . $e->getMessage());
            $this->plugin = null;
        }
    }
    
    /**
     * Übersetzung mit Fallback
     */
    private function txt(string $key): string
    {
        if ($this->plugin !== null) {
            return $this->plugin->txt($key);
        }
        
        // Fallback-Übersetzungen
        $fallbacks = [
            'status_passed' => 'Bestanden',
            'status_failed' => 'Nicht bestanden',
            'status_notgraded' => 'Nicht bewertet',
            'team_error_loading' => 'Fehler beim Laden der Teams'
        ];
        
        return $fallbacks[$key] ?? $key;
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
            $users_data_map = $this->getMemberDataBatch($all_user_ids);

            // PERFORMANCE: Lade alle Status-Daten in 1 Query statt N Queries
            $statuses_map = $this->getTeamStatusesBatch($assignment_id, $all_user_ids);

            // Baue Team-Daten mit vorbereiten User-Daten
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
     * Batch-Laden von Mitglieder-Daten (Performance-Optimierung)
     *
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => user_data
     */
    private function getMemberDataBatch(array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        try {
            global $DIC;
            $db = $DIC->database();

            // 1 Query für ALLE User-IDs statt N einzelne Queries
            $query = "SELECT usr_id, login, firstname, lastname
                      FROM usr_data
                      WHERE " . $db->in('usr_id', $user_ids, false, 'integer');

            $result = $db->query($query);
            $users = [];

            while ($row = $db->fetchAssoc($result)) {
                $user_id = (int)$row['usr_id'];
                $users[$user_id] = [
                    'user_id' => $user_id,
                    'login' => $row['login'],
                    'firstname' => $row['firstname'],
                    'lastname' => $row['lastname'],
                    'fullname' => trim($row['firstname'] . ' ' . $row['lastname'])
                ];
            }

            return $users;

        } catch (Exception $e) {
            $this->logger->error("Batch loading member data failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * PERFORMANCE: Batch-Laden aller Team-Status
     *
     * @param int $assignment_id Assignment ID
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => status_data
     */
    private function getTeamStatusesBatch(int $assignment_id, array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        try {
            // 1 Query für ALLE User-Status statt N einzelne Queries
            $query = "SELECT usr_id, status, mark, notice, comment
                      FROM exc_mem_ass_status
                      WHERE ass_id = " . $this->db->quote($assignment_id, 'integer') . "
                      AND " . $this->db->in('usr_id', $user_ids, false, 'integer');

            $result = $this->db->query($query);
            $statuses = [];

            while ($row = $this->db->fetchAssoc($result)) {
                $user_id = (int)$row['usr_id'];
                $statuses[$user_id] = [
                    'status' => $this->translateStatus($row['status'] ?? null),
                    'mark' => $row['mark'] ?: '',
                    'notice' => $row['notice'] ?: '',
                    'comment' => $row['comment'] ?: ''
                ];
            }

            return $statuses;

        } catch (Exception $e) {
            $this->logger->error("Batch loading team statuses failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Mitglieder-Daten laden (Einzelner User - Legacy-Support)
     */
    private function getMemberData(int $user_id): ?array
    {
        try {
            $user_data = \ilObjUser::_lookupName($user_id);
            if (!$user_data || !$user_data['login']) {
                return null;
            }

            return [
                'user_id' => $user_id,
                'login' => $user_data['login'],
                'firstname' => $user_data['firstname'],
                'lastname' => $user_data['lastname'],
                'fullname' => trim($user_data['firstname'] . ' ' . $user_data['lastname'])
            ];

        } catch (Exception $e) {
            $this->logger->error("Error loading member data for user $user_id: " . $e->getMessage());
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
     * Status übersetzen
     */
    private function translateStatus(?string $status): string
    {
        switch ($status) {
            case 'passed':
                return $this->txt('status_passed');
            case 'failed':
                return $this->txt('status_failed');
            case 'notgraded':
            default:
                return $this->txt('status_notgraded');
        }
    }
    
    /**
     * Standard-Status
     */
    private function getDefaultStatus(): array
    {
        return [
            'status' => $this->txt('status_notgraded'),
            'mark' => '',
            'notice' => '',
            'comment' => ''
        ];
    }
    
    /**
     * JSON-Response für AJAX generieren
     */
    public function generateJSONResponse(int $assignment_id): void
    {
        try {
            $teams_data = $this->getTeamsForAssignment($assignment_id);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

            // Enable Gzip compression for faster transfer
            if (!ob_start('ob_gzhandler')) {
                ob_start();
            }

            echo json_encode($teams_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ob_end_flush();
            exit;
            
        } catch (Exception $e) {
            $this->logger->error("Error generating JSON response: " . $e->getMessage());
            
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');
            
            echo json_encode([
                'error' => true,
                'message' => $this->txt('team_error_loading'),
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
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
            'version' => '1.1.1'
        ];
    }
}
?>