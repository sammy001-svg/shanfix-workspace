<?php
$pageTitle = 'Lab Results';
require_once __DIR__ . '/../includes/header-patient.php';

$labOrders = [];
try {
    $s = $pdo->prepare("
        SELECT lo.*, lt.name AS test_name, lt.normal_range, lt.unit, lt.category
        FROM health_lab_orders lo
        JOIN health_lab_tests lt ON lo.test_id=lt.id
        WHERE lo.patient_id=? AND lo.org_id=?
        ORDER BY lo.created_at DESC
    ");
    $s->execute([$patientId, $orgId]);
    $labOrders = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['pending'=>'warning','collected'=>'info','processing'=>'primary','completed'=>'success','cancelled'=>'secondary'];
?>

<div class="mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-flask me-2 text-danger"></i>My Lab Results</h5>
  <p class="text-muted small mb-0">Laboratory test orders and results</p>
</div>

<?= flashAlert() ?>

<?php if (empty($labOrders)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-flask fa-3x mb-3 d-block opacity-25"></i><p>No lab tests ordered yet.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Test</th><th>Category</th><th>Date Ordered</th><th>Status</th><th>Result</th><th>Normal Range</th></tr>
        </thead>
        <tbody>
          <?php foreach ($labOrders as $lo):
            $bg = $statusColors[$lo['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold"><?= e($lo['test_name']) ?></td>
            <td><span class="badge bg-light text-dark border small"><?= e($lo['category'] ?? '—') ?></span></td>
            <td class="small"><?= formatDate($lo['created_at']) ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($lo['status']) ?></span></td>
            <td>
              <?php if ($lo['status'] === 'completed' && !empty($lo['result'])): ?>
              <span class="fw-semibold"><?= e($lo['result']) ?> <?= e($lo['unit'] ?? '') ?></span>
              <?php else: ?>
              <span class="text-muted small"><?= $lo['status'] === 'completed' ? 'See doctor' : 'Pending' ?></span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($lo['normal_range'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-patient.php'; ?>
