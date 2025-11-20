<?php
declare(strict_types=1);

/**
 * User Data Provider
 * 
 * Stellt User-Daten für Individual-Assignments bereit (analog zu Team Data Provider)
 * 
 * @author Cornel Musielak
 * @version 1.1.1
 */
class ilExUserDataProvider
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
            'individual_error_loading' => 'Fehler beim Laden der Teilnehmer'
        ];
        
        return $fallbacks[$key] ?? $key;
    }
    
    /**
     * Users für Assignment laden
     */
    public function getUsersForAssignment(int $assignment_id): array
    {
        try {
            $assignment = new \ilExAssignment($assignment_id);

            // Nur für Individual-Assignments
            if ($assignment->getAssignmentType()->usesTeams()) {
                return [];
            }

            $exercise_id = $assignment->getExerciseId();

            // Sammle alle User-IDs
            $query = "SELECT usr_id FROM exc_members WHERE obj_id = " .
                     $this->db->quote($exercise_id, 'integer');
            $result = $this->db->query($query);

            $user_ids = [];
            while ($row = $this->db->fetchAssoc($result)) {
                $user_ids[] = (int)$row['usr_id'];
            }

            if (empty($user_ids)) {
                return [];
            }

            // PERFORMANCE: Batch-Load aller User-Daten
            $users_data_map = $this->getUserDataBatch($user_ids);

            // PERFORMANCE: Batch-Check aller Submissions
            $submissions_map = $this->checkSubmissionsExistBatch($assignment_id, $user_ids);

            // PERFORMANCE: Batch-Load aller User-Status
            $statuses_map = $this->getUserStatusesBatch($assignment_id, $user_ids);

            // Baue finale User-Daten
            $users_data = [];
            foreach ($user_ids as $user_id) {
                $user_data = $this->buildUserDataOptimized(
                    $user_id,
                    $users_data_map[$user_id] ?? null,
                    $statuses_map[$user_id] ?? null,
                    $submissions_map[$user_id] ?? false
                );

                if ($user_data) {
                    $users_data[] = $user_data;
                }
            }

            // Sortieren nach Nachname
            usort($users_data, function($a, $b) {
                return strcmp($a['lastname'], $b['lastname']);
            });

            return $users_data;

        } catch (Exception $e) {
            $this->logger->error("User data provider error: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * User-Daten erstellen (mit robusterem Submission-Check)
     */
    private function buildUserData(int $user_id, \ilExAssignment $assignment): ?array
    {
        try {
            // User-Daten laden
            $user_data = \ilObjUser::_lookupName($user_id);
            if (!$user_data || !$user_data['login']) {
                return null;
            }
            
            // Status ermitteln
            $user_status = $this->getUserStatus($user_id, $assignment);
            
            // Submission prüfen - VERBESSERTE VERSION
            $has_submission = $this->checkSubmissionExists($user_id, $assignment);
            
            return [
                'user_id' => $user_id,
                'login' => $user_data['login'],
                'firstname' => $user_data['firstname'],
                'lastname' => $user_data['lastname'],
                'fullname' => trim($user_data['firstname'] . ' ' . $user_data['lastname']),
                'status' => $user_status['status'],
                'mark' => $user_status['mark'],
                'notice' => $user_status['notice'],
                'comment' => $user_status['comment'],
                'has_submission' => $has_submission
            ];
            
        } catch (Exception $e) {
            $this->logger->error("Error building user data for user $user_id: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * PERFORMANCE: Batch-Laden von User-Daten (analog zu TeamDataProvider)
     *
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => user_data
     */
    private function getUserDataBatch(array $user_ids): array
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
     * PERFORMANCE: Batch-Check ob User Submissions haben
     *
     * @param int $assignment_id Assignment ID
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => bool (true = hat Submission)
     */
    private function checkSubmissionsExistBatch(int $assignment_id, array $user_ids): array
    {
        if (empty($user_ids)) {
            return [];
        }

        try {
            // 1 Query statt 300+ einzelne Queries!
            $query = "SELECT user_id, COUNT(*) as cnt
                      FROM exc_returned
                      WHERE ass_id = " . $this->db->quote($assignment_id, 'integer') . "
                      AND " . $this->db->in('user_id', $user_ids, false, 'integer') . "
                      GROUP BY user_id";

            $result = $this->db->query($query);
            $submissions = [];

            // Initialisiere alle User mit false
            foreach ($user_ids as $user_id) {
                $submissions[$user_id] = false;
            }

            // Setze true für User mit Submissions
            while ($row = $this->db->fetchAssoc($result)) {
                $user_id = (int)$row['user_id'];
                $submissions[$user_id] = ((int)$row['cnt'] > 0);
            }

            return $submissions;

        } catch (Exception $e) {
            $this->logger->error("Batch checking submissions failed: " . $e->getMessage());

            // Fallback: alle auf false
            $submissions = [];
            foreach ($user_ids as $user_id) {
                $submissions[$user_id] = false;
            }
            return $submissions;
        }
    }

    /**
     * PERFORMANCE: Batch-Laden aller User-Status
     *
     * @param int $assignment_id Assignment ID
     * @param array $user_ids Array von User-IDs
     * @return array Assoziatives Array: user_id => status_data
     */
    private function getUserStatusesBatch(int $assignment_id, array $user_ids): array
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
            $this->logger->error("Batch loading user statuses failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * PERFORMANCE: User-Daten mit vorgeladenen Daten erstellen
     *
     * @param int $user_id User ID
     * @param array|null $user_data Vorgeladene User-Daten
     * @param array|null $status_data Vorgeladene Status-Daten
     * @param bool $has_submission Ob User eine Submission hat
     * @return array|null User-Daten oder null bei Fehler
     */
    private function buildUserDataOptimized(
        int $user_id,
        ?array $user_data,
        ?array $status_data,
        bool $has_submission
    ): ?array {
        try {
            // User-Daten validieren
            if (!$user_data || !isset($user_data['login'])) {
                return null;
            }

            // Status-Daten mit Fallback
            if (!$status_data) {
                $status_data = $this->getDefaultStatus();
            }

            return [
                'user_id' => $user_id,
                'login' => $user_data['login'],
                'firstname' => $user_data['firstname'],
                'lastname' => $user_data['lastname'],
                'fullname' => $user_data['fullname'],
                'status' => $status_data['status'],
                'mark' => $status_data['mark'],
                'notice' => $status_data['notice'],
                'comment' => $status_data['comment'],
                'has_submission' => $has_submission
            ];

        } catch (Exception $e) {
            $this->logger->error("Error building optimized user data for user $user_id: " . $e->getMessage());
            return null;
        }
    }

    /**
     * User-Status ermitteln
     */
    private function getUserStatus(int $user_id, \ilExAssignment $assignment): array
    {
        try {
            $member_status = $assignment->getMemberStatus($user_id);

            if ($member_status) {
                return [
                    'status' => $this->translateStatus($member_status->getStatus()),
                    'mark' => $member_status->getMark() ?: '',
                    'notice' => $member_status->getNotice() ?: '',
                    'comment' => $member_status->getComment() ?: ''
                ];
            }

            return $this->getDefaultStatus();

        } catch (Exception $e) {
            $this->logger->error("Error getting user status: " . $e->getMessage());
            return $this->getDefaultStatus();
        }
    }
    
    /**
     * Prüfen ob User eine Submission hat - NEUE ROBUSTE METHODE
     */
    private function checkSubmissionExists(int $user_id, \ilExAssignment $assignment): bool
    {
        try {
            $assignment_id = $assignment->getId();
            
            // Methode 1: Direkter DB-Check in exc_returned (zuverlässigste Methode)
            $query = "SELECT COUNT(*) as cnt FROM exc_returned 
                      WHERE ass_id = " . $this->db->quote($assignment_id, 'integer') . " 
                      AND user_id = " . $this->db->quote($user_id, 'integer');
            
            $result = $this->db->query($query);
            if ($row = $this->db->fetchAssoc($result)) {
                if ((int)$row['cnt'] > 0) {
                    return true;
                }
            }
            
            // Methode 2: Check über ilExSubmission Objekt
            try {
                $submission = new \ilExSubmission($assignment, $user_id);
                
                if ($submission && $submission->hasSubmitted()) {
                    return true;
                }
                
                // Prüfe auch Files
                $files = $submission->getFiles();
                if (!empty($files) && is_array($files) && count($files) > 0) {
                    return true;
                }
            } catch (Exception $e) {
                // Submission-Objekt konnte nicht erstellt werden - ignorieren
            }
            
            // Methode 3: Check über MemberStatus
            try {
                $member_status = $assignment->getMemberStatus($user_id);
                if ($member_status) {
                    if ($member_status->getReturned()) {
                        return true;
                    }
                }
            } catch (Exception $e) {
                // MemberStatus konnte nicht geladen werden - ignorieren
            }
            
            return false;
            
        } catch (Exception $e) {
            $this->logger->error("Error checking submission for user $user_id in assignment {$assignment->getId()}: " . $e->getMessage());
            return false;
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
            $users_data = $this->getUsersForAssignment($assignment_id);

            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, must-revalidate');
            header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

            // Enable Gzip compression for faster transfer
            if (!ob_start('ob_gzhandler')) {
                ob_start();
            }

            echo json_encode([
                'success' => true,
                'users' => $users_data
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            ob_end_flush();
            exit;
            
        } catch (Exception $e) {
            $this->logger->error("Error generating JSON response: " . $e->getMessage());
            
            header('Content-Type: application/json; charset=utf-8');
            header('HTTP/1.1 500 Internal Server Error');
            
            echo json_encode([
                'success' => false,
                'error' => true,
                'message' => $this->txt('individual_error_loading'),
                'details' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
?>