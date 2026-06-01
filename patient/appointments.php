<?php
$pageTitle = 'My Appointments';
require_once __DIR__ . '/../includes/header-patient.php';

// ── Book appointment (POST) ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['book_appointment'])) {
    verifyCsrf();
    $doctorId   = (int)($_POST['doctor_id']  ?? 0) ?: null;
    $date       = $_POST['date']             ?? date('Y-m-d');
    $time       = $_POST['time']             ?? '09:00:00';
    $type       = sanitize($_POST['type']    ?? 'General Consultation');
    $complaint  = sanitize($_POST['complaint'] ?? '');
    try {
        $pdo->prepare("INSERT INTO health_appointments (org_id,patient_id,doctor_id,date,time,type,complaint,status) VALUES (?,?,?,?,?,?,?,'scheduled')")
            ->execute([$orgId, $patientId, $doctorId, $date, $time, $type, $complaint]);
        setFlash('success', 'Appointment requested successfully. You will be notified of confirmation.');
    } catch (Throwable $e) { setFlash('error', 'Could not book appointment. Please try again.'); }
    redirect(APP_URL . '/patient/appointments.php');
}

// ── Cancel appointment ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['cancel_appointment'])) {
    verifyCsrf();
    $aid = (int)($_POST['appointment_id'] ?? 0);
    $pdo->prepare("UPDATE health_appointments SET status='cancelled' WHERE id=? AND patient_id=? AND org_id=? AND status='scheduled'")
        ->execute([$aid, $patientId, $orgId]);
    setFlash('success', 'Appointment cancelled.');
    redirect(APP_URL . '/patient/appointments.php');
}

$appointments = [];
try {
    $s = $pdo->prepare("
        SELECT a.*,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name, d.specialization
        FROM health_appointments a
        LEFT JOIN health_doctors d ON a.doctor_id=d.id
        WHERE a.patient_id=? AND a.org_id=?
        ORDER BY a.date DESC, a.time DESC
    ");
    $s->execute([$patientId, $orgId]);
    $appointments = $s->fetchAll();
} catch (Throwable $e) {}

$doctors = [];
try {
    $s = $pdo->prepare("SELECT id, first_name, last_name, specialization FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name");
    $s->execute([$orgId]);
    $doctors = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['scheduled'=>'info','completed'=>'success','cancelled'=>'secondary','no_show'=>'warning'];
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-calendar-check me-2 text-danger"></i>My Appointments</h5>
    <p class="text-muted small mb-0">View past and upcoming consultations</p>
  </div>
  <button class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#bookModal">
    <i class="fas fa-plus me-1"></i>Request Appointment
  </button>
</div>

<?= flashAlert() ?>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Date &amp; Time</th><th>Type</th><th>Doctor</th><th>Complaint</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php if (empty($appointments)): ?>
          <tr><td colspan="6" class="text-center py-5 text-muted"><i class="fas fa-calendar-times fa-2x mb-2 d-block opacity-25"></i>No appointments yet</td></tr>
          <?php else: foreach ($appointments as $a):
            $bg = $statusColors[$a['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold small"><?= formatDate($a['date']) ?></div>
              <div class="text-muted small"><?= date('h:i A', strtotime($a['time'])) ?></div>
            </td>
            <td><span class="badge bg-light text-dark border small"><?= e($a['type']) ?></span></td>
            <td>
              <div class="small fw-semibold"><?= e($a['doctor_name'] ?: 'Duty Physician') ?></div>
              <div class="text-muted small"><?= e($a['specialization'] ?? '') ?></div>
            </td>
            <td class="small text-muted"><?= e(truncate($a['complaint'] ?? '—', 50)) ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst(str_replace('_',' ',$a['status'])) ?></span></td>
            <td>
              <?php if ($a['status'] === 'scheduled' && $a['date'] >= date('Y-m-d')): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this appointment?')">
                <?= csrfField() ?>
                <input type="hidden" name="cancel_appointment" value="1">
                <input type="hidden" name="appointment_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Book Modal -->
<div class="modal fade" id="bookModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?><input type="hidden" name="book_appointment" value="1">
      <div class="modal-header text-white" style="background:var(--pat-red)">
        <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Request Appointment</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Preferred Doctor</label>
            <select name="doctor_id" class="form-select">
              <option value="">Any available doctor</option>
              <?php foreach ($doctors as $d): ?>
              <option value="<?= $d['id'] ?>"><?= e($d['first_name'].' '.$d['last_name']) ?> (<?= e($d['specialization']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label fw-semibold">Preferred Date</label><input type="date" name="date" class="form-control" required min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>"></div>
          <div class="col-6"><label class="form-label fw-semibold">Preferred Time</label><input type="time" name="time" class="form-control" value="09:00"></div>
          <div class="col-12"><label class="form-label fw-semibold">Consultation Type</label><input type="text" name="type" class="form-control" value="General Consultation" placeholder="e.g. General Consultation, Follow-up"></div>
          <div class="col-12"><label class="form-label fw-semibold">Primary Complaint / Reason</label><textarea name="complaint" class="form-control" rows="3" placeholder="Describe your symptoms or reason for the visit…"></textarea></div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-danger"><i class="fas fa-paper-plane me-1"></i>Submit Request</button>
      </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
