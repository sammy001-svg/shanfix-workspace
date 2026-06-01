<?php
$pageTitle = 'My Prescriptions';
require_once __DIR__ . '/../includes/header-patient.php';

$prescriptions = [];
try {
    $s = $pdo->prepare("
        SELECT m.*, ord.name AS ordered_by_name, adm.name AS administered_by_name
        FROM health_mar m
        LEFT JOIN users ord ON ord.id=m.ordered_by
        LEFT JOIN users adm ON adm.id=m.administered_by
        WHERE m.patient_id=? AND m.org_id=?
        ORDER BY m.status='active' DESC, m.start_date DESC
    ");
    $s->execute([$patientId, $orgId]);
    $prescriptions = $s->fetchAll();
} catch (Throwable $e) {}

$routeLabels = ['oral'=>'Oral (PO)','iv'=>'IV','im'=>'IM','sc'=>'SC','topical'=>'Topical','inhaled'=>'Inhaled','other'=>'Other'];
$statusColors = ['active'=>'success','completed'=>'primary','discontinued'=>'secondary'];
?>

<div class="mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-prescription me-2 text-danger"></i>My Prescriptions</h5>
  <p class="text-muted small mb-0">Current and past medication orders</p>
</div>

<?= flashAlert() ?>

<?php if (empty($prescriptions)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-pills fa-3x mb-3 d-block opacity-25"></i><p>No prescriptions on record.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Medicine</th><th>Dose &amp; Route</th><th>Frequency</th><th>Period</th><th>Status</th><th>Last Given</th></tr>
        </thead>
        <tbody>
          <?php foreach ($prescriptions as $p):
            $bg = $statusColors[$p['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold"><?= e($p['medicine_name']) ?></td>
            <td>
              <div class="small"><?= e($p['dose']) ?></div>
              <span class="badge bg-light text-dark border" style="font-size:.65rem"><?= $routeLabels[$p['route']] ?? $p['route'] ?></span>
            </td>
            <td class="small"><?= e($p['frequency'] ?: '—') ?></td>
            <td class="small">
              <?= formatDate($p['start_date']) ?>
              <?php if ($p['end_date']): ?> → <?= formatDate($p['end_date']) ?><?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($p['status']) ?></span></td>
            <td class="small text-muted">
              <?= $p['administered_at'] ? date('d M Y H:i', strtotime($p['administered_at'])) : '—' ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
