<?php
/**
 * Cron: SACCO Loan Repayment Reminders, Penalty Auto-Creation & Savings Reminders
 * Schedule: Daily at 7:30 AM
 * cPanel: 30 7 * * * php /home/USERNAME/public_html/shanfix/cron/sacco-reminders.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$sent         = 0;
$penaltiesAdded = 0;
$errors       = 0;

echo date('Y-m-d H:i:s') . " — sacco-reminders.php starting...\n";

// ── Dedup helpers ─────────────────────────────────────────────────
function saccoAlreadySent(PDO $pdo, string $eventType, int $refId, string $periodDate): bool
{
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type=? AND reference_id=? AND period_date=?");
        $s->execute([$eventType, $refId, $periodDate]);
        return (bool)$s->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function saccoMarkSent(PDO $pdo, string $eventType, int $refId, string $periodDate): void
{
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type, reference_id, period_date) VALUES (?,?,?)")
            ->execute([$eventType, $refId, $periodDate]);
    } catch (Exception $e) {}
}

// ── Email body builder ────────────────────────────────────────────
function buildSaccoEmailBody(string $memberName, string $bodyContent): string
{
    $name    = htmlspecialchars($memberName, ENT_QUOTES);
    $appName = APP_NAME;
    $year    = date('Y');

    return "
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
        <span style='color:white;font-size:1.2rem;font-weight:800'>{$appName}</span>
        <div style='color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px'>SACCO Management</div>
      </div>
      <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
        <p style='color:#374151;margin:0 0 16px'>Dear <strong>{$name}</strong>,</p>
        {$bodyContent}
        <hr style='border:none;border-top:1px solid #f1f5f9;margin:24px 0'>
        <p style='color:#94a3b8;font-size:.75rem;margin:0;text-align:center'>
          &copy; {$year} {$appName} &bull; SACCO Management System
        </p>
      </div>
    </div>";
}

// ══════════════════════════════════════════════════════════════════
// SECTION 1: Loan Repayment Reminders (due today or tomorrow)
// ══════════════════════════════════════════════════════════════════
echo "\n[1/3] Loan repayment reminders...\n";

$eventTypeRepayment = 'sacco_repayment_reminder';

try {
    // Also surface loans whose NEXT PENDING schedule installment is due within 3 days
    $stmt = $pdo->prepare("
        SELECT l.id AS loan_id, l.org_id, l.outstanding_balance, l.next_repayment_date,
               m.first_name, m.last_name, m.email AS member_email, m.phone AS member_phone,
               m.id AS member_id
        FROM sacco_loans l
        JOIN sacco_members m ON l.member_id = m.id
        WHERE l.status = 'active'
          AND DATE(l.next_repayment_date) BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 1 DAY)
          AND l.outstanding_balance > 0
        LIMIT 500
    ");
    $stmt->execute();
    $upcomingLoans = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[sacco-reminders] Repayment query failed: ' . $e->getMessage());
    $upcomingLoans = [];
}

echo "  Found " . count($upcomingLoans) . " upcoming repayment(s).\n";

foreach ($upcomingLoans as $loan) {
    $loanId     = (int)$loan['loan_id'];
    $periodDate = date('Y-m-d', strtotime($loan['next_repayment_date']));
    $memberName = trim($loan['first_name'] . ' ' . $loan['last_name']);
    $dueLabel   = date('Y-m-d', strtotime($loan['next_repayment_date'])) === date('Y-m-d') ? 'today' : 'tomorrow';
    $amtFmt     = 'KES ' . number_format((float)$loan['outstanding_balance'], 2);

    if (saccoAlreadySent($pdo, $eventTypeRepayment, $loanId, $periodDate)) {
        echo "  [SKIP] repayment reminder loan#$loanId ($periodDate) already sent\n";
        continue;
    }

    $bodyContent = "
        <div style='background:#fff7ed;border-left:4px solid #f59e0b;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
          <p style='color:#92400e;font-weight:700;margin:0 0 4px'>Loan Repayment Due " . ucfirst($dueLabel) . "</p>
          <p style='color:#78350f;font-size:.9rem;margin:0'>Your loan repayment is due <strong>{$dueLabel}</strong>, " . date('d M Y', strtotime($loan['next_repayment_date'])) . ".</p>
        </div>
        <table style='width:100%;border-collapse:collapse;margin:0 0 20px'>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Outstanding Balance</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>{$amtFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Due Date</td><td style='padding:8px;border:1px solid #eee;color:#f59e0b;font-weight:600'>" . date('d M Y', strtotime($loan['next_repayment_date'])) . "</td></tr>
        </table>
        <p style='color:#64748b;font-size:.85rem'>Please ensure timely payment to avoid penalties. Contact your SACCO office if you need assistance.</p>
        <div style='text-align:center;margin:20px 0'>
          <a href='" . APP_URL . "/modules/sacco/loans.php'
             style='background:#f59e0b;color:white;padding:11px 26px;border-radius:50px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-block'>
            View My Loan Details &rarr;
          </a>
        </div>";

    try {
        $body = buildSaccoEmailBody($memberName, $bodyContent);
        $ok   = empty($loan['member_email']) ? false : mailer()->send(
            $loan['member_email'],
            "SACCO: Loan repayment due {$dueLabel} — " . $amtFmt,
            $body
        );
    } catch (Exception $e) {
        error_log("[sacco-reminders] Email build failed loan#{$loanId}: " . $e->getMessage());
        $ok = false;
    }

    // SMS reminder
    if (!empty($loan['member_phone'])) {
        $smsMsg = "SACCO: Dear {$memberName}, your loan repayment of {$amtFmt} is due {$dueLabel}. Please pay on time to avoid penalties.";
        notifySms($loan['member_phone'], $smsMsg, (int)$loan['org_id'], 'sacco_repayment_due');
    }

    if ($ok) {
        $sent++;
        saccoMarkSent($pdo, $eventTypeRepayment, $loanId, $periodDate);
        echo "  [OK] Repayment reminder → loan#$loanId ({$memberName})\n";
    } else {
        $errors++;
        echo "  [FAIL] Repayment reminder → loan#$loanId\n";
    }

    notifyOrg(
        (int)$loan['org_id'],
        'Loan Repayment Due ' . ucfirst($dueLabel) . ' — ' . $memberName,
        "Loan repayment of {$amtFmt} is due {$dueLabel} for member {$memberName}.",
        'warning',
        APP_URL . '/modules/sacco/loans.php'
    );

    try {
        $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','sacco',?,?)")
            ->execute(["Repayment reminder " . ($ok ? 'sent' : 'FAILED') . " — loan#{$loanId} to {$loan['member_email']}", 'cron']);
    } catch (Exception $e) {}
}

// ══════════════════════════════════════════════════════════════════
// SECTION 2: Overdue Loans — auto-create penalty
// ══════════════════════════════════════════════════════════════════
echo "\n[2/3] Overdue loan penalties...\n";

$eventTypePenalty = 'sacco_overdue_penalty';
$penaltyPeriod    = date('Y-m-01'); // First of current month — one penalty per loan per month

try {
    $stmt = $pdo->prepare("
        SELECT l.id AS loan_id, l.org_id, l.outstanding_balance, l.last_repayment_date,
               m.id AS member_id, m.first_name, m.last_name, m.email AS member_email
        FROM sacco_loans l
        JOIN sacco_members m ON l.member_id = m.id
        WHERE l.status = 'active'
          AND (
            l.last_repayment_date IS NULL
            OR l.last_repayment_date < DATE_SUB(NOW(), INTERVAL 30 DAY)
          )
          AND l.outstanding_balance > 0
        LIMIT 500
    ");
    $stmt->execute();
    $overdueLoans = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[sacco-reminders] Overdue query failed: ' . $e->getMessage());
    $overdueLoans = [];
}

echo "  Found " . count($overdueLoans) . " overdue loan(s).\n";

foreach ($overdueLoans as $loan) {
    $loanId     = (int)$loan['loan_id'];
    $memberName = trim($loan['first_name'] . ' ' . $loan['last_name']);

    // Skip if penalty already created this month
    if (saccoAlreadySent($pdo, $eventTypePenalty, $loanId, $penaltyPeriod)) {
        echo "  [SKIP] penalty loan#$loanId ($penaltyPeriod) already done\n";
        continue;
    }

    // Default penalty: 2% of outstanding balance (minimum KES 500)
    $penaltyAmt = max(500.00, round((float)$loan['outstanding_balance'] * 0.02, 2));
    $amtFmt     = 'KES ' . number_format((float)$loan['outstanding_balance'], 2);
    $penFmt     = 'KES ' . number_format($penaltyAmt, 2);

    // Insert penalty record
    $penaltyCreated = false;
    try {
        $pdo->prepare("
            INSERT INTO sacco_penalties (org_id, loan_id, member_id, amount, reason, penalty_date, status)
            VALUES (?, ?, ?, ?, 'Late repayment — 30+ days overdue', CURDATE(), 'unpaid')
        ")->execute([$loan['org_id'], $loanId, $loan['member_id'], $penaltyAmt]);
        $penaltyCreated = true;
        $penaltiesAdded++;
    } catch (Exception $e) {
        // If penalties table doesn't exist yet, log and skip
        error_log("[sacco-reminders] Penalty insert failed loan#{$loanId}: " . $e->getMessage());
    }

    saccoMarkSent($pdo, $eventTypePenalty, $loanId, $penaltyPeriod);

    // Send penalty notification email
    $bodyContent = "
        <div style='background:#fff1f2;border-left:4px solid #ef4444;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
          <p style='color:#991b1b;font-weight:700;margin:0 0 4px'>Overdue Loan Penalty Applied</p>
          <p style='color:#7f1d1d;font-size:.9rem;margin:0'>Your loan repayment is more than 30 days overdue. A penalty has been applied to your account.</p>
        </div>
        <table style='width:100%;border-collapse:collapse;margin:0 0 20px'>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Outstanding Balance</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>{$amtFmt}</td></tr>
          <tr style='background:#fff1f2'><td style='padding:8px;border:1px solid #eee;color:#666;font-weight:700'>Penalty Applied</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#ef4444'>{$penFmt}</td></tr>
          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Month</td><td style='padding:8px;border:1px solid #eee'>" . date('F Y') . "</td></tr>
        </table>
        <p style='color:#64748b;font-size:.85rem'>Please contact your SACCO office immediately to arrange a repayment plan and avoid further penalties.</p>
        <div style='text-align:center;margin:20px 0'>
          <a href='" . APP_URL . "/modules/sacco/loans.php'
             style='background:#ef4444;color:white;padding:11px 26px;border-radius:50px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-block'>
            View Loan Details &rarr;
          </a>
        </div>";

    try {
        $ok = empty($loan['member_email']) ? false : mailer()->send(
            $loan['member_email'],
            "SACCO: Overdue loan penalty applied — " . $penFmt,
            buildSaccoEmailBody($memberName, $bodyContent)
        );
        if ($ok) $sent++;
        else $errors++;
    } catch (Exception $e) {
        error_log("[sacco-reminders] Penalty email failed loan#{$loanId}: " . $e->getMessage());
        $errors++;
    }

    // SMS penalty alert
    if (!empty($loan['member_email'])) { /* email already attempted above */ }
    if (!empty($loan['member_phone'] ?? '')) {
        $smsMsg = "SACCO: Dear {$memberName}, a late payment penalty of {$penFmt} has been applied to your loan. Please contact the SACCO office urgently.";
        notifySms($loan['member_phone'], $smsMsg, (int)$loan['org_id'], 'sacco_penalty');
    }

    notifyOrg(
        (int)$loan['org_id'],
        'Overdue Loan Penalty — ' . $memberName,
        "Loan #{$loanId} is overdue. A penalty of {$penFmt} has been applied for " . date('F Y') . '.',
        'danger',
        APP_URL . '/modules/sacco/loans.php'
    );

    echo "  [" . ($penaltyCreated ? 'PENALTY' : 'LOG') . "] loan#$loanId ({$memberName}) — {$penFmt}\n";

    try {
        $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_penalty','sacco',?,?)")
            ->execute(["Penalty {$penFmt} applied to loan#{$loanId} member: {$memberName}", 'cron']);
    } catch (Exception $e) {}
}

// ══════════════════════════════════════════════════════════════════
// SECTION 3: Savings Reminders — no deposit this month
// ══════════════════════════════════════════════════════════════════
echo "\n[3/3] Savings reminders...\n";

$eventTypeSavings = 'sacco_savings_reminder';
$savingsPeriod    = date('Y-m-01');

try {
    $stmt = $pdo->prepare("
        SELECT m.id AS member_id, m.org_id, m.first_name, m.last_name, m.email
        FROM sacco_members m
        WHERE m.status = 'active'
          AND m.id NOT IN (
              SELECT DISTINCT member_id FROM sacco_savings
              WHERE YEAR(created_at) = YEAR(CURDATE())
                AND MONTH(created_at) = MONTH(CURDATE())
          )
          AND m.id NOT IN (
              SELECT reference_id FROM scheduled_email_log
              WHERE event_type = 'sacco_savings_reminder'
                AND period_date = ?
          )
        LIMIT 500
    ");
    $stmt->execute([$savingsPeriod]);
    $noSavings = $stmt->fetchAll();
} catch (Exception $e) {
    error_log('[sacco-reminders] Savings query failed: ' . $e->getMessage());
    $noSavings = [];
}

echo "  Found " . count($noSavings) . " member(s) with no savings this month.\n";

foreach ($noSavings as $member) {
    $memberId   = (int)$member['member_id'];
    $memberName = trim($member['first_name'] . ' ' . $member['last_name']);

    if (saccoAlreadySent($pdo, $eventTypeSavings, $memberId, $savingsPeriod)) {
        echo "  [SKIP] savings reminder member#$memberId already sent this month\n";
        continue;
    }

    $bodyContent = "
        <div style='background:#f0fdf4;border-left:4px solid #22c55e;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
          <p style='color:#14532d;font-weight:700;margin:0 0 4px'>Monthly Savings Reminder</p>
          <p style='color:#166534;font-size:.9rem;margin:0'>We noticed you haven't made a savings deposit this month. Consistent savings keep your SACCO membership strong!</p>
        </div>
        <p style='color:#374151;font-size:.9rem'>
          Regular savings contributions are important for your financial health and also strengthen the SACCO's ability to offer better loans and dividends.
        </p>
        <ul style='color:#374151;font-size:.9rem;line-height:1.8;padding-left:20px'>
          <li>Deposits can be made at any SACCO branch or via M-Pesa</li>
          <li>Even small regular amounts add up significantly</li>
          <li>Stay consistent to maintain full member benefits</li>
        </ul>
        <div style='text-align:center;margin:20px 0'>
          <a href='" . APP_URL . "/modules/sacco/savings.php'
             style='background:#22c55e;color:white;padding:11px 26px;border-radius:50px;text-decoration:none;font-weight:700;font-size:.9rem;display:inline-block'>
            View My Savings &rarr;
          </a>
        </div>";

    try {
        $ok = empty($member['email']) ? false : mailer()->send(
            $member['email'],
            "SACCO: Don't forget your " . date('F Y') . " savings deposit",
            buildSaccoEmailBody($memberName, $bodyContent)
        );
    } catch (Exception $e) {
        error_log("[sacco-reminders] Savings email failed member#{$memberId}: " . $e->getMessage());
        $ok = false;
    }

    // SMS savings nudge
    if (!empty($member['phone'] ?? '')) {
        $smsMsg = "SACCO: Dear {$memberName}, you haven't saved this month (" . date('F Y') . "). Regular savings keep your membership strong. Save today!";
        notifySms($member['phone'], $smsMsg, (int)$member['org_id'], 'sacco_savings_nudge');
    }

    if ($ok) {
        $sent++;
        saccoMarkSent($pdo, $eventTypeSavings, $memberId, $savingsPeriod);
        echo "  [OK] Savings reminder → member#$memberId ({$memberName})\n";
    } else {
        $errors++;
        echo "  [FAIL] Savings reminder → member#$memberId\n";
    }

    notifyOrg(
        (int)$member['org_id'],
        'Savings Reminder — ' . date('F Y'),
        "Member {$memberName} has not made a savings deposit this month.",
        'info',
        APP_URL . '/modules/sacco/savings.php'
    );

    try {
        $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('cron_email','sacco',?,?)")
            ->execute(["Savings reminder " . ($ok ? 'sent' : 'FAILED') . " to {$memberName} (member#{$memberId})", 'cron']);
    } catch (Exception $e) {}
}

echo "\n" . date('Y-m-d H:i:s')
    . " — Done."
    . " Emails sent: {$sent}"
    . ", Penalties added: {$penaltiesAdded}"
    . ", Errors: {$errors}\n";
