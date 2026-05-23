<?php
/**
 * Cron: Trial Expiry Reminders
 * Schedule: Daily at 8:00 AM
 * cPanel: 0 8 * * * php /home/USERNAME/public_html/shanfix/cron/trial-expiry.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$sent   = 0;
$errors = 0;

echo date('Y-m-d H:i:s') . " — trial-expiry.php starting...\n";

// ── Dedup helpers ─────────────────────────────────────────────────
function trialAlreadySent(PDO $pdo, string $eventType, int $subId, string $periodDate): bool
{
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type=? AND reference_id=? AND period_date=?");
        $s->execute([$eventType, $subId, $periodDate]);
        return (bool)$s->fetch();
    } catch (Exception $e) {
        return false; // table may not exist yet
    }
}

function trialMarkSent(PDO $pdo, string $eventType, int $subId, string $periodDate): void
{
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type, reference_id, period_date) VALUES (?,?,?)")
            ->execute([$eventType, $subId, $periodDate]);
    } catch (Exception $e) {}
}

// ── Send trial reminders at 1, 3, and 7 days before trial_ends_at ─
$targetDays = [7, 3, 1];

foreach ($targetDays as $days) {
    $targetDate = date('Y-m-d', strtotime("+{$days} days"));
    $eventType  = 'trial_reminder_' . $days . 'd';

    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.org_id, s.trial_ends_at,
                   o.name AS org_name,
                   o.email AS org_email,
                   u.name AS admin_name
            FROM subscriptions s
            JOIN organizations o ON s.org_id = o.id
            JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
            WHERE DATE(s.trial_ends_at) = ?
              AND s.status = 'trial'
            LIMIT 200
        ");
        $stmt->execute([$targetDate]);
        $subs = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('[trial-expiry] Query failed for ' . $days . 'd: ' . $e->getMessage());
        continue;
    }

    foreach ($subs as $sub) {
        $subId      = (int)$sub['id'];
        $periodDate = date('Y-m-d', strtotime($sub['trial_ends_at']));

        // Skip if already notified for this trial period
        if (trialAlreadySent($pdo, $eventType, $subId, $periodDate)) {
            echo "  [SKIP] $eventType sub#$subId ($periodDate) already sent\n";
            continue;
        }

        // Send email
        $ok = mailer()->sendTrialExpiry($sub['org_email'], $sub['admin_name'], $days);
        if ($ok) {
            $sent++;
            trialMarkSent($pdo, $eventType, $subId, $periodDate);
        } else {
            $errors++;
            error_log("[trial-expiry] Email failed to {$sub['org_email']} sub#{$subId}");
        }

        // In-app notification for all org users
        notifyOrg(
            (int)$sub['org_id'],
            'Trial Expiring in ' . $days . ' Day' . ($days !== 1 ? 's' : ''),
            'Your free trial ends on ' . date('d M Y', strtotime($sub['trial_ends_at'])) . '. Upgrade now to keep full access to your modules.',
            $days <= 1 ? 'danger' : 'warning',
            APP_URL . '/client/billing.php'
        );

        // Activity log
        try {
            $pdo->prepare(
                "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','billing',?,?)"
            )->execute([
                ($ok ? 'Trial expiry reminder sent' : 'Trial expiry reminder FAILED') .
                " to {$sub['org_email']} ({$days} days remaining)",
                'cron',
            ]);
        } catch (Exception $e) {}
    }
}

echo date('Y-m-d H:i:s') . " — Done. Sent: {$sent}, Errors: {$errors}\n";
