<?php
$pageTitle = 'Subscriptions';
require_once __DIR__ . '/../includes/header-admin.php';

$subscriptions = $pdo->query("
    SELECT s.*, o.name as org_name, o.email as org_email,
           p.name as plan_name,
           COUNT(DISTINCT sm.module_id) as module_count
    FROM subscriptions s
    JOIN organizations o ON s.org_id = o.id
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id AND sm.status='active'
    GROUP BY s.id ORDER BY s.created_at DESC
")->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-credit-card me-2 text-green"></i>Subscriptions</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../admin/index.php">Dashboard</a></li><li class="breadcrumb-item active">Subscriptions</li></ol></nav>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  $counts = [
    ['Active',    $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active'")->fetchColumn(),    'green','fas fa-check-circle'],
    ['On Trial',  $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='trial'")->fetchColumn(),    'warning','fas fa-clock'],
    ['Expired',   $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='expired'")->fetchColumn(),  'danger','fas fa-times-circle'],
    ['Cancelled', $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='cancelled'")->fetchColumn(),'secondary','fas fa-ban'],
  ];
  foreach($counts as $c): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $c[2] ?>">
      <div class="stat-icon <?= $c[2] ?>-bg"><i class="<?= $c[3] ?>"></i></div>
      <div><div class="stat-value"><?= $c[1] ?></div><div class="stat-label"><?= $c[0] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table">
        <thead>
          <tr><th>#</th><th>Organization</th><th>Plan</th><th>Modules</th><th>Billing</th><th>Amount</th><th>Status</th><th>Trial/Expiry</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($subscriptions as $i => $s): ?>
          <tr>
            <td class="text-muted small"><?= $i+1 ?></td>
            <td>
              <div class="fw-600"><?= e($s['org_name']) ?></div>
              <div class="text-muted small"><?= e($s['org_email']) ?></div>
            </td>
            <td><span class="badge bg-info text-dark"><?= e($s['plan_name'] ?? 'Custom') ?></span></td>
            <td><span class="badge bg-primary"><?= $s['module_count'] ?> modules</span></td>
            <td class="small text-capitalize"><?= $s['billing_cycle'] ?></td>
            <td class="fw-600"><?= formatCurrency((float)$s['amount']) ?></td>
            <td><?= statusBadge($s['status']) ?></td>
            <td class="small">
              <?php if ($s['status'] === 'trial' && $s['trial_ends_at']): ?>
              <span class="text-warning"><?= formatDate($s['trial_ends_at']) ?></span>
              <?php elseif ($s['ends_at']): ?>
              <?= formatDate($s['ends_at']) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $s['org_id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fas fa-eye"></i></a>
                <button class="btn btn-xs btn-outline-success" title="Activate" onclick="updateStatus(<?= $s['id'] ?>,'active')"><i class="fas fa-check"></i></button>
                <button class="btn btn-xs btn-outline-danger"  title="Cancel"   onclick="updateStatus(<?= $s['id'] ?>,'cancelled')"><i class="fas fa-times"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function updateStatus(id, status) {
  if (!confirm("Change subscription status to " + status + "?")) return;
  fetch("' . APP_URL . '/admin/ajax.php", {
    method: "POST",
    headers: {"Content-Type":"application/json"},
    body: JSON.stringify({action:"update_sub_status", id, status})
  }).then(() => location.reload());
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
