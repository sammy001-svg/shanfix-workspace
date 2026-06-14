<?php
$pageTitle = 'Notices';
require_once __DIR__ . '/../includes/header-student.php';

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 12;
$offset  = ($page - 1) * $perPage;

// ── Notices for students ─────────────────────────────────────────
$notices = []; $totalCount = 0;
try {
    $s = $pdo->prepare(
        "SELECT COUNT(*) FROM sch_notices
         WHERE org_id=? AND audience IN ('all','students')
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())"
    );
    $s->execute([$stuOrgId]);
    $totalCount = (int)$s->fetchColumn();

    $s = $pdo->prepare(
        "SELECT id, title, content, audience, priority, created_at, expiry_date
         FROM sch_notices
         WHERE org_id=? AND audience IN ('all','students')
           AND (expiry_date IS NULL OR expiry_date >= CURDATE())
         ORDER BY
           CASE priority WHEN 'urgent' THEN 1 WHEN 'important' THEN 2 ELSE 3 END,
           created_at DESC
         LIMIT ? OFFSET ?"
    );
    $s->execute([$stuOrgId, $perPage, $offset]);
    $notices = $s->fetchAll();
} catch (Throwable $e) {}

$totalPages = (int)ceil($totalCount / $perPage);

$priorityConfig = [
    'urgent'    => ['color'=>'#e74c3c','bg'=>'#fef2f2','badge'=>'danger',  'icon'=>'fas fa-bullhorn'],
    'important' => ['color'=>'#f39c12','bg'=>'#fef5e7','badge'=>'warning', 'icon'=>'fas fa-exclamation-circle'],
    'normal'    => ['color'=>'#1d4ed8','bg'=>'#eff6ff','badge'=>'primary', 'icon'=>'fas fa-info-circle'],
];
$audienceLabel = ['all'=>'Everyone','students'=>'Students','parents'=>'Parents','teachers'=>'Teachers'];
?>

<h5 class="fw-bold mb-4"><i class="fas fa-bullhorn me-2" style="color:var(--stu-blue)"></i>School Notices</h5>

<?php if (empty($notices)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-bullhorn fa-3x mb-3 d-block opacity-25"></i>
  <h6>No notices at the moment</h6>
  <p class="small mb-0">Check back later for school announcements.</p>
</div>
<?php else: ?>

<div class="row g-3">
<?php foreach ($notices as $notice):
    $prio = $priorityConfig[$notice['priority']] ?? $priorityConfig['normal'];
    $isNew = strtotime($notice['created_at']) > strtotime('-3 days');
    $isExpiring = !empty($notice['expiry_date'])
        && strtotime($notice['expiry_date']) <= strtotime('+3 days');
?>
<div class="col-12 col-lg-6">
  <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?= $prio['color'] ?>!important">
    <div class="card-body">
      <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
        <div class="d-flex align-items-start gap-2">
          <div class="d-flex align-items-center justify-content-center rounded-circle flex-shrink-0"
               style="width:36px;height:36px;background:<?= $prio['bg'] ?>">
            <i class="<?= $prio['icon'] ?>" style="color:<?= $prio['color'] ?>;font-size:.8rem"></i>
          </div>
          <div>
            <div class="fw-bold mb-1 lh-sm"><?= e($notice['title']) ?></div>
            <div class="d-flex flex-wrap gap-1">
              <span class="badge bg-<?= $prio['badge'] ?>" style="font-size:.62rem"><?= ucfirst($notice['priority']) ?></span>
              <span class="badge bg-light text-secondary border" style="font-size:.62rem">
                <?= $audienceLabel[$notice['audience']] ?? ucfirst($notice['audience']) ?>
              </span>
              <?php if ($isNew): ?><span class="badge bg-success" style="font-size:.62rem">New</span><?php endif; ?>
              <?php if ($isExpiring): ?><span class="badge bg-warning text-dark" style="font-size:.62rem">Expiring soon</span><?php endif; ?>
            </div>
          </div>
        </div>
        <div class="text-muted text-end flex-shrink-0" style="font-size:.68rem">
          <div><?= date('d M Y', strtotime($notice['created_at'])) ?></div>
          <?php if (!empty($notice['expiry_date'])): ?>
          <div>Expires <?= date('d M', strtotime($notice['expiry_date'])) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php if (!empty($notice['content'])): ?>
      <p class="small text-secondary mb-0" style="white-space:pre-line;line-height:1.6">
        <?= nl2br(e($notice['content'])) ?>
      </p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Pagination -->
<?php if ($totalPages > 1): ?>
<nav class="mt-4">
  <ul class="pagination pagination-sm justify-content-center mb-0">
    <li class="page-item <?= $page<=1?'disabled':'' ?>">
      <a class="page-link" href="?p=<?= $page-1 ?>"><i class="fas fa-chevron-left"></i></a>
    </li>
    <?php for ($pg=1; $pg<=$totalPages; $pg++): ?>
    <li class="page-item <?= $pg===$page?'active':'' ?>">
      <a class="page-link" href="?p=<?= $pg ?>"><?= $pg ?></a>
    </li>
    <?php endfor; ?>
    <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
      <a class="page-link" href="?p=<?= $page+1 ?>"><i class="fas fa-chevron-right"></i></a>
    </li>
  </ul>
</nav>
<?php endif; ?>

<p class="text-center text-muted small mt-2">
  Showing <?= count($notices) ?> of <?= $totalCount ?> notice<?= $totalCount!==1?'s':'' ?>
</p>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
