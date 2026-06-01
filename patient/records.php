<?php
$pageTitle = 'Medical Records';
require_once __DIR__ . '/../includes/header-patient.php';

$records = [];
try {
    $s = $pdo->prepare("
        SELECT r.*,
               CONCAT(d.first_name,' ',d.last_name) AS doctor_name, d.specialization
        FROM health_records r
        LEFT JOIN health_doctors d ON r.doctor_id=d.id
        WHERE r.patient_id=? AND r.org_id=?
        ORDER BY r.created_at DESC
    ");
    $s->execute([$patientId, $orgId]);
    $records = $s->fetchAll();
} catch (Throwable $e) {}
?>

<div class="mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-file-medical me-2 text-danger"></i>My Medical Records</h5>
  <p class="text-muted small mb-0">Your complete clinical history</p>
</div>

<?= flashAlert() ?>

<?php if (empty($records)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-file-medical fa-3x mb-3 d-block opacity-25"></i>
    <p>No medical records on file yet.</p>
  </div>
</div>
<?php else: foreach ($records as $r): ?>
<div class="card border-0 shadow-sm mb-3">
  <div class="card-header d-flex align-items-center justify-content-between py-2" style="background:#f8f9fa">
    <div>
      <span class="fw-bold text-danger small"><?= formatDate($r['created_at']) ?></span>
      <span class="text-muted small ms-2">by <?= e($r['doctor_name'] ?: 'Unknown') ?><?= $r['specialization'] ? ' · '.e($r['specialization']) : '' ?></span>
    </div>
    <?php if (!empty($r['follow_up_date'])): ?>
    <span class="badge bg-info">Follow-up: <?= formatDate($r['follow_up_date']) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <div class="row g-3">
      <?php if (!empty($r['diagnosis'])): ?>
      <div class="col-md-6">
        <div class="text-muted small fw-semibold mb-1"><i class="fas fa-stethoscope me-1"></i>Diagnosis</div>
        <div><?= e($r['diagnosis']) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($r['treatment'])): ?>
      <div class="col-md-6">
        <div class="text-muted small fw-semibold mb-1"><i class="fas fa-pills me-1"></i>Treatment</div>
        <div><?= e($r['treatment']) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($r['prescription'])): ?>
      <div class="col-md-6">
        <div class="text-muted small fw-semibold mb-1"><i class="fas fa-prescription me-1"></i>Prescription</div>
        <div class="small"><?= nl2br(e($r['prescription'])) ?></div>
      </div>
      <?php endif; ?>
      <?php if (!empty($r['notes'])): ?>
      <div class="col-md-6">
        <div class="text-muted small fw-semibold mb-1"><i class="fas fa-sticky-note me-1"></i>Notes</div>
        <div class="small text-muted"><?= e($r['notes']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; endif; ?>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
