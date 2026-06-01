<?php
$pageTitle = 'School Notices';
require_once __DIR__ . '/../includes/header-parent.php';

$classId = (int)($activeStudent['class_id'] ?? 0);

// ── Notices for this class or all ──────────────────────────────
$notices = [];
try {
    $s = $pdo->prepare(
        "SELECT n.*, c.name AS class_name
         FROM sch_notices n
         LEFT JOIN sch_classes c ON n.class_id = c.id
         WHERE n.org_id=?
           AND (n.audience='all' OR n.audience='parents'
                OR n.class_id IS NULL OR n.class_id=?)
         ORDER BY n.created_at DESC LIMIT 50"
    );
    $s->execute([$parOrgId, $classId]);
    $notices = $s->fetchAll();
} catch (Throwable $e) {}

$audienceIcons = ['all'=>'fa-globe','parents'=>'fa-users','students'=>'fa-graduation-cap','staff'=>'fa-user-tie'];
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-bullhorn me-2" style="color:#f39c12"></i>School Notices</h5>
  <span class="badge bg-warning text-dark ms-2"><?= count($notices) ?></span>
</div>

<?php if (empty($notices)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-bullhorn fa-3x mb-3 d-block opacity-25"></i>
    <h6>No notices at the moment</h6>
    <p class="small">New school announcements will appear here.</p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($notices as $n):
    $aud = strtolower($n['audience'] ?? 'all');
    $aIcon = $audienceIcons[$aud] ?? 'fa-globe';
  ?>
  <div class="col-12">
    <div class="card border-0 shadow-sm" style="border-left:3px solid #f39c12!important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <h6 class="fw-bold mb-0"><?= e($n['title']) ?></h6>
          <div class="d-flex gap-2 flex-shrink-0">
            <?php if (!empty($n['class_name'])): ?>
            <span class="badge bg-success bg-opacity-25 text-success" style="font-size:.7rem">
              <i class="fas fa-chalkboard me-1"></i><?= e($n['class_name']) ?>
            </span>
            <?php endif; ?>
            <span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.7rem">
              <i class="fas <?= $aIcon ?> me-1"></i><?= ucfirst($aud) ?>
            </span>
            <span class="text-muted" style="font-size:.72rem;white-space:nowrap"><?= date('d M Y', strtotime($n['created_at'])) ?></span>
          </div>
        </div>
        <p class="text-muted mb-0" style="font-size:.88rem;line-height:1.6"><?= nl2br(e($n['content'] ?? '')) ?></p>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
