<?php
declare(strict_types=1);

/**
 * Feedback Notification Sender
 *
 * This class is loaded via lazy loading to prevent command routing conflicts.
 * It encapsulates the entire email notification logic.
 */
class ilExFeedbackNotificationSender
{
    private ilLogger $logger;
    /** @var array Static to prevent duplicate notifications across multiple instances */
    private static array $notified_users = [];

    public function __construct()
    {
        global $DIC;
        $this->logger = $DIC->logger()->root();
    }

    /**
     * Sends feedback notification to users
     *
     * @param int $assignment_id Assignment ID
     * @param int $user_id User ID (for teams: one team member)
     * @param bool $is_team Is this a team assignment?
     * @return array Statistics with 'sent' and 'skipped' counters
     */
    public function sendNotification(int $assignment_id, int $user_id, bool $is_team): array
    {
        $stats = ['sent' => 0, 'skipped' => 0];

        try {
            global $DIC;

            // Read debug mode from plugin constant (with fallback)
            $debug_mode = false;
            if (class_exists('ilExerciseStatusFilePlugin')) {
                $debug_mode = defined('ilExerciseStatusFilePlugin::DEBUG_EMAIL_NOTIFICATIONS')
                    ? ilExerciseStatusFilePlugin::DEBUG_EMAIL_NOTIFICATIONS
                    : false;
            }

            // Prevent duplicates
            if (isset(self::$notified_users[$assignment_id][$user_id])) {
                $stats['skipped']++;
                return $stats;
            }

            // Load assignment
            $assignment = new \ilExAssignment($assignment_id);
            $exc_id = $assignment->getExerciseId();
            $assignment_title = $assignment->getTitle();

            // Load exercise object
            $exc_refs = \ilObject::_getAllReferences($exc_id);
            $exc_ref_id = reset($exc_refs);

            if (!$exc_ref_id) {
                $this->logger->warning("Could not find ref_id for exercise $exc_id - notification skipped");
                $stats['skipped']++;
                return $stats;
            }

            // Exercise title for debug output
            $exc_title = \ilObject::_lookupTitle($exc_id);

            // Determine all affected user IDs (for teams: all members)
            $submission = new \ilExSubmission($assignment, $user_id);
            $recipient_ids = $submission->getUserIds();

            if (empty($recipient_ids)) {
                $this->logger->warning("No recipients found for notification (assignment=$assignment_id, user=$user_id)");
                $stats['skipped']++;
                return $stats;
            }

            $recipient_count = count($recipient_ids);

            // Debug mode: Only logs, no real emails
            if ($debug_mode) {
                $this->logger->info("DEBUG MODE: E-Mail notification suppressed for assignment '$assignment_title' (ID: $assignment_id)");
                $this->logger->info("DEBUG: Would notify " . $recipient_count . " user(s): " . implode(', ', $recipient_ids));
                $this->logger->info("DEBUG: Exercise: '$exc_title' (ID: $exc_id, Ref: $exc_ref_id), Team: " . ($is_team ? 'Yes' : 'No'));

                $stats['sent'] += $recipient_count;

                // Mark all as notified (prevents duplicates)
                foreach ($recipient_ids as $uid) {
                    self::$notified_users[$assignment_id][$uid] = true;
                }

                return $stats;
            }

            // ILIAS checks internally if notifications are enabled
            try {
                $notification_manager = $DIC->exercise()->internal()->domain()->notification($exc_ref_id);

                foreach ($recipient_ids as $recipient_id) {
                    // Prevent duplicates
                    if (isset(self::$notified_users[$assignment_id][$recipient_id])) {
                        $stats['skipped']++;
                        continue;
                    }

                    try {
                        // Send feedback notification (requires array of user IDs)
                        $notification_manager->sendFeedbackNotification($assignment_id, [$recipient_id]);

                        self::$notified_users[$assignment_id][$recipient_id] = true;
                        $stats['sent']++;

                    } catch (Exception $e) {
                        $this->logger->error("Failed to send notification to user $recipient_id: " . $e->getMessage());
                        $stats['skipped']++;
                    }
                }

                if ($stats['sent'] > 0) {
                    $this->logger->info("Sent {$stats['sent']} feedback notification(s) for assignment '$assignment_title'");
                }

            } catch (Exception $e) {
                $this->logger->error("Error accessing NotificationManager: " . $e->getMessage());
                $stats['skipped'] += count($recipient_ids);
            }

        } catch (Exception $e) {
            $this->logger->error("Error in sendNotification: " . $e->getMessage());
            $stats['skipped']++;
        }

        return $stats;
    }

}
