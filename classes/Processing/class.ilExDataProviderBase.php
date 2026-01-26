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
     * @return array Assoziatives Array: user_id => status_data
     */
    protected function getStatusesBatch(int $assignment_id, array $user_ids): array
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
            $this->logger->error("Batch loading statuses failed: " . $e->getMessage());
            return [];
        }
    }

    /**
     * User-Daten laden (Einzelner User - Legacy-Support)
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
     * Sendet JSON-Response-Header
     */
    protected function sendJSONHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    }

    /**
     * Startet Gzip-Kompression wenn möglich
     */
    protected function startGzipCompression(): void
    {
        if (!ob_start('ob_gzhandler')) {
            ob_start();
        }
    }

    /**
     * Sendet JSON-Error-Response
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
}
?>
