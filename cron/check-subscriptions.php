<?php
/**
 * Cron: Subscription Renewal Automation
 * Schedule: Daily at 7:00 AM (before trial-expiry at 8:00 AM)
 * cPanel: 0 7 * * * php /home/USERNAME/public_html/shanfix/cron/check-subscriptions.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';

$mailer = mailer();
$today  = date('Y-m-d');
$sent   = 0;
$suspended = 0;
$errors = 0;

echo date('Y-m-d H:i:s') . " — check-subscriptions.php starting...\n";

// ── 1. Renewal reminders for active paid subscriptions ────────────
// Send at 7 days, 3 days, and 1 day before ends_at
$targetDays = [7, 3, 1];

foreach ($targetDays as $days) {
    $targetDate = date('Y-m-d', strtotime("+{$days} days"));

    try {
        $stmt = $pdo->prepare("
            SELECT s.id, s.ends_at, s.amount,
                   o.name  AS org_name,
                   o.email AS org_email,
                   u.name  AS admin_name,
                   p.name  AS plan_name
            FROM subscriptions s
            JOIN organizations o ON s.org_id = o.id
            LEFT JOIN subscription_plans p ON s.plan_id = p.id
            JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
            WHERE DATE(s.ends_at) = ?
              AND s.status = 'active'
            LIMIT 200
        ");
        $stmt->execute([$targetDate]);
        $subs = $stmt->fetchAll();
    } catch (Exception $e) {
        error_log('[check-subscriptions] Query failed for ' . $days . 'd reminder: ' . $e->getMessage());
        continue;
    }

    foreach ($subs as $sub) {
        $ok = $mailer->sendSubscriptionExpiry(
            $sub['org_email'],
            $sub['admin_name'],
            $days,
            $sub['plan_name'] ?? 'Subscription',
            (float)$sub['amount']
        );

        if ($ok) {
            $sent++;
        } else {
            $errors++;
            error_log('[check-subscriptions] Email failed to ' . $sub['org_email']);
        }

        try {
            $pdo->prepare(
                "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','billing',?,?)"
            )->execute([
                ($ok ? 'Renewal reminder sent' : 'Renewal reminder FAILED') .
                " to {$sub['org_email']} ({$days} days remaining)",
                'cron',
            ]);
        } catch (Exception $e) {}
    }
}

// ── 2. Auto-suspend subscriptions that expired today or earlier ───
try {
    $stmt = $pdo->prepare("
        SELECT s.id AS sub_id, s.org_id, s.ends_at,
               o.name  AS org_name,
               o.email AS org_email,
               u.name  AS admin_name,
               p.name  AS plan_name
        FROM subscriptions s
        JOIN organizations o ON s.org_id = o.id
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
        WHERE DATE(s.ends_at) < ?
          AND s.status = 'active'
        LIMIT 200
    ");
    $stmt->execute([$today]);
    $expired = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[check-subscriptions] Expiry query failed: ' . $e->getMessage());
    $expired = [];
}

foreach ($expired as $sub) {
    try {
        $pdo->prepare("UPDATE subscriptions  SET status = 'expired'   WHERE id = ?")->execute([$sub['sub_id']]);
        $pdo->prepare("UPDATE organizations  SET status = 'suspended' WHERE id = ?")->execute([$sub['org_id']]);
        $suspended++;
    } catch (Exception $e) {
        error_log('[check-subscriptions] Suspend failed for org ' . $sub['org_id'] . ': ' . $e->getMessage());
        $errors++;
        continue;
    }

    // Send suspension notice
    $ok = $mailer->sendSuspension(
        $sub['org_email'],
        $sub['admin_name'],
        $sub['plan_name'] ?? 'Subscription'
    );
    if (!$ok) $errors++;

    try {
        $pdo->prepare(
            "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_suspend','billing',?,?)"
        )->execute([
            "Auto-suspended org #{$sub['org_id']} ({$sub['org_name']}) — subscription expired on {$sub['ends_at']}",
            'cron',
        ]);
    } catch (Exception $e) {}
}

echo date('Y-m-d H:i:s') .
     " — Done. Reminders sent: {$sent}, Auto-suspended: {$suspended}, Errors: {$errors}\n";
