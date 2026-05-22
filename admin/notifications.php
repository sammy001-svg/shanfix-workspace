<?php
$pageTitle = 'Notifications';
require_once __DIR__ . '/../includes/header-admin.php';
require_once __DIR__ . '/../includes/notifications.php';

// ── POST: broadcast ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'broadcast') {
        verifyCsrf();
        $title     = sanitize($_POST['title']    ?? '');
        $message   = sanitize($_POST['message']  ?? '');
        $type      = in_array($_POST['type'] ?? '', ['info','success','warning','danger']) ? $_POST['type'] : 'info';
        $link      = sanitize($_POST['link']     ?? '');
        $target    = $_POST['target']            ?? 'all';
        $orgId     = (int)($_POST['org_id']      ?? 0);
        $userId    = (int)($_POST['user_id']     ?? 0);
        $sendEmail = isset($_POST['send_email']);

        if (!$title) {
            setFlash('danger', 'Notification title is required.');
        } else {
            $sent = 0;
            if ($target === 'all') {
                $sent = notifyAllOrgs($title, $message, $type, $link, $sendEmail);
            } elseif ($target === 'org' && $orgId) {
                $sent = notifyOrg($orgId, $title, $message, $type, $link, $sendEmail);
            } elseif ($target === 'user' && $userId) {
                // Get user's org for createNotification
                $uRow = $pdo->prepare("SELECT org_id, name, email FROM users WHERE id=?");
                $uRow->execute([$userId]);
                $uRow = $uRow->fetch();
                if ($uRow) {
                    if (createNotification((int)$uRow['org_id'], $userId, $title, $message, $type, $link)) {
                        $sent = 1;
                        if ($sendEmail) {
                            _sendNotifEmail($uRow['email'], $uRow['name'], $title, $message, $type, $link);
                        }
                    }
                }
            }
            logActivity('broadcast_notification', 'admin', "Broadcast '$title' ($type) to $target — $sent recipients");
            setFlash('success', "Notification sent to $sent recipient" . ($sent !== 1 ? 's' : '') . '.');
        }
        redirect(APP_URL . '/admin/notifications.php');
    }

    if ($act === 'delete_notification') {
        verifyCsrf();
        $id = (int)($_POST['notif_id'] ?? 0);
        if ($id) {
            $pdo->prepare("DELETE FROM notifications WHERE id=?")->execute([$id]);
            setFlash('success', 'Notification deleted.');
        }
        redirect(APP_URL . '/admin/notifications.php');
    }

    if ($act === 'cleanup') {
        verifyCsrf();
        $days  = max(7, (int)($_POST['days'] ?? 90));
        $count = cleanOldNotifications($days);
        setFlash('success', "Cleaned up $count notification" . ($count !== 1 ? 's' : '') . " older than $days days.");
        redirect(APP_URL . '/admin/notifications.php');
    }
}

// ── Stats ─────────────────────────────────────────────────────────
try {
    $totalNotifs  = $pdo->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
    $unreadTotal  = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read=0")->fetchColumn();
    $thisWeek     = $pdo->query("SELECT COUNT(*) FROM notifications WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    $orgsNotified = $pdo->query("SELECT COUNT(DISTINCT org_id) FROM notifications")->fetchColumn();
    $byType       = $pdo->query("SELECT type, COUNT(*) as cnt FROM notifications GROUP BY type")->fetchAll();
} catch (Exception $e) {
    $totalNotifs = $unreadTotal = $thisWeek = $orgsNotified = 0;
    $byType = [];
}

// History (paginated)
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;
$fType  = $_GET['type'] ?? '';
$fOrg   = (int)($_GET['org'] ?? 0);

$where  = 'WHERE 1=1';
$params = [];
if ($fType && in_array($fType, ['info','success','warning','danger'])) { $where .= ' AND n.type=?'; $params[] = $fType; }
if ($fOrg)  { $where .= ' AND n.org_id=?'; $params[] = $fOrg; }

try {
    $totalRows = $pdo->prepare("SELECT COUNT(*) FROM notifications n $where");
    $totalRows->execute($params);
    $totalRows = (int)$totalRows->fetchColumn();

    $histStmt = $pdo->prepare("
        SELECT n.*, o.name as org_name, u.name as user_name
        FROM notifications n
        LEFT JOIN organizations o ON n.org_id = o.id
        LEFT JOIN users u ON n.user_id = u.id
        $where
        ORDER BY n.created_at DESC LIMIT ? OFFSET ?
    ");
    $histStmt->execute(array_merge($params, [$limit, $offset]));
    $history = $histStmt->fetchAll();
} catch (Exception $e) {
    $history  = [];
    $totalRows = 0;
}

// Org and user lists for broadcast form
$orgs  = $pdo->query("SELECT id, name FROM organizations WHERE status='active' ORDER BY name")->fetchAll();
$users = $pdo->query("SELECT u.id, u.name, o.name as org_name FROM users u JOIN organizations o ON u.org_id=o.id WHERE u.role!='super_admin' ORDER BY o.name,u.name")->fetchAll();

$typeConfig = [
    'info'    => ['icon' => 'fas fa-info-circle',          'color' => 'primary', 'label' => 'Info'],
    'success' => ['icon' => 'fas fa-check-circle',         'color' => 'success', 'label' => 'Success'],
    'warning' => ['icon' => 'fas fa-exclamation-triangle', 'color' => 'warning', 'label' => 'Warning'],
    'danger'  => ['icon' => 'fas fa-times-circle',         'color' => 'danger',  'label' => 'Danger'],
];

$templates = [
    ['title' => 'Subscription Expiring Soon',   'message' => 'Your subscription is expiring soon. Please renew to avoid service interruption.', 'type' => 'warning'],
    ['title' => 'Invoice Ready',                'message' => 'A new invoice has been generated for your account. Please review and make payment at your earliest convenience.', 'type' => 'info'],
    ['title' => 'Welcome to OrbitDesk!',        'message' => 'Welcome! Your account has been set up successfully. Explore your modules to get started.', 'type' => 'success'],
    ['title' => 'Scheduled Maintenance',        'message' => 'We will be performing scheduled maintenance. The system may be briefly unavailable. We apologize for any inconvenience.', 'type' => 'warning'],
    ['title' => 'Payment Received',             'message' => 'Thank you! We have received your payment and your subscription has been renewed.', 'type' => 'success'],
    ['title' => 'Action Required',              'message' => 'Your account requires attention. Please log in to review and resolve the issue.', 'type' => 'danger'],
];
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-bell me-2 text-green"></i>Notifications</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Notifications</li>
    </ol></nav>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cleanupModal">
      <i class="fas fa-broom me-1"></i>Cleanup Old
    </button>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#broadcastModal">
      <i class="fas fa-paper-plane me-2"></i>Send Notification
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon navy-bg"><i class="fas fa-bell"></i></div>
      <div><div class="stat-value"><?= number_format($totalNotifs) ?></div><div class="stat-label">Total Sent</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning"><div class="stat-icon warning-bg"><i class="fas fa-envelope-open"></i></div>
      <div><div class="stat-value"><?= number_format($unreadTotal) ?></div><div class="stat-label">Unread</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon green-bg"><i class="fas fa-calendar-week"></i></div>
      <div><div class="stat-value"><?= number_format($thisWeek) ?></div><div class="stat-label">This Week</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon navy-bg"><i class="fas fa-building"></i></div>
      <div><div class="stat-value"><?= number_format($orgsNotified) ?></div><div class="stat-label">Orgs Reached</div></div></div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Type breakdown -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-chart-pie text-green me-2"></i>By Type</div>
      <div class="card-body">
        <?php
        $byTypeMap = array_column($byType, 'cnt', 'type');
        foreach ($typeConfig as $t => $cfg):
          $cnt = (int)($byTypeMap[$t] ?? 0);
          $pct = $totalNotifs > 0 ? round($cnt / $totalNotifs * 100) : 0;
        ?>
        <div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <div class="small fw-600 d-flex align-items-center gap-2">
              <i class="<?= $cfg['icon'] ?> text-<?= $cfg['color'] ?>"></i><?= $cfg['label'] ?>
            </div>
            <div class="small text-muted"><?= $cnt ?></div>
          </div>
          <div class="progress" style="height:6px">
            <div class="progress-bar bg-<?= $cfg['color'] ?>" style="width:<?= $pct ?>%"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if ($totalNotifs === 0): ?>
        <div class="text-center text-muted small py-3">No notifications sent yet.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Quick templates -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-bolt text-green me-2"></i>Quick Templates</div>
      <div class="card-body">
        <p class="small text-muted mb-3">Click a template to pre-fill the broadcast form.</p>
        <div class="row g-2">
          <?php foreach ($templates as $tpl): ?>
          <div class="col-md-6">
            <div class="card border-<?= $typeConfig[$tpl['type']]['color'] ?> h-100"
                 style="cursor:pointer;transition:.15s"
                 onclick="applyTemplate(<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES) ?>)"
                 onmouseenter="this.style.transform='translateY(-2px)'" onmouseleave="this.style.transform=''">
              <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center gap-2 mb-1">
                  <i class="<?= $typeConfig[$tpl['type']]['icon'] ?> text-<?= $typeConfig[$tpl['type']]['color'] ?> fa-sm"></i>
                  <span class="small fw-700"><?= e($tpl['title']) ?></span>
                </div>
                <p class="small text-muted mb-0" style="line-height:1.3;font-size:.72rem"><?= e(substr($tpl['message'], 0, 70)) ?>…</p>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Notification History -->
<div class="card">
  <div class="card-header">
    <i class="fas fa-history text-green me-2"></i>Notification History
    <div class="ms-auto d-flex gap-2 align-items-center">
      <!-- Filters -->
      <select class="form-select form-select-sm" style="width:auto" onchange="applyFilter('type',this.value)">
        <option value="">All Types</option>
        <?php foreach ($typeConfig as $t => $cfg): ?>
        <option value="<?= $t ?>" <?= $fType === $t ? 'selected' : '' ?>><?= $cfg['label'] ?></option>
        <?php endforeach; ?>
      </select>
      <select class="form-select form-select-sm" style="width:auto" onchange="applyFilter('org',this.value)">
        <option value="">All Orgs</option>
        <?php foreach ($orgs as $o): ?>
        <option value="<?= $o['id'] ?>" <?= $fOrg === $o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead>
          <tr><th>Type</th><th>Title</th><th>Organization</th><th>User</th><th>Status</th><th>Sent</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($history as $n): ?>
          <tr class="<?= !$n['is_read'] ? 'table-light' : '' ?>">
            <td>
              <span class="badge bg-<?= $typeConfig[$n['type']]['color'] ?>">
                <i class="<?= $typeConfig[$n['type']]['icon'] ?> me-1"></i><?= ucfirst($n['type']) ?>
              </span>
            </td>
            <td>
              <div class="fw-600"><?= e($n['title']) ?></div>
              <?php if ($n['message']): ?>
              <div class="text-muted" style="font-size:.72rem"><?= e(substr($n['message'], 0, 60)) ?><?= strlen($n['message']) > 60 ? '…' : '' ?></div>
              <?php endif; ?>
            </td>
            <td><?= e($n['org_name'] ?? '—') ?></td>
            <td class="text-muted"><?= $n['user_id'] ? e($n['user_name'] ?? '#' . $n['user_id']) : '<span class="badge bg-secondary">All Users</span>' ?></td>
            <td><?= $n['is_read'] ? '<span class="badge bg-light text-dark border">Read</span>' : '<span class="badge bg-primary">Unread</span>' ?></td>
            <td class="text-muted"><?= timeAgo($n['created_at']) ?></td>
            <td>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_notification">
                <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger"
                        data-confirm="Delete this notification?" title="Delete">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($history)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No notifications found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($totalRows > $limit): ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2">
    <small class="text-muted">Showing <?= count($history) ?> of <?= $totalRows ?></small>
    <nav><?= paginate($totalRows, $limit, $page, '?type=' . urlencode($fType) . '&org=' . $fOrg . '&page=') ?></nav>
  </div>
  <?php endif; ?>
</div>

<!-- ── Broadcast Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="broadcastModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send Notification</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="broadcast">
        <div class="modal-body">
          <div class="row g-3">
            <!-- Type -->
            <div class="col-12">
              <label class="form-label fw-600">Notification Type</label>
              <div class="d-flex gap-2 flex-wrap" id="typeSelector">
                <?php foreach ($typeConfig as $t => $cfg): ?>
                <label class="type-pill">
                  <input type="radio" name="type" value="<?= $t ?>" class="d-none" <?= $t === 'info' ? 'checked' : '' ?>>
                  <span class="btn btn-sm btn-outline-<?= $cfg['color'] ?>" id="typePill_<?= $t ?>">
                    <i class="<?= $cfg['icon'] ?> me-1"></i><?= $cfg['label'] ?>
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
            </div>
            <!-- Target -->
            <div class="col-md-4">
              <label class="form-label fw-600">Send To</label>
              <select name="target" id="targetSelect" class="form-select" onchange="updateTargetFields()">
                <option value="all">All Organizations</option>
                <option value="org">Specific Organization</option>
                <option value="user">Specific User</option>
              </select>
            </div>
            <div class="col-md-8" id="targetFieldWrap" style="display:none">
              <label class="form-label fw-600" id="targetFieldLabel">Organization</label>
              <select name="org_id" id="orgSelect" class="form-select">
                <option value="">Select organization...</option>
                <?php foreach ($orgs as $o): ?>
                <option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <select name="user_id" id="userSelect" class="form-select d-none">
                <option value="">Select user...</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> — <?= e($u['org_name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- Title -->
            <div class="col-12">
              <label class="form-label fw-600">Title *</label>
              <input type="text" name="title" id="broadcastTitle" class="form-control" required maxlength="255" placeholder="Notification headline">
            </div>
            <!-- Message -->
            <div class="col-12">
              <label class="form-label fw-600">Message</label>
              <textarea name="message" id="broadcastMsg" class="form-control" rows="3" placeholder="Full notification message (optional)"></textarea>
            </div>
            <!-- Link -->
            <div class="col-12">
              <label class="form-label fw-600">Action Link <span class="text-muted fw-400">(optional)</span></label>
              <input type="text" name="link" class="form-control" placeholder="https://... or /client/billing.php">
            </div>
            <!-- Email -->
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="send_email" id="sendEmailCheck">
                <label class="form-check-label" for="sendEmailCheck">
                  Also send via email <span class="text-muted small">(respects user email preferences)</span>
                </label>
              </div>
            </div>
            <!-- Preview -->
            <div class="col-12">
              <div class="rounded p-3 border notif-item notif-type-info" id="notifPreview">
                <div class="d-flex align-items-start gap-2">
                  <i class="fas fa-info-circle text-primary mt-1" id="previewIcon"></i>
                  <div>
                    <div class="notif-title" id="previewTitle">Your notification title</div>
                    <div class="notif-msg" id="previewMsg">Your notification message will appear here.</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Send Now</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Cleanup Modal ────────────────────────────────────────────── -->
<div class="modal fade" id="cleanupModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-broom me-2"></i>Cleanup Old Notifications</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="cleanup">
        <div class="modal-body">
          <label class="form-label">Delete notifications older than</label>
          <div class="input-group">
            <input type="number" name="days" class="form-control" value="90" min="7" max="365">
            <span class="input-group-text">days</span>
          </div>
          <div class="small text-muted mt-2">This will permanently delete old notification records to keep the database clean.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash me-1"></i>Clean Up</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
// ── Type pill toggle ───────────────────────────────────────────────
const typeIcons = {
  info:    {icon:"fas fa-info-circle",          color:"text-primary",  border:"notif-type-info"},
  success: {icon:"fas fa-check-circle",         color:"text-success",  border:"notif-type-success"},
  warning: {icon:"fas fa-exclamation-triangle", color:"text-warning",  border:"notif-type-warning"},
  danger:  {icon:"fas fa-times-circle",         color:"text-danger",   border:"notif-type-danger"},
};

document.querySelectorAll(".type-pill input").forEach(radio => {
  radio.addEventListener("change", () => {
    document.querySelectorAll(".type-pill span").forEach(s => s.classList.remove("active"));
    radio.nextElementSibling.classList.add("active");
    updatePreviewType(radio.value);
  });
  if (radio.checked) radio.nextElementSibling.classList.add("active");
});

function updatePreviewType(t) {
  const preview = document.getElementById("notifPreview");
  preview.className = "rounded p-3 border notif-item notif-type-" + t;
  const icon = document.getElementById("previewIcon");
  icon.className = typeIcons[t].icon + " " + typeIcons[t].color + " mt-1";
}

// ── Live preview ─────────────────────────────────────────────────
document.getElementById("broadcastTitle").addEventListener("input", function() {
  document.getElementById("previewTitle").textContent = this.value || "Your notification title";
});
document.getElementById("broadcastMsg").addEventListener("input", function() {
  document.getElementById("previewMsg").textContent = this.value || "Your notification message will appear here.";
});

// ── Target field toggle ──────────────────────────────────────────
function updateTargetFields() {
  const val = document.getElementById("targetSelect").value;
  const wrap = document.getElementById("targetFieldWrap");
  const orgSel  = document.getElementById("orgSelect");
  const userSel = document.getElementById("userSelect");
  const label   = document.getElementById("targetFieldLabel");
  if (val === "all") {
    wrap.style.display = "none";
  } else {
    wrap.style.display = "";
    if (val === "org") {
      orgSel.classList.remove("d-none"); userSel.classList.add("d-none");
      label.textContent = "Organization";
    } else {
      orgSel.classList.add("d-none"); userSel.classList.remove("d-none");
      label.textContent = "User";
    }
  }
}

// ── Templates ────────────────────────────────────────────────────
function applyTemplate(tpl) {
  document.getElementById("broadcastTitle").value = tpl.title;
  document.getElementById("broadcastMsg").value   = tpl.message;
  document.getElementById("previewTitle").textContent = tpl.title;
  document.getElementById("previewMsg").textContent   = tpl.message;
  // Select the matching type radio
  document.querySelectorAll(".type-pill input").forEach(r => {
    if (r.value === tpl.type) { r.checked = true; r.dispatchEvent(new Event("change")); }
  });
  updatePreviewType(tpl.type);
  new bootstrap.Modal(document.getElementById("broadcastModal")).show();
}

// ── Filters ──────────────────────────────────────────────────────
function applyFilter(param, val) {
  const url = new URL(window.location.href);
  if (val) url.searchParams.set(param, val);
  else url.searchParams.delete(param);
  url.searchParams.delete("page");
  window.location = url.toString();
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
