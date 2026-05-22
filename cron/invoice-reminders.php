<?php
/**
 * Cron: Invoice Payment Reminders
 * Schedule: Daily at 9:00 AM
 * cPanel: 0 9 * * * php /home/USERNAME/public_html/shanfix/cron/invoice-reminders.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

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

$sent   = 0;
$errors = 0;

// ── Helper: build invoice reminder HTML body ──────────────────────────────────
function buildInvoiceReminderBody(array $inv, string $type): string
{
    $badgeColor  = $type === 'overdue' ? '#e74c3c' : '#e67e22';
    $badgeLabel  = $type === 'overdue' ? 'OVERDUE' : 'DUE IN 3 DAYS';
    $dueFormatted = date('d M Y', strtotime($inv['due_date']));
    $totalFmt    = 'KES ' . number_format((float)$inv['total'], 2);
    $amountFmt   = 'KES ' . number_format((float)$inv['amount'], 2);
    $taxFmt      = 'KES ' . number_format((float)$inv['tax'], 2);
    $loginUrl    = APP_URL . '/client/billing.php';

    $messageIntro = $type === 'overdue'
        ? "Your invoice is <strong>overdue</strong>. Please make payment immediately to avoid service interruption."
        : "Your invoice is due in <strong>3 days</strong>. Please arrange payment before the due date.";

    return "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
        <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
      </div>
      <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
        <div style='display:inline-block;background:{$badgeColor};color:white;padding:4px 14px;border-radius:50px;font-size:.8rem;font-weight:700;margin-bottom:16px'>{$badgeLabel}</div>
        <h2 style='color:#0B2D4E;margin-top:0'>Invoice {$inv['invoice_number']}</h2>
        <p>Dear <strong>" . htmlspecialchars($inv['admin_name']) . "</strong>,</p>
        <p>{$messageIntro}</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Invoice #</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>" . htmlspecialchars($inv['invoice_number']) . "</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Organization</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($inv['org_name']) . "</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Amount</td><td style='padding:8px;border:1px solid #eee'>{$amountFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Tax (VAT)</td><td style='padding:8px;border:1px solid #eee'>{$taxFmt}</td></tr>
          <tr style='background:#fff5f5'><td style='padding:8px;border:1px solid #eee;font-weight:700'>Total Due</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:{$badgeColor}'>{$totalFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Due Date</td><td style='padding:8px;border:1px solid #eee;color:{$badgeColor};font-weight:600'>{$dueFormatted}</td></tr>
        </table>
        <p style='color:#666;font-size:.85rem'>Pay via M-Pesa: Paybill <strong>123456</strong>, Account: <strong>" . htmlspecialchars($inv['invoice_number']) . "</strong></p>
        <div style='text-align:center;margin:24px 0'>
          <a href='{$loginUrl}' style='background:{$badgeColor};color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
            View &amp; Pay Invoice &rarr;
          </a>
        </div>
        <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
        <p style='color:#999;font-size:.8rem;margin:0'>
          &copy; " . date('Y') . " " . APP_NAME . " &bull;
          <a href='" . APP_URL . "' style='color:#1A8A4E'>Visit Website</a> &bull;
          If you did not request this, please contact support.
        </p>
      </div>
    </div>";
}

// ── 1. Invoices due in exactly 3 days ────────────────────────────────────────
$dueSoon = $pdo->prepare("
    SELECT i.*, o.name AS org_name, u.name AS admin_name, u.email AS admin_email
    FROM invoices i
    JOIN organizations o ON i.org_id = o.id
    JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
    WHERE i.status IN ('sent', 'pending')
      AND DATE(i.due_date) = ?
");
$dueSoon->execute([date('Y-m-d', strtotime('+3 days'))]);
$dueSoonInvoices = $dueSoon->fetchAll();

foreach ($dueSoonInvoices as $inv) {
    $subject = "Reminder: Invoice {$inv['invoice_number']} due in 3 days";
    $body    = buildInvoiceReminderBody($inv, 'due_soon');
    $ok      = $mailer->send($inv['admin_email'], $subject, $body);

    if ($ok) {
        $sent++;
    } else {
        $errors++;
        error_log("[invoice-reminders cron] Due-soon: Failed to send to {$inv['admin_email']} for invoice {$inv['invoice_number']}");
    }

    // In-app notification
    notifyOrg(
        (int)$inv['org_id'],
        'Invoice Due in 3 Days — ' . $inv['invoice_number'],
        'Invoice ' . $inv['invoice_number'] . ' for KES ' . number_format((float)$inv['total'], 2) . ' is due on ' . date('d M Y', strtotime($inv['due_date'])) . '. Please arrange payment.',
        'warning',
        APP_URL . '/client/billing.php'
    );

    $pdo->prepare(
        "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','billing',?,?)"
    )->execute([
        ($ok ? 'Due-soon reminder sent' : 'Due-soon reminder FAILED') .
        " — Invoice {$inv['invoice_number']} to {$inv['admin_email']}",
        'cron',
    ]);
}

// ── 2. Overdue invoices (due_date < today) ────────────────────────────────────
$overdue = $pdo->prepare("
    SELECT i.*, o.name AS org_name, u.name AS admin_name, u.email AS admin_email
    FROM invoices i
    JOIN organizations o ON i.org_id = o.id
    JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
    WHERE i.status IN ('sent', 'pending')
      AND DATE(i.due_date) < ?
");
$overdue->execute([date('Y-m-d')]);
$overdueInvoices = $overdue->fetchAll();

foreach ($overdueInvoices as $inv) {
    $subject = "OVERDUE: Invoice {$inv['invoice_number']} — Action Required";
    $body    = buildInvoiceReminderBody($inv, 'overdue');
    $ok      = $mailer->send($inv['admin_email'], $subject, $body);

    if ($ok) {
        $sent++;
    } else {
        $errors++;
        error_log("[invoice-reminders cron] Overdue: Failed to send to {$inv['admin_email']} for invoice {$inv['invoice_number']}");
    }

    // In-app notification
    notifyOrg(
        (int)$inv['org_id'],
        'Overdue Invoice — ' . $inv['invoice_number'],
        'Invoice ' . $inv['invoice_number'] . ' for KES ' . number_format((float)$inv['total'], 2) . ' was due on ' . date('d M Y', strtotime($inv['due_date'])) . ' and is now overdue. Immediate payment is required.',
        'danger',
        APP_URL . '/client/billing.php'
    );

    // Update invoice status to overdue
    $pdo->prepare("UPDATE invoices SET status='overdue' WHERE id=? AND status IN ('sent','pending')")
        ->execute([$inv['id']]);

    $pdo->prepare(
        "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','billing',?,?)"
    )->execute([
        ($ok ? 'Overdue reminder sent' : 'Overdue reminder FAILED') .
        " — Invoice {$inv['invoice_number']} to {$inv['admin_email']}",
        'cron',
    ]);
}

echo date('Y-m-d H:i:s')
    . " — Invoice reminders cron done."
    . " Due-soon: " . count($dueSoonInvoices)
    . ", Overdue: " . count($overdueInvoices)
    . ", Sent: {$sent}, Errors: {$errors}\n";
