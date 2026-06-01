<?php
/**
 * Cron: School Fee Overdue / Upcoming Reminders (SMS + Email)
 * Schedule: Daily at 9:30 AM
 * cPanel: 30 9 * * * php /home/USERNAME/public_html/shanfix/cron/school-fee-reminders.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 *
 * Two triggers per fee invoice:
 *   1. Due in 3 days  — "upcoming" reminder
 *   2. Overdue        — "overdue" reminder (sent once per invoice, daily if not paid)
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$sent   = 0;
$errors = 0;
$today  = date('Y-m-d');
$in3    = date('Y-m-d', strtotime('+3 days'));

echo date('Y-m-d H:i:s') . " — school-fee-reminders.php starting...\n";

function feeAlreadySent(PDO $pdo, string $type, int $feeId): bool {
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type=? AND reference_id=? AND period_date=?");
        $s->execute([$type, $feeId, date('Y-m-01')]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}
function feeMarkSent(PDO $pdo, string $type, int $feeId): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type,reference_id,period_date) VALUES (?,?,?)")
            ->execute([$type, $feeId, date('Y-m-01')]);
    } catch (Exception $e) {}
}

// ── Fetch all outstanding fee invoices needing reminders ─────────
// Join student → primary parent to get contact details
$fees = [];
try {
    $stmt = $pdo->prepare("
        SELECT f.id AS fee_id, f.org_id, f.fee_type, f.amount, f.balance, f.due_date, f.status,
               CONCAT(s.first_name,' ',s.last_name) AS student_name, s.admission_no,
               c.name AS class_name,
               p.first_name AS par_first, p.last_name AS par_last,
               p.phone AS par_phone, p.email AS par_email,
               o.name AS org_name
        FROM sch_fees f
        JOIN sch_students s ON f.student_id = s.id
        LEFT JOIN sch_classes c ON s.class_id = c.id
        LEFT JOIN sch_student_parents sp ON sp.student_id = s.id AND sp.is_primary = 1
        LEFT JOIN sch_parents p ON p.id = sp.parent_id
        JOIN organizations o ON f.org_id = o.id
        WHERE f.balance > 0
          AND f.status IN ('unpaid','partial')
          AND (f.due_date <= ? OR (f.due_date BETWEEN ? AND ?))
        ORDER BY f.due_date ASC
        LIMIT 500
    ");
    $stmt->execute([$today, $today, $in3]);
    $fees = $stmt->fetchAll();
} catch (Throwable $e) {
    echo "  [ERROR] Query failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "  Found " . count($fees) . " fee invoice(s) needing reminders.\n";

foreach ($fees as $fee) {
    $feeId      = (int)$fee['fee_id'];
    $isOverdue  = $fee['due_date'] < $today;
    $eventType  = $isOverdue ? 'sch_fee_overdue' : 'sch_fee_upcoming';
    $studentName = $fee['student_name'];
    $parName    = trim(($fee['par_first'] ?? '') . ' ' . ($fee['par_last'] ?? '')) ?: 'Parent/Guardian';
    $balFmt     = formatCurrency($fee['balance']);
    $dueLabel   = $isOverdue
        ? 'OVERDUE since ' . date('d M Y', strtotime($fee['due_date']))
        : 'due ' . date('d M Y', strtotime($fee['due_date']));
    $feeTypeLabel = ucwords(str_replace('_', ' ', $fee['fee_type']));

    if (feeAlreadySent($pdo, $eventType, $feeId)) {
        echo "  [SKIP] {$eventType} fee#{$feeId} already sent this month\n";
        continue;
    }

    $emailOk = false;

    // ── Email to parent ────────────────────────────────────────────
    if (!empty($fee['par_email'])) {
        $borderColor = $isOverdue ? '#ef4444' : '#f59e0b';
        $bgColor     = $isOverdue ? '#fef2f2' : '#fffbeb';
        $headerLabel = $isOverdue ? 'Fee Payment Overdue' : 'Fee Payment Reminder';

        $body = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
            <div style='color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px'>" . htmlspecialchars($fee['org_name']) . " — School Fees</div>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <p>Dear <strong>" . htmlspecialchars($parName) . "</strong>,</p>
            <div style='background:{$bgColor};border-left:4px solid {$borderColor};padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
              <p style='font-weight:700;margin:0 0 4px;color:" . ($isOverdue ? '#991b1b' : '#92400e') . "'>{$headerLabel}</p>
              <p style='font-size:.9rem;margin:0;color:" . ($isOverdue ? '#7f1d1d' : '#78350f') . "'>
                Fee payment for <strong>" . htmlspecialchars($studentName) . "</strong> is <strong>{$dueLabel}</strong>.
              </p>
            </div>
            <table style='width:100%;border-collapse:collapse;margin:0 0 20px'>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Student</td><td style='padding:8px;border:1px solid #eee;font-weight:600'>" . htmlspecialchars($studentName) . " (" . htmlspecialchars($fee['admission_no'] ?? '') . ")</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Class</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($fee['class_name'] ?? '—') . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Fee Type</td><td style='padding:8px;border:1px solid #eee'>{$feeTypeLabel}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Outstanding Balance</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:{$borderColor}'>{$balFmt}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Due Date</td><td style='padding:8px;border:1px solid #eee;color:{$borderColor};font-weight:600'>{$dueLabel}</td></tr>
            </table>
            <p style='color:#64748b;font-size:.85rem'>Please visit the school bursar's office or make payment via your preferred method to clear this balance.</p>
            <hr style='border:none;border-top:1px solid #f1f5f9;margin:24px 0'>
            <p style='color:#94a3b8;font-size:.75rem;text-align:center;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
          </div>
        </div>";

        try {
            $subjectPrefix = $isOverdue ? 'OVERDUE' : 'Reminder';
            $emailOk = mailer()->send(
                $fee['par_email'],
                "[{$subjectPrefix}] {$feeTypeLabel} — {$studentName} — {$balFmt}",
                $body
            );
            if ($emailOk) $sent++;
        } catch (Throwable $e) {
            error_log("[school-fees] Email failed fee#{$feeId}: " . $e->getMessage());
            $errors++;
        }
    }

    // ── SMS to parent ──────────────────────────────────────────────
    if (!empty($fee['par_phone'])) {
        $prefix = $isOverdue ? 'OVERDUE' : 'Reminder';
        $sms = "{$prefix}: Dear {$parName}, {$feeTypeLabel} for {$studentName} is {$dueLabel}. Outstanding: {$balFmt}. Please pay at the school bursar.";
        notifySms($fee['par_phone'], $sms, (int)$fee['org_id'], $eventType);
    }

    feeMarkSent($pdo, $eventType, $feeId);

    notifyOrg(
        (int)$fee['org_id'],
        ($isOverdue ? 'Overdue' : 'Upcoming') . " Fee — {$studentName}",
        "{$feeTypeLabel} balance of {$balFmt} is {$dueLabel} for {$studentName}.",
        $isOverdue ? 'danger' : 'warning',
        APP_URL . '/modules/school/fees.php'
    );

    $status = ($emailOk || !empty($fee['par_phone'])) ? 'OK' : 'FAIL';
    echo "  [{$status}] {$eventType} fee#{$feeId} → {$studentName} (email:" . ($emailOk?'✓':'✗') . " sms:" . (!empty($fee['par_phone'])?'✓':'✗') . ")\n";
}

echo "\n" . date('Y-m-d H:i:s') . " — Done. Sent: {$sent}, Errors: {$errors}\n";
