<?php
$pageTitle = 'Module Management';
require_once __DIR__ . '/../includes/header-admin.php';

$modules = $pdo->query("
    SELECT m.*, COUNT(sm.id) as subscriber_count
    FROM modules m
    LEFT JOIN subscription_modules sm ON m.id = sm.module_id AND sm.status='active'
    GROUP BY m.id ORDER BY m.sort_order
")->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-puzzle-piece me-2 text-green"></i>Module Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../admin/index.php">Dashboard</a></li><li class="breadcrumb-item active">Modules</li></ol></nav>
  </div>
</div>

<div class="row g-3">
  <?php foreach($modules as $m): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <div style="width:48px;height:48px;border-radius:12px;background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
            <i class="<?= e($m['icon']) ?>"></i>
          </div>
          <div class="flex-1">
            <div class="fw-700 text-navy"><?= e($m['name']) ?></div>
            <div class="text-muted small mb-2"><?= e($m['category']) ?></div>
            <div class="d-flex gap-2 mb-2">
              <span class="badge bg-success"><?= $m['subscriber_count'] ?> subscribers</span>
              <?= statusBadge($m['status']) ?>
            </div>
            <div class="small text-muted"><?= e(substr($m['description'],0,80)) ?>...</div>
          </div>
        </div>
        <hr class="my-2">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="small fw-600">KES <?= number_format($m['monthly_price']) ?>/mo</div>
            <div class="small text-muted">KES <?= number_format($m['annual_price']) ?>/yr</div>
          </div>
          <div class="d-flex gap-1">
            <button class="btn btn-xs btn-outline-secondary" title="Edit pricing"><i class="fas fa-edit"></i></button>
            <button class="btn btn-xs <?= $m['status']==='active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                    title="<?= $m['status']==='active' ? 'Deactivate' : 'Activate' ?>">
              <i class="fas fa-<?= $m['status']==='active' ? 'pause' : 'play' ?>"></i>
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
