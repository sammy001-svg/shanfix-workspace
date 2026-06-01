<?php
/**
 * Cron: Health Appointment Reminders (SMS + Email)
 * Schedule: Daily at 8:00 AM
 * cPanel: 0 8 * * * php /home/USERNAME/public_html/shanfix/cron/appointment-reminders.php >> /home/USERNAME/logs/shanfix-cron.log 2>&1
 *
 * Sends reminders for appointments scheduled for TOMORROW.
 * Deduped via scheduled_email_log to prevent duplicate sends.
 */
define('CRON_RUN', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/notifications.php';

$sent   = 0;
$errors = 0;
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$eventType = 'appt_reminder_24h';

echo date('Y-m-d H:i:s') . " — appointment-reminders.php starting (tomorrow: {$tomorrow})...\n";

// ── Dedup helpers ──────────────────────────────────────────────────
function apptAlreadySent(PDO $pdo, int $apptId): bool {
    try {
        $s = $pdo->prepare("SELECT id FROM scheduled_email_log WHERE event_type='appt_reminder_24h' AND reference_id=? AND period_date=?");
        $s->execute([$apptId, date('Y-m-d')]);
        return (bool)$s->fetch();
    } catch (Exception $e) { return false; }
}
function apptMarkSent(PDO $pdo, int $apptId): void {
    try {
        $pdo->prepare("INSERT IGNORE INTO scheduled_email_log (event_type,reference_id,period_date) VALUES ('appt_reminder_24h',?,?)")
            ->execute([$apptId, date('Y-m-d')]);
    } catch (Exception $e) {}
}

// ── Fetch tomorrow's scheduled appointments ─────────────────────
$appointments = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.id, a.org_id, a.date, a.time, a.type, a.notes,
               CONCAT(p.first_name,' ',p.last_name) AS patient_name,
               p.phone AS patient_phone, p.email AS patient_email,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name,
               d.specialization,
               o.name AS org_name
        FROM health_appointments a
        JOIN health_patients p ON a.patient_id = p.id
        LEFT JOIN health_doctors d ON a.doctor_id = d.id
        JOIN organizations o ON a.org_id = o.id
        WHERE a.date = ? AND a.status = 'scheduled'
        LIMIT 1000
    ");
    $stmt->execute([$tomorrow]);
    $appointments = $stmt->fetchAll();
} catch (Throwable $e) {
    echo "  [ERROR] Query failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "  Found " . count($appointments) . " appointment(s) for tomorrow.\n";

foreach ($appointments as $appt) {
    $apptId     = (int)$appt['id'];
    $patName    = $appt['patient_name'];
    $timeLabel  = $appt['time'] ? date('h:i A', strtotime($appt['time'])) : '';
    $dateLabel  = date('l, d F Y', strtotime($appt['date']));
    $doctorLine = $appt['doctor_name'] ? "Dr. {$appt['doctor_name']}" . ($appt['specialization'] ? " ({$appt['specialization']})" : '') : 'Duty Physician';
    $orgName    = $appt['org_name'];

    if (apptAlreadySent($pdo, $apptId)) {
        echo "  [SKIP] appt#$apptId already notified\n";
        continue;
    }

    $emailOk = false;

    // ── Email ──────────────────────────────────────────────────────
    if (!empty($appt['patient_email'])) {
        $body = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
            <div style='color:rgba(255,255,255,.6);font-size:.8rem;margin-top:4px'>{$orgName} — Health Clinic</div>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <p style='color:#374151;margin:0 0 16px'>Dear <strong>" . htmlspecialchars($patName) . "</strong>,</p>
            <div style='background:#f0fdf4;border-left:4px solid #1A8A4E;padding:16px 20px;border-radius:0 8px 8px 0;margin-bottom:20px'>
              <p style='color:#14532d;font-weight:700;margin:0 0 4px'>Appointment Reminder — Tomorrow</p>
              <p style='color:#166534;font-size:.9rem;margin:0'>You have a medical appointment scheduled for <strong>tomorrow</strong>.</p>
            </div>
            <table style='width:100%;border-collapse:collapse;margin:0 0 20px'>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Date</td><td style='padding:8px;border:1px solid #eee;font-weight:600'>{$dateLabel}</td></tr>
              " . ($timeLabel ? "<tr><td style='padding:8px;border:1px solid #eee;color:#666'>Time</td><td style='padding:8px;border:1px solid #eee;font-weight:600;color:#1A8A4E'>{$timeLabel}</td></tr>" : '') . "
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Doctor</td><td style='padding:8px;border:1px solid #eee'>{$doctorLine}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Type</td><td style='padding:8px;border:1px solid #eee'>" . htmlspecialchars($appt['type'] ?? 'General') . "</td></tr>
            </table>
            <p style='color:#64748b;font-size:.85rem'>Please arrive 10 minutes early. If you need to reschedule, contact the clinic as soon as possible.</p>
            <hr style='border:none;border-top:1px solid #f1f5f9;margin:24px 0'>
            <p style='color:#94a3b8;font-size:.75rem;margin:0;text-align:center'>&copy; " . date('Y') . " " . APP_NAME . "</p>
          </div>
        </div>";
        try {
            $emailOk = mailer()->send(
                $appt['patient_email'],
                "Appointment Reminder: {$dateLabel}" . ($timeLabel ? " at {$timeLabel}" : '') . " — {$orgName}",
                $body
            );
            if ($emailOk) $sent++;
        } catch (Throwable $e) {
            error_log("[appt-reminders] Email failed appt#{$apptId}: " . $e->getMessage());
            $errors++;
        }
    }

    // ── SMS ────────────────────────────────────────────────────────
    if (!empty($appt['patient_phone'])) {
        $sms = "Reminder: Dear {$patName}, you have an appointment tomorrow ({$dateLabel}"
             . ($timeLabel ? " at {$timeLabel}" : '') . ") with {$doctorLine} at {$orgName}. Please be on time.";
        notifySms($appt['patient_phone'], $sms, (int)$appt['org_id'], 'appt_reminder_24h');
    }

    apptMarkSent($pdo, $apptId);

    // In-app notification for clinic staff
    notifyOrg(
        (int)$appt['org_id'],
        "Tomorrow: Appointment — {$patName}",
        "Appointment {$dateLabel}" . ($timeLabel ? " at {$timeLabel}" : '') . " with {$doctorLine}.",
        'info',
        APP_URL . '/modules/health/appointments.php'
    );

    $status = ($emailOk || !empty($appt['patient_phone'])) ? 'OK' : 'FAIL';
    echo "  [{$status}] appt#{$apptId} → {$patName} (email:" . ($emailOk?'✓':'✗') . " sms:" . (!empty($appt['patient_phone'])?'✓':'✗') . ")\n";

    try {
        $pdo->prepare("INSERT INTO activity_log (action,module,description,ip) VALUES ('cron_reminder','health',?,?)")
            ->execute(["Appt reminder sent appt#{$apptId} to {$patName}", 'cron']);
    } catch (Throwable $e) {}
}

echo "\n" . date('Y-m-d H:i:s') . " — Done. Emails sent: {$sent}, Errors: {$errors}\n";
