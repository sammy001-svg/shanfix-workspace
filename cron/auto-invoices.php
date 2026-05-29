<?php
/**
 * Cron: Auto-generate Renewal Invoices
 * Schedule: Daily at 8:00 AM
 * cPanel: 0 8 * * * php /home/USERNAME/public_html/shanfix/cron/auto-invoices.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$invoicesCreated = 0;
$emailsSent      = 0;
$errors          = 0;

echo date('Y-m-d H:i:s') . " — auto-invoices.php starting...\n";

// ── Dedup helpers ─────────────────────────────────────────────────
function autoInvAlreadyLogged(PDO $pdo, int $orgId, string $periodDate): bool
{
    try {
        $s = $pdo->prepare(
            "SELECT id FROM scheduled_email_log
             WHERE event_type='auto_renewal_invoice' AND reference_id=? AND period_date=?"
        );
        $s->execute([$orgId, $periodDate]);
        return (bool)$s->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function autoInvMarkLogged(PDO $pdo, int $orgId, string $periodDate): void
{
    try {
        $pdo->prepare(
            "INSERT IGNORE INTO scheduled_email_log (event_type, reference_id, period_date) VALUES (?,?,?)"
        )->execute(['auto_renewal_invoice', $orgId, $periodDate]);
    } catch (Exception $e) {}
}

// ── Build renewal email body ──────────────────────────────────────
function buildRenewalEmailBody(array $row, string $invoiceNo, float $amount, float $tax, float $total, string $dueDate): string
{
    $totalFmt  = 'KES ' . number_format($total,  2);
    $amountFmt = 'KES ' . number_format($amount, 2);
    $taxFmt    = 'KES ' . number_format($tax,    2);
    $dueFmt    = date('d M Y', strtotime($dueDate));
    $loginUrl  = APP_URL . '/client/billing.php';

    return "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
        <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
      </div>
      <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
        <div style='display:inline-block;background:#1A8A4E;color:white;padding:4px 14px;border-radius:50px;font-size:.8rem;font-weight:700;margin-bottom:16px'>RENEWAL INVOICE</div>
        <h2 style='color:#0B2D4E;margin-top:0'>Subscription Renewal — " . htmlspecialchars($invoiceNo) . "</h2>
        <p>Dear <strong>" . htmlspecialchars($row['admin_name']) . "</strong>,</p>
        <p>Your <strong>" . htmlspecialchars($row['plan_name'] ?? 'subscription') . "</strong> plan is expiring soon.
        A renewal invoice has been generated for you. Please pay before the due date to keep your workspace active.</p>
        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Invoice #</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>" . htmlspecialchars($invoiceNo) . "</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Organization</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($row['org_name']) . "</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Plan</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($row['plan_name'] ?? '—') . "</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Subtotal</td><td style='padding:8px;border:1px solid #eee'>{$amountFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Tax (16% VAT)</td><td style='padding:8px;border:1px solid #eee'>{$taxFmt}</td></tr>
          <tr style='background:#f0fdf4'><td style='padding:8px;border:1px solid #eee;font-weight:700'>Total Due</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>{$totalFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Due Date</td><td style='padding:8px;border:1px solid #eee;color:#e67e22;font-weight:600'>{$dueFmt}</td></tr>
        </table>
        <div style='text-align:center;margin:24px 0'>
          <a href='{$loginUrl}' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
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

// ── 1. Find subscriptions expiring within 7 days ──────────────────
$subs = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*, o.name AS org_name, o.email AS org_email,
               p.name AS plan_name, p.price_monthly, p.price_annual,
               u.name AS admin_name, u.email AS admin_email
        FROM subscriptions s
        JOIN organizations o ON s.org_id = o.id
        JOIN users u ON u.org_id = o.id AND u.role = 'client_admin'
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.status IN ('active','trial')
          AND (
            s.ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
            OR s.trial_ends_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
          )
          AND s.org_id NOT IN (
            SELECT org_id FROM invoices
            WHERE status IN ('sent','pending','draft')
              AND notes LIKE '%renewal%'
              AND created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
          )
    ");
    $stmt->execute();
    $subs = $stmt->fetchAll();
} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " [ERROR] Subscription query failed: " . $e->getMessage() . "\n";
    error_log('[auto-invoices] Subscription query: ' . $e->getMessage());
}

echo date('Y-m-d H:i:s') . " — Found " . count($subs) . " subscription(s) needing renewal invoices.\n";

// ── 2. Create invoices ────────────────────────────────────────────
foreach ($subs as $row) {
    $orgId   = (int)$row['org_id'];
    $subId   = (int)$row['id'];
    $cycle   = $row['billing_cycle'] ?? 'monthly';
    $dueDate = $row['ends_at'] ?: $row['trial_ends_at'] ?: date('Y-m-d', strtotime('+7 days'));

    // Dedup via scheduled_email_log
    $periodDate = date('Y-m', strtotime($dueDate)); // monthly period key
    if (autoInvAlreadyLogged($pdo, $orgId, $periodDate)) {
        echo "  [SKIP] org#{$orgId} ({$row['org_name']}) already has auto-renewal logged for {$periodDate}\n";
        continue;
    }

    // Determine amount
    $amount = $cycle === 'annual'
        ? (float)($row['price_annual'] ?? $row['price_monthly'] ?? 0)
        : (float)($row['price_monthly'] ?? 0);

    // Fall back to subscription amount if plan price is not set
    if ($amount <= 0) {
        $amount = (float)($row['amount'] ?? 0);
    }

    if ($amount <= 0) {
        echo "  [SKIP] org#{$orgId} ({$row['org_name']}) — zero amount, skipping.\n";
        continue;
    }

    $tax    = round($amount * 0.16, 2);
    $total  = $amount + $tax;

    // Build invoice number: REN-YYYYMM-ORGID
    $invoiceNo = 'REN-' . date('Ym', strtotime($dueDate)) . '-' . $orgId;
    $notes     = 'Subscription renewal — ' . ($row['plan_name'] ?? 'Subscription');

    try {
        $pdo->prepare("
            INSERT INTO invoices (org_id, subscription_id, invoice_number, amount, tax, total, status, due_date, notes)
            VALUES (?,?,?,?,?,?,'sent',?,?)
        ")->execute([$orgId, $subId, $invoiceNo, $amount, $tax, $total, $dueDate, $notes]);

        $invId = (int)$pdo->lastInsertId();
        $invoicesCreated++;

        echo "  [OK] Created renewal invoice {$invoiceNo} for org#{$orgId} ({$row['org_name']}) — KES " . number_format($total, 2) . "\n";

        // ── 3. Send email notification ──
        $subject = "Renewal Invoice {$invoiceNo} — Action Required";
        $body    = buildRenewalEmailBody($row, $invoiceNo, $amount, $tax, $total, $dueDate);
        $sent    = mailer()->send($row['admin_email'], $subject, $body);

        if ($sent) {
            $emailsSent++;
            echo "  [EMAIL] Sent renewal notice to {$row['admin_email']}\n";
        } else {
            $errors++;
            error_log("[auto-invoices] Email failed for org#{$orgId} to {$row['admin_email']}");
        }

        // ── 4. In-app notification ──
        notifyOrg(
            $orgId,
            'Renewal Invoice Generated — ' . $invoiceNo,
            'A renewal invoice of KES ' . number_format($total, 2) . ' has been created for your ' . ($row['plan_name'] ?? 'subscription') . ' plan, due on ' . date('d M Y', strtotime($dueDate)) . '.',
            'info',
            APP_URL . '/client/billing.php?tab=pay'
        );

        // ── 5. Log to prevent duplicates ──
        autoInvMarkLogged($pdo, $orgId, $periodDate);

        // Activity log
        try {
            $pdo->prepare(
                "INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_invoice','billing',?,?)"
            )->execute(["Auto renewal invoice {$invoiceNo} created for {$row['org_name']}", 'cron']);
        } catch (Exception $e) {}

    } catch (Exception $e) {
        $errors++;
        echo "  [ERROR] org#{$orgId} ({$row['org_name']}): " . $e->getMessage() . "\n";
        error_log("[auto-invoices] Invoice insert failed for org#{$orgId}: " . $e->getMessage());
    }
}

echo date('Y-m-d H:i:s')
    . " — Done."
    . " Invoices created: {$invoicesCreated}"
    . ", Emails sent: {$emailsSent}"
    . ", Errors: {$errors}\n";
