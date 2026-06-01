<?php
$pageTitle = 'Vital Signs';
require_once __DIR__ . '/../includes/header-patient.php';

$vitals = [];
try {
    $s = $pdo->prepare("SELECT * FROM health_vitals WHERE patient_id=? AND org_id=? ORDER BY recorded_at DESC LIMIT 50");
    $s->execute([$patientId, $orgId]);
    $vitals = $s->fetchAll();
} catch (Throwable $e) {}
?>

<div class="mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-heartbeat me-2 text-danger"></i>Vital Signs History</h5>
  <p class="text-muted small mb-0">Last 50 recorded observations</p>
</div>

<?= flashAlert() ?>

<?php if (empty($vitals)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-heartbeat fa-3x mb-3 d-block opacity-25"></i><p>No vitals recorded yet.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr><th>Date</th><th>BP</th><th>Pulse</th><th>Temp (°C)</th><th>Weight (kg)</th><th>SPO2 (%)</th><th>Pain</th></tr>
        </thead>
        <tbody>
          <?php foreach ($vitals as $v): ?>
          <tr>
            <td class="small"><?= date('d M Y H:i', strtotime($v['recorded_at'])) ?></td>
            <td class="small fw-semibold">
              <?= ($v['bp_systolic'] && $v['bp_diastolic']) ? e($v['bp_systolic'].'/'.$v['bp_diastolic']) : '—' ?>
            </td>
            <td class="small"><?= $v['pulse'] ? e($v['pulse']).' bpm' : '—' ?></td>
            <td class="small"><?= $v['temperature'] ? e($v['temperature']) : '—' ?></td>
            <td class="small"><?= $v['weight'] ? e($v['weight']) : '—' ?></td>
            <td class="small"><?= $v['spo2'] ? e($v['spo2']).'%' : '—' ?></td>
            <td class="small"><?= isset($v['pain_scale']) && $v['pain_scale'] !== null ? e($v['pain_scale']).'/10' : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
