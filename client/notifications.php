<?php
$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header-client.php';
require_once __DIR__ . '/../includes/notifications.php';

$uid   = (int)$user['id'];
$orgId = (int)$user['org_id'];

// ── POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'clear_all') {
        try { $pdo->prepare("DELETE FROM notifications WHERE org_id=?")->execute([$orgId]); } catch (Exception $e) {}
        setFlash('success', 'All notifications cleared.');
        redirect(APP_URL . '/client/notifications.php');
    }

    if ($act === 'delete_one') {
        deleteNotification((int)($_POST['notif_id'] ?? 0), $uid);
        redirect(APP_URL . '/client/notifications.php' . ($_GET['tab'] === 'prefs' ? '?tab=prefs' : ''));
    }

    if ($act === 'save_prefs') {
        saveNotifPreferences($uid, $orgId, $_POST);
        setFlash('success', 'Notification preferences saved.');
        redirect(APP_URL . '/client/notifications.php?tab=prefs');
    }
}

// Mark all as read on inbox view
$tab = $_GET['tab'] ?? 'inbox';
if ($tab === 'inbox') {
    markNotificationsRead($uid);
}

// ── Inbox data ────────────────────────────────────────────────────
$page         = max(1, (int)($_GET['page'] ?? 1));
$limit        = 20;
$offset       = ($page - 1) * $limit;
$filterType   = $_GET['type'] ?? '';
$allowedTypes = ['info','success','warning','danger'];
$typeWhere    = '';
$typeParams   = [$orgId];
if ($filterType && in_array($filterType, $allowedTypes)) {
    $typeWhere  = ' AND type=?';
    $typeParams[] = $filterType;
}

try {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE org_id=?" . $typeWhere);
    $totalStmt->execute($typeParams);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT * FROM notifications WHERE org_id=?" . $typeWhere . "
        ORDER BY created_at DESC LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($typeParams, [$limit, $offset]));
    $notes = $stmt->fetchAll();
} catch (Exception $e) {
    $total = 0; $notes = [];
}

// ── Preferences data ─────────────────────────────────────────────
$prefs = getNotifPreferences($uid);

$typeConfig = [
    'info'    => ['icon' => 'fas fa-info-circle',          'color' => 'primary', 'label' => 'Info / General'],
    'success' => ['icon' => 'fas fa-check-circle',         'color' => 'success', 'label' => 'Success / Confirmations'],
    'warning' => ['icon' => 'fas fa-exclamation-triangle', 'color' => 'warning', 'label' => 'Warnings / Reminders'],
    'danger'  => ['icon' => 'fas fa-times-circle',         'color' => 'danger',  'label' => 'Urgent / Action Required'],
];
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-bell me-2 text-primary"></i>Notifications</h4>
    <p class="text-muted mb-0 small">Your activity alerts, system messages, and preferences</p>
  </div>
  <?php if ($tab === 'inbox' && $total > 0): ?>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="clear_all">
    <button class="btn btn-sm btn-outline-danger" data-confirm="Delete all notifications? This cannot be undone.">
      <i class="fas fa-trash me-1"></i>Clear All
    </button>
  </form>
  <?php endif; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'inbox' ? 'active' : '' ?>" href="?tab=inbox">
      <i class="fas fa-inbox me-1"></i>Inbox
      <?php if ($total > 0): ?><span class="badge bg-primary ms-1"><?= $total ?></span><?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $tab === 'prefs' ? 'active' : '' ?>" href="?tab=prefs">
      <i class="fas fa-sliders-h me-1"></i>Preferences
    </a>
  </li>
</ul>

<?php if ($tab === 'prefs'): ?>
<!-- ── Preferences tab ──────────────────────────────────────────── -->
<div class="row justify-content-center">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header"><i class="fas fa-sliders-h text-green me-2"></i>Notification Preferences</div>
      <div class="card-body">
        <p class="text-muted small mb-4">Control how and when you receive notifications. Changes take effect immediately.</p>
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_prefs">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Notification Type</th>
                  <th class="text-center">
                    <i class="fas fa-bell text-navy me-1"></i>In-App
                  </th>
                  <th class="text-center">
                    <i class="fas fa-envelope text-navy me-1"></i>Email
                  </th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($typeConfig as $t => $cfg): ?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <i class="<?= $cfg['icon'] ?> text-<?= $cfg['color'] ?>"></i>
                      <span class="fw-600"><?= $cfg['label'] ?></span>
                    </div>
                  </td>
                  <td class="text-center">
                    <div class="form-check d-flex justify-content-center mb-0">
                      <input class="form-check-input" type="checkbox"
                             name="inapp_<?= $t ?>"
                             id="inapp_<?= $t ?>"
                             <?= ($prefs['inapp_' . $t] ?? 1) ? 'checked' : '' ?>>
                    </div>
                  </td>
                  <td class="text-center">
                    <div class="form-check d-flex justify-content-center mb-0">
                      <input class="form-check-input" type="checkbox"
                             name="email_<?= $t ?>"
                             id="email_<?= $t ?>"
                             <?= ($prefs['email_' . $t] ?? 1) ? 'checked' : '' ?>>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Preferences</button>
            <a href="?tab=inbox" class="btn btn-light">Back to Inbox</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card mt-3">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3">
          <i class="fas fa-shield-alt fa-lg text-green mt-1"></i>
          <div>
            <div class="fw-600 mb-1">About Notifications</div>
            <p class="small text-muted mb-0">
              In-app notifications appear in the bell icon in the header. Email notifications are sent to
              <strong><?= e($user['email'] ?? '') ?></strong> based on your preferences above.
              Critical account alerts (e.g. subscription expiry) may still be sent regardless of email preferences.
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ── Inbox tab ─────────────────────────────────────────────────── -->

<!-- Type filter pills -->
<div class="mb-3">
  <ul class="nav nav-pills flex-wrap gap-1">
    <li class="nav-item">
      <a class="nav-link <?= $filterType === '' ? 'active' : '' ?> py-1 px-3 small" href="?tab=inbox">All</a>
    </li>
    <?php foreach ($typeConfig as $t => $cfg): ?>
    <li class="nav-item">
      <a class="nav-link <?= $filterType === $t ? 'active' : '' ?> py-1 px-3 small" href="?tab=inbox&type=<?= $t ?>">
        <i class="<?= $cfg['icon'] ?> me-1"></i><?= explode(' ', $cfg['label'])[0] ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</div>

<?php if (empty($notes)): ?>
<div class="card">
  <div class="card-body text-center py-5">
    <i class="fas fa-bell-slash fa-3x text-muted mb-3 d-block"></i>
    <p class="text-muted mb-0">No notifications<?= $filterType ? ' of this type' : '' ?>.</p>
    <a href="?tab=inbox" class="btn btn-sm btn-outline-primary mt-3">View All</a>
  </div>
</div>
<?php else: ?>
<div class="card p-0">
  <?php foreach ($notes as $n):
    $isRead = (int)$n['is_read'];
  ?>
  <div class="notif-item notif-type-<?= e($n['type']) ?> <?= $isRead ? '' : 'unread' ?> position-relative">
    <div class="d-flex align-items-start gap-3">
      <div class="mt-1 flex-shrink-0">
        <i class="<?= $typeConfig[$n['type']]['icon'] ?? 'fas fa-bell' ?> text-<?= $typeConfig[$n['type']]['color'] ?? 'secondary' ?> fa-lg"></i>
      </div>
      <div class="flex-grow-1" <?= $n['link'] ? "style='cursor:pointer' onclick=\"window.location='" . e($n['link']) . "'\"" : '' ?>>
        <div class="d-flex justify-content-between align-items-start gap-2">
          <span class="notif-title"><?= e($n['title']) ?></span>
          <span class="notif-time flex-shrink-0"><?= timeAgo($n['created_at']) ?></span>
        </div>
        <?php if (!empty($n['message'])): ?>
        <div class="notif-msg mt-1"><?= e($n['message']) ?></div>
        <?php endif; ?>
        <div class="d-flex align-items-center gap-3 mt-1">
          <span class="notif-time"><?= formatDateTime($n['created_at']) ?></span>
          <?php if ($n['link']): ?>
          <a href="<?= e($n['link']) ?>" class="notif-time text-primary" onclick="event.stopPropagation()">
            <i class="fas fa-external-link-alt me-1"></i>Open
          </a>
          <?php endif; ?>
        </div>
      </div>
      <div class="d-flex flex-column gap-1 flex-shrink-0">
        <?php if (!$isRead): ?>
        <span class="badge bg-primary" style="font-size:.6rem">NEW</span>
        <?php endif; ?>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete_one">
          <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
          <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete"
                  data-confirm="Delete this notification?">
            <i class="fas fa-times"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Pagination -->
<?php
$pages   = (int)ceil($total / $limit);
$baseUrl = '?tab=inbox&type=' . urlencode($filterType);
if ($pages > 1): ?>
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
<p class="text-muted small mt-2">
  Showing <?= count($notes) ?> of <?= $total ?> notification<?= $total !== 1 ? 's' : '' ?>
  &bull; <a href="?tab=prefs">Manage preferences</a>
</p>
<?php endif; // end else (notes not empty)
endif; // end inbox tab
?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
