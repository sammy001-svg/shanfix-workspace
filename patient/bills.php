<?php
$pageTitle = 'My Bills';
require_once __DIR__ . '/../includes/header-patient.php';

$bills = [];
$totalOwed = 0;
try {
    $s = $pdo->prepare("
        SELECT * FROM health_bills
        WHERE patient_id=? AND org_id=?
        ORDER BY created_at DESC
    ");
    $s->execute([$patientId, $orgId]);
    $bills = $s->fetchAll();

    $ts = $pdo->prepare("SELECT COALESCE(SUM(total_amount - paid_amount),0) FROM health_bills WHERE patient_id=? AND org_id=? AND status NOT IN ('paid','cancelled')");
    $ts->execute([$patientId, $orgId]);
    $totalOwed = (float)$ts->fetchColumn();
} catch (Throwable $e) {}

$statusColors = ['draft'=>'secondary','sent'=>'info','partial'=>'warning','paid'=>'success','cancelled'=>'secondary','overdue'=>'danger'];
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-receipt me-2 text-danger"></i>My Bills</h5>
    <p class="text-muted small mb-0">Hospital billing and payment history</p>
  </div>
  <?php if ($totalOwed > 0): ?>
  <div class="text-end">
    <div class="text-muted small">Total Outstanding</div>
    <div class="fw-bold fs-5 text-danger"><?= formatCurrency($totalOwed) ?></div>
  </div>
  <?php endif; ?>
</div>

<?= flashAlert() ?>

<?php if (empty($bills)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-receipt fa-3x mb-3 d-block opacity-25"></i><p>No bills on record.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Bill No</th><th>Date</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($bills as $b):
            $balance = (float)$b['total_amount'] - (float)$b['paid_amount'];
            $bg = $statusColors[$b['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold font-monospace small"><?= e($b['bill_no'] ?? '#'.$b['id']) ?></td>
            <td class="small"><?= formatDate($b['created_at']) ?></td>
            <td class="fw-semibold"><?= formatCurrency($b['total_amount']) ?></td>
            <td class="text-success"><?= formatCurrency($b['paid_amount']) ?></td>
            <td class="<?= $balance > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= formatCurrency($balance) ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($b['status']) ?></span></td>
            <td>
              <a href="<?= APP_URL ?>/modules/health/invoice-pdf.php?id=<?= $b['id'] ?>" target="_blank"
                 class="btn btn-xs btn-outline-secondary" title="Print Invoice">
                <i class="fas fa-print me-1"></i>Invoice
              </a>
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
