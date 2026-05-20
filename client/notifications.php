<?php
$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header-client.php';
require_once __DIR__ . '/../includes/notifications.php';

$uid   = (int)$user['id'];
$orgId = (int)$user['org_id'];

// Handle clear all
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    verifyCsrf();
    try {
        $pdo->prepare("DELETE FROM notifications WHERE org_id=?")->execute([$orgId]);
    } catch (Exception $e) { /* table may not exist yet */ }
    setFlash('success', 'All notifications cleared.');
    redirect(APP_URL . '/client/notifications.php');
}

// Mark all as read on page load
markNotificationsRead($uid);

// Pagination
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 20;
$offset = ($page - 1) * $limit;

// Optional type filter
$filterType   = $_GET['type'] ?? '';
$allowedTypes = ['info', 'success', 'warning', 'danger'];
$typeWhere    = '';
$typeParams   = [$orgId];
if ($filterType && in_array($filterType, $allowedTypes)) {
    $typeWhere  = ' AND type = ?';
    $typeParams[] = $filterType;
}

try {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE org_id=?" . $typeWhere);
    $totalStmt->execute($typeParams);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE org_id=?" . $typeWhere . " ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute(array_merge($typeParams, [$limit, $offset]));
    $notes = $stmt->fetchAll();
} catch (Exception $e) {
    $total = 0;
    $notes = [];
}

$typeIcons = [
    'info'    => 'fas fa-info-circle text-primary',
    'success' => 'fas fa-check-circle text-success',
    'warning' => 'fas fa-exclamation-triangle text-warning',
    'danger'  => 'fas fa-times-circle text-danger',
];
$typeLabels = [
    'info'    => 'Info',
    'success' => 'Success',
    'warning' => 'Warning',
    'danger'  => 'Danger',
];
?>

<div class="page-header d-flex justify-content-between align-items-center flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-bell me-2 text-primary"></i>Notifications</h4>
    <p class="text-muted mb-0">Your activity alerts and system messages</p>
  </div>
  <?php if ($total > 0): ?>
  <form method="POST" onsubmit="return confirm('Delete all notifications for your organisation? This cannot be undone.')">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="clear_all">
    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Clear All</button>
  </form>
  <?php endif; ?>
</div>

<!-- Type filter tabs -->
<div class="mb-3">
  <ul class="nav nav-pills flex-wrap gap-1">
    <li class="nav-item">
      <a class="nav-link <?= $filterType === '' ? 'active' : '' ?>" href="?">All</a>
    </li>
    <?php foreach ($allowedTypes as $t): ?>
    <li class="nav-item">
      <a class="nav-link <?= $filterType === $t ? 'active' : '' ?>" href="?type=<?= $t ?>">
        <i class="<?= $typeIcons[$t] ?> me-1"></i><?= $typeLabels[$t] ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if (empty($notes)): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="fas fa-bell-slash fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-0">No notifications<?= $filterType ? ' of this type' : '' ?> found.</p>
  </div>
</div>
<?php else: ?>
<div class="card p-0">
  <?php foreach ($notes as $n): ?>
  <?php $isRead = (int)$n['is_read']; ?>
  <div class="notif-item notif-type-<?= e($n['type']) ?> <?= $isRead ? '' : 'unread' ?>"
       style="<?= $n['link'] ? 'cursor:pointer' : '' ?>"
       <?= $n['link'] ? "onclick=\"window.location='" . e($n['link']) . "'\"" : '' ?>>
    <div class="d-flex align-items-start gap-3">
      <div class="mt-1 flex-shrink-0">
        <i class="<?= $typeIcons[$n['type']] ?> fa-lg"></i>
      </div>
      <div class="flex-grow-1">
        <div class="d-flex justify-content-between align-items-start">
          <span class="notif-title"><?= e($n['title']) ?></span>
          <span class="notif-time ms-3 flex-shrink-0"><?= timeAgo($n['created_at']) ?></span>
        </div>
        <?php if (!empty($n['message'])): ?>
        <div class="notif-msg mt-1"><?= e($n['message']) ?></div>
        <?php endif; ?>
        <div class="notif-time mt-1"><?= formatDateTime($n['created_at']) ?></div>
      </div>
      <?php if (!$isRead): ?>
      <span class="badge bg-primary flex-shrink-0" style="font-size:.6rem;">NEW</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php
$pages = (int)ceil($total / $limit);
if ($pages > 1):
    $baseUrl = '?type=' . urlencode($filterType);
?>
<nav class="mt-3">
  <ul class="pagination pagination-sm mb-0">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
      <a class="page-link" href="<?= $baseUrl ?>&page=<?= $i ?>"><?= $i ?></a>
    </li>
    <?php endfor; ?>
  </ul>
</nav>
<?php endif; ?>

<p class="text-muted small mt-2">Showing <?= count($notes) ?> of <?= $total ?> notification<?= $total !== 1 ? 's' : '' ?></p>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
