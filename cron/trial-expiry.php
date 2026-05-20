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

// Instantiate mailer using the same constants as the sendEmail() helper
$mailer = new Mailer([
    'host'       => defined('SMTP_HOST') ? SMTP_HOST : 'localhost',
    'port'       => defined('SMTP_PORT') ? SMTP_PORT : 587,
    'username'   => defined('SMTP_USER') ? SMTP_USER : '',
    'password'   => defined('SMTP_PASS') ? SMTP_PASS : '',
    'encryption' => defined('SMTP_ENC')  ? SMTP_ENC  : 'tls',
    'from_name'  => APP_NAME,
    'from_email' => defined('MAIL_FROM') ? MAIL_FROM : 'noreply@shanfix.co.ke',
]);

// Find subscriptions expiring in 1, 3, or 7 days
$targetDays = [1, 3, 7];
$sent = 0;
$errors = 0;

foreach ($targetDays as $days) {
    $targetDate = date('Y-m-d', strtotime("+{$days} days"));

    $stmt = $pdo->prepare("
        SELECT s.*, o.name AS org_name, o.email AS org_email, u.name AS admin_name
        FROM subscriptions s
        JOIN organizations o ON s.org_id = o.id
        JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
        WHERE DATE(s.trial_ends_at) = ?
          AND s.status = 'trial'
        LIMIT 100
    ");
    $stmt->execute([$targetDate]);
    $subs = $stmt->fetchAll();

    foreach ($subs as $sub) {
        $ok = $mailer->sendTrialExpiry($sub['org_email'], $sub['admin_name'], $days);

        if ($ok) {
            $sent++;
        } else {
            $errors++;
            error_log("[trial-expiry cron] Failed to send to {$sub['org_email']}");
        }

        // Log to activity_log regardless of send result
        $pdo->prepare(
            "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','billing',?,?)"
        )->execute([
            ($ok ? 'Trial expiry reminder sent' : 'Trial expiry reminder FAILED') .
            " to {$sub['org_email']} ({$days} days remaining)",
            'cron',
        ]);
    }
}

echo date('Y-m-d H:i:s') . " — Trial expiry cron done. Sent: {$sent}, Errors: {$errors}\n";
