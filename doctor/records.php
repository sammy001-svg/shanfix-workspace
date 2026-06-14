<?php
$pageTitle = 'Medical Records';
require_once __DIR__ . '/../includes/header-doctor.php';

// Prefilled from appointment/patient links
$prePatientId = (int)($_GET['patient_id'] ?? 0);
$preApptId    = (int)($_GET['appt_id']    ?? 0);

// Handle POST: save a new record
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_record') {
        $patientId = (int)($_POST['patient_id'] ?? 0);
        $apptId    = (int)($_POST['appointment_id'] ?? 0) ?: null;
        $date      = $_POST['date'] ?? date('Y-m-d');
        $diagnosis = sanitize($_POST['diagnosis'] ?? '');
        $treatment = sanitize($_POST['treatment'] ?? '');
        $notes     = sanitize($_POST['notes'] ?? '');
        $followUp  = $_POST['follow_up_date'] ?? null;
        if ($followUp === '') $followUp = null;

        if (!$patientId || !$diagnosis) {
            setFlash('error', 'Patient and diagnosis are required.');
        } else {
            try {
                $pdo->prepare("INSERT INTO health_records (org_id,patient_id,doctor_id,appointment_id,date,diagnosis,treatment,notes,follow_up_date)
                               VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$docOrgId, $patientId, $docId, $apptId, $date, $diagnosis, $treatment, $notes, $followUp]);
                if ($apptId) {
                    $pdo->prepare("UPDATE health_appointments SET status='completed' WHERE id=? AND org_id=?")
                        ->execute([$apptId, $docOrgId]);
                }
                setFlash('success', 'Medical record saved successfully.');
            } catch (Throwable $err) {
                setFlash('error', 'Could not save record. Please try again.');
            }
        }
        redirect(APP_URL . '/doctor/records.php?patient_id=' . $patientId);
    }
}

// Load patients for dropdown (doctor's patients)
$myPatients = [];
try {
    $s = $pdo->prepare("
        SELECT DISTINCT p.id, CONCAT(p.first_name,' ',p.last_name) AS name, p.patient_no
        FROM health_patients p
        WHERE p.org_id=? AND (
            p.id IN (SELECT patient_id FROM health_appointments WHERE doctor_id=? AND org_id=p.org_id)
            OR p.id IN (SELECT patient_id FROM health_records WHERE doctor_id=? AND org_id=p.org_id)
        )
        ORDER BY p.first_name
    ");
    $s->execute([$docOrgId, $docId, $docId]);
    $myPatients = $s->fetchAll();
} catch (Throwable $e) {}

// Load records for selected patient
$records = [];
$selectedPatient = null;
if ($prePatientId) {
    try {
        $s = $pdo->prepare("SELECT first_name, last_name, patient_no FROM health_patients WHERE id=? AND org_id=? LIMIT 1");
        $s->execute([$prePatientId, $docOrgId]);
        $selectedPatient = $s->fetch();

        $s = $pdo->prepare("SELECT r.*, u.name AS doctor_name FROM health_records r LEFT JOIN users u ON u.id=(SELECT user_id FROM health_doctors WHERE id=r.doctor_id LIMIT 1) WHERE r.patient_id=? AND r.org_id=? ORDER BY r.date DESC, r.id DESC LIMIT 50");
        $s->execute([$prePatientId, $docOrgId]);
        $records = $s->fetchAll();
    } catch (Throwable $e) {}
}
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-file-medical me-2" style="color:var(--doc-blue)"></i>Medical Records</h5>
    <p class="text-muted small mb-0">View history and write new clinical notes</p>
  </div>
  <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#newRecordModal">
    <i class="fas fa-plus me-1"></i>New Record
  </button>
</div>

<!-- Patient selector -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 px-3">
    <form class="d-flex gap-2 align-items-end flex-wrap">
      <div>
        <label class="form-label small mb-1">Select Patient</label>
        <select name="patient_id" class="form-select form-select-sm" style="min-width:220px" onchange="this.form.submit()">
          <option value="">— Choose a patient —</option>
          <?php foreach ($myPatients as $mp): ?>
          <option value="<?= $mp['id'] ?>" <?= $prePatientId==$mp['id']?'selected':'' ?>>
            <?= e($mp['name']) ?> (<?= e($mp['patient_no'] ?? '#'.$mp['id']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if ($prePatientId && $selectedPatient): ?>
<!-- Records list -->
<div class="d-flex align-items-center mb-2 gap-2">
  <span class="fw-semibold"><?= e($selectedPatient['first_name'].' '.$selectedPatient['last_name']) ?></span>
  <span class="text-muted small">#<?= e($selectedPatient['patient_no'] ?? $prePatientId) ?></span>
</div>

<?php if (empty($records)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-4 text-muted">
    <i class="fas fa-file-medical fa-2x mb-2 d-block opacity-25"></i>
    No records found for this patient. Use the "New Record" button above.
  </div>
</div>
<?php else: ?>
<div class="d-flex flex-column gap-3">
  <?php foreach ($records as $r): ?>
  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-0 d-flex align-items-start justify-content-between py-2 px-3">
      <div>
        <div class="fw-semibold small"><?= date('d M Y', strtotime($r['date'])) ?></div>
        <?php if ($r['doctor_name']): ?><div class="text-muted" style="font-size:.75rem">Dr. <?= e($r['doctor_name']) ?></div><?php endif; ?>
      </div>
      <?php if ($r['follow_up_date']): ?>
      <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:.72rem">
        Follow-up: <?= date('d M Y', strtotime($r['follow_up_date'])) ?>
      </span>
      <?php endif; ?>
    </div>
    <div class="card-body pt-1 px-3 pb-3">
      <div class="row g-2">
        <div class="col-md-4">
          <div class="text-muted small fw-semibold mb-1">Diagnosis</div>
          <div class="small"><?= nl2br(e($r['diagnosis'] ?? '—')) ?></div>
        </div>
        <div class="col-md-4">
          <div class="text-muted small fw-semibold mb-1">Treatment / Plan</div>
          <div class="small"><?= nl2br(e($r['treatment'] ?? '—')) ?></div>
        </div>
        <div class="col-md-4">
          <div class="text-muted small fw-semibold mb-1">Notes</div>
          <div class="small"><?= nl2br(e($r['notes'] ?? '—')) ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php elseif (!empty($myPatients)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-user-md fa-2x mb-2 d-block opacity-25"></i>
    Select a patient above to view their medical history.
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-procedures fa-2x mb-2 d-block opacity-25"></i>
    No patient history yet. Patients will appear here after you see them in appointments.
  </div>
</div>
<?php endif; ?>

<!-- New Record Modal -->
<div class="modal fade" id="newRecordModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <input type="hidden" name="action" value="save_record">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="fas fa-file-medical me-2" style="color:var(--doc-blue)"></i>New Medical Record</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Patient <span class="text-danger">*</span></label>
              <select name="patient_id" class="form-select form-select-sm" required>
                <option value="">— Select patient —</option>
                <?php foreach ($myPatients as $mp): ?>
                <option value="<?= $mp['id'] ?>" <?= $prePatientId==$mp['id']?'selected':'' ?>>
                  <?= e($mp['name']) ?> (#<?= e($mp['patient_no'] ?? $mp['id']) ?>)
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Date</label>
              <input type="date" name="date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Appointment ID</label>
              <input type="number" name="appointment_id" class="form-control form-control-sm"
                     value="<?= $preApptId ?: '' ?>" placeholder="Optional">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Diagnosis <span class="text-danger">*</span></label>
              <textarea name="diagnosis" class="form-control form-control-sm" rows="2" required
                        placeholder="Chief complaint and clinical diagnosis..."></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Treatment / Management Plan</label>
              <textarea name="treatment" class="form-control form-control-sm" rows="3"
                        placeholder="Treatment plan, procedures, referrals..."></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Clinical Notes</label>
              <textarea name="notes" class="form-control form-control-sm" rows="3"
                        placeholder="Examination findings, vitals, comments..."></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label small fw-semibold">Follow-up Date</label>
              <input type="date" name="follow_up_date" class="form-control form-control-sm">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i>Save Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-doctor.php'; ?>
