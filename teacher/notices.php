<?php
$pageTitle = 'Notices';
require_once __DIR__ . '/../includes/header-teacher.php';

// Load notices for staff and all
$notices = [];
try {
    $s = $pdo->prepare(
        "SELECT n.*, c.name AS class_name
         FROM sch_notices n
         LEFT JOIN sch_classes c ON n.class_id = c.id
         WHERE n.org_id=?
           AND (n.audience IN ('all','staff','teachers') OR n.audience IS NULL)
           AND (n.expiry_date IS NULL OR n.expiry_date >= CURDATE())
         ORDER BY n.is_pinned DESC, n.created_at DESC
         LIMIT 60"
    );
    $s->execute([$tchOrgId]);
    $notices = $s->fetchAll();
} catch (Throwable $e) {}

$priorityConfig = [
    'urgent'    => ['bg'=>'#fde8e8','border'=>'#e74c3c','badge'=>'danger',   'icon'=>'fa-exclamation-circle'],
    'important' => ['bg'=>'#fff3cd','border'=>'#f39c12','badge'=>'warning',   'icon'=>'fa-exclamation-triangle'],
    'normal'    => ['bg'=>'#ffffff','border'=>'#dee2e6','badge'=>'secondary', 'icon'=>'fa-info-circle'],
];

$pinned  = array_filter($notices, fn($n) => !empty($n['is_pinned']));
$regular = array_filter($notices, fn($n) =>  empty($n['is_pinned']));
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-bullhorn me-2" style="color:#f39c12"></i>School Notices</h5>
  <?php if (count($notices)): ?>
  <span class="badge bg-warning text-dark"><?= count($notices) ?> notice<?= count($notices)!==1?'s':'' ?></span>
  <?php endif; ?>
</div>

<?php if (empty($notices)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-bullhorn fa-3x mb-3 d-block opacity-25"></i>
    <h6>No notices at the moment</h6>
    <p class="small mb-0">School announcements will appear here when published by admin.</p>
  </div>
</div>

<?php else: ?>

<?php if (!empty($pinned)): ?>
<div class="mb-2" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-thumbtack me-1"></i>Pinned
</div>
<?php foreach ($pinned as $n):
  $pri = strtolower($n['priority'] ?? 'normal');
  $pc  = $priorityConfig[$pri] ?? $priorityConfig['normal'];
?>
<div class="card border-0 shadow-sm mb-3" style="border-left:4px solid <?= $pc['border'] ?>!important;background:<?= $pc['bg'] ?>">
  <div class="card-body">
    <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
      <div class="d-flex align-items-center gap-2">
        <i class="fas fa-thumbtack text-warning" style="font-size:.8rem"></i>
        <h6 class="fw-bold mb-0"><?= e($n['title']) ?></h6>
      </div>
      <div class="d-flex gap-2 flex-shrink-0 align-items-center">
        <?php if ($pri !== 'normal'): ?>
        <span class="badge bg-<?= $pc['badge'] ?>" style="font-size:.68rem">
          <i class="fas <?= $pc['icon'] ?> me-1"></i><?= ucfirst($pri) ?>
        </span>
        <?php endif; ?>
        <?php if (!empty($n['class_name'])): ?>
        <span class="badge bg-success bg-opacity-25 text-success" style="font-size:.68rem">
          <i class="fas fa-chalkboard me-1"></i><?= e($n['class_name']) ?>
        </span>
        <?php endif; ?>
        <span class="text-muted" style="font-size:.7rem;white-space:nowrap"><?= date('d M Y', strtotime($n['created_at'])) ?></span>
      </div>
    </div>
    <p class="text-muted mb-0" style="font-size:.875rem;line-height:1.65"><?= nl2br(e($n['content'] ?? '')) ?></p>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($pinned) && !empty($regular)): ?>
<div class="mb-2 mt-4" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  All Notices
</div>
<?php endif; ?>

<div class="row g-3">
  <?php foreach ($regular as $n):
    $pri = strtolower($n['priority'] ?? 'normal');
    $pc  = $priorityConfig[$pri] ?? $priorityConfig['normal'];
  ?>
  <div class="col-12">
    <div class="card border-0 shadow-sm" style="border-left:3px solid <?= $pc['border'] ?>!important">
      <div class="card-body py-3">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-1">
          <h6 class="fw-semibold mb-0" style="font-size:.9rem"><?= e($n['title']) ?></h6>
          <div class="d-flex gap-2 flex-shrink-0 align-items-center">
            <?php if ($pri !== 'normal'): ?>
            <span class="badge bg-<?= $pc['badge'] ?> bg-opacity-75" style="font-size:.65rem"><?= ucfirst($pri) ?></span>
            <?php endif; ?>
            <?php if (!empty($n['class_name'])): ?>
            <span class="badge bg-success bg-opacity-25 text-success" style="font-size:.65rem">
              <i class="fas fa-chalkboard me-1"></i><?= e($n['class_name']) ?>
            </span>
            <?php endif; ?>
            <span class="text-muted" style="font-size:.7rem;white-space:nowrap"><?= date('d M Y', strtotime($n['created_at'])) ?></span>
          </div>
        </div>
        <p class="text-muted mb-0" style="font-size:.855rem;line-height:1.6"><?= nl2br(e($n['content'] ?? '')) ?></p>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
