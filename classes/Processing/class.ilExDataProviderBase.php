<?php
declare(strict_types=1);

/**
 * Base Class for Data Providers
 *
 * Enthält gemeinsame Funktionalität für Team- und User-Data-Provider
 *
 * @author Cornel Musielak
 * @version 1.2.0
 */
abstract class ilExDataProviderBase
{
    protected ilLogger $logger;
    protected ilDBInterface $db;
    protected ?ilExerciseStatusFilePlugin $plugin = null;

    /**
     * Gibt den Entity-Typ zurück ('team' oder 'user')
     */
    abstract protected function getEntityType(): string;

    /**
     * Gibt den Error-Key für Übersetzungen zurück
     */
    abstract protected function getErrorLoadingKey(): string;

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
    protected function txt(string $key): string
    {
        if ($this->plugin !== null) {
            return $this->plugin->txt($key);
        }

        // Fallback-Übersetzungen
        $fallbacks = [
            'status_passed' => 'Bestanden',
            'status_failed' => 'Nicht bestanden',
            'status_notgraded' => 'Nicht bewertet',
            'team_error_loading' => 'Fehler beim Laden der Teams',
            'individual_error_loading' => 'Fehler beim Laden der Teilnehmer'
        ];

        return $fallbacks[$key] ?? $key;
    }

    /**
     * Status übersetzen
     */
    protected function translateStatus(?string $status): string
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
    protected function getDefaultStatus(): array
    {
        return [
            'status' => $this->txt('status_notgraded'),
            'mark' => '',
            'notice' => '',
            'comment' => ''
        ];
    }

    /**
     * Batch-Laden von User-Daten (Performance-Optimierung)
     *
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => user_data
     */
    protected function getUserDataBatch(array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        try {
            // 1 Query für ALLE User-IDs statt N einzelne Queries
            $query = "SELECT usr_id, login, firstname, lastname
                      FROM usr_data
                      WHERE " . $this->db->in('usr_id', $user_ids, false, 'integer');

            $result = $this->db->query($query);
            $users = [];

            while ($row = $this->db->fetchAssoc($result)) {
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
            $this->logger->error("Batch loading user data failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * PERFORMANCE: Batch-Laden aller Status-Daten
     *
     * @param int $assignment_id Assignment ID
     * @param array $user_ids Array von User-IDs
     * @param ilExAssignment|null $assignment Assignment-Objekt (optional, für ExAutoScore)
     * @return array Assoziatives Array: user_id => status_data
     */
    protected function getStatusesBatch(int $assignment_id, array $user_ids, ?ilExAssignment $assignment = null): array
    {
        if (empty($user_ids)) {
            return [];
        }

        try {
            // 1 Query für ALLE User-Status statt N einzelne Queries
            // Hinweis: Die Spalte heißt 'u_comment', nicht 'comment'
            $query = "SELECT usr_id, status, mark, notice, u_comment
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
                    'comment' => $row['u_comment'] ?: ''
                ];
            }

            // NEW: Add instant feedback for ExAutoScore assignments
            if ($assignment && $this->isExAutoScoreAssignment($assignment)) {
                // PERFORMANCE: Batch-load ALL instant feedbacks at once
                $instant_feedbacks = $this->getExAutoScoreInstantFeedbackBatch($assignment_id, $user_ids);

                foreach ($user_ids as $user_id) {
                    if (!isset($statuses[$user_id])) {
                        $statuses[$user_id] = $this->getDefaultStatus();
                    }

                    // Use preloaded feedback
                    $instant_feedback = $instant_feedbacks[$user_id] ?? null;

                    if ($instant_feedback !== null) {
                        // If comment already exists, append feedback
                        if (!empty($statuses[$user_id]['comment'])) {
                            $statuses[$user_id]['comment'] .= "\n\n" . $instant_feedback;
                        } else {
                            $statuses[$user_id]['comment'] = $instant_feedback;
                        }
                    }
                }
            }

            return $statuses;

        } catch (Exception $e) {
            $this->logger->error("Batch loading statuses failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Load user data (single user - legacy support)
     */
    protected function getMemberData(int $user_id): ?array
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
     * Send JSON response headers
     */
    protected function sendJSONHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    }

    /**
     * Start gzip compression if possible
     */
    protected function startGzipCompression(): void
    {
        if (!ob_start('ob_gzhandler')) {
            ob_start();
        }
    }

    /**
     * Send JSON error response
     */
    protected function sendJSONErrorResponse(string $message, string $details): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('HTTP/1.1 500 Internal Server Error');

        echo json_encode([
            'success' => false,
            'error' => true,
            'message' => $this->txt($this->getErrorLoadingKey()),
            'details' => $details
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Check if assignment is of type ExAutoScore
     * ExAutoScore Type IDs: 101 (User), 102 (Team)
     */
    protected function isExAutoScoreAssignment(ilExAssignment $assignment): bool
    {
        $type_id = $assignment->getType();
        return in_array($type_id, [101, 102], true);
    }

    /**
     * Check if ExAutoScore plugin is active
     */
    protected function isExAutoScorePluginActive(): bool
    {
        try {
            // Check if plugin is active in il_plugin table (plugin_id = 'exautoscore', active = 1)
            $query = "SELECT active FROM il_plugin WHERE plugin_id = 'exautoscore'";
            $result = $this->db->query($query);

            if ($row = $this->db->fetchAssoc($result)) {
                return (int)$row['active'] === 1;
            }

            return false;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * PERFORMANCE: Batch-load instant feedback for ALL users at once
     * Avoids N+1 query problem
     *
     * @param int $assignment_id Assignment ID
     * @param array $user_ids Array of user IDs
     * @return array Associative array: user_id => feedback_string
     */
    protected function getExAutoScoreInstantFeedbackBatch(int $assignment_id, array $user_ids): array
    {
        $feedbacks = [];

        if (empty($user_ids)) {
            return $feedbacks;
        }

        // Check if ExAutoScore plugin is active
        if (!$this->isExAutoScorePluginActive()) {
            return $feedbacks;
        }

        try {
            // STRATEGY 1: Load all tasks for individual users (user_id match)
            $query_individual = "SELECT user_id, instant_status, instant_message
                                 FROM exautoscore_task
                                 WHERE assignment_id = " . $this->db->quote($assignment_id, 'integer') . "
                                 AND user_id IS NOT NULL
                                 AND " . $this->db->in('user_id', $user_ids, false, 'integer') . "
                                 ORDER BY submit_time DESC";

            $result = $this->db->query($query_individual);
            $user_tasks = [];

            while ($row = $this->db->fetchAssoc($result)) {
                $user_id = (int)$row['user_id'];
                // Only the newest task per user (ORDER BY submit_time DESC)
                if (!isset($user_tasks[$user_id])) {
                    $user_tasks[$user_id] = $row;
                }
            }

            // STRATEGY 2: Load team tasks and map them to all team members
            // Wrapped in try-catch as exc_team may have different name in ILIAS 9
            try {
                $query_teams = "SELECT t.team_id, t.instant_status, t.instant_message, et.user_id_list
                               FROM exautoscore_task t
                               INNER JOIN exc_team et ON t.team_id = et.id
                               WHERE t.assignment_id = " . $this->db->quote($assignment_id, 'integer') . "
                               AND t.team_id IS NOT NULL
                               AND et.ass_id = " . $this->db->quote($assignment_id, 'integer') . "
                               ORDER BY t.submit_time DESC";

                $result = $this->db->query($query_teams);
                $team_tasks = [];

                while ($row = $this->db->fetchAssoc($result)) {
                    $team_id = (int)$row['team_id'];
                    // Only the newest task per team
                    if (!isset($team_tasks[$team_id])) {
                        // Parse user_id_list (comma-separated)
                        $team_user_ids = array_map('intval', explode(',', $row['user_id_list']));

                        foreach ($team_user_ids as $user_id) {
                            // Only if user is in our batch list
                            if (in_array($user_id, $user_ids, true) && !isset($user_tasks[$user_id])) {
                                $user_tasks[$user_id] = $row;
                            }
                        }

                        $team_tasks[$team_id] = true;
                    }
                }
            } catch (Exception $e) {
                // Team query failed (e.g. exc_team doesn't exist) - ignore for individual assignments
                // Silent fail - expected for individual assignments
            }

            // Convert to feedback strings
            foreach ($user_tasks as $user_id => $task_data) {
                $status = $task_data['instant_status'] ?? '';
                $message = $task_data['instant_message'] ?? '';

                $parts = array_filter([$status, $message]);
                if (!empty($parts)) {
                    $feedbacks[$user_id] = implode(': ', $parts);
                }
            }

        } catch (Exception $e) {
            // Silent fail - if ExAutoScore is not installed or query fails
            return [];
        }

        return $feedbacks;
    }
}
