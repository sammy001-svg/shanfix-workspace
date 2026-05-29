<?php
// ── Audit Trail — activity log viewer (admin-only) ───────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Role guard: client_admin only
if (($user['role'] ?? '') !== 'client_admin') {
    setFlash('danger', 'Access denied. This page is for admin users only.');
    redirect(APP_URL . '/client/index.php');
}

// ── CSV Export (before header output) ────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';

    $params = [$orgId];
    $where  = ['a.org_id=?'];

    $fromDate = $_GET['from'] ?? '';
    $toDate   = $_GET['to']   ?? '';
    $modFilter = $_GET['module'] ?? '';
    $actFilter = $_GET['action_search'] ?? '';
    $userFilter = (int)($_GET['user_id'] ?? 0);

    if ($fromDate) { $where[] = "DATE(a.created_at) >= ?"; $params[] = $fromDate; }
    if ($toDate)   { $where[] = "DATE(a.created_at) <= ?"; $params[] = $toDate;   }
    if ($modFilter) { $where[] = "a.module=?"; $params[] = $modFilter; }
    if ($actFilter) { $where[] = "a.action LIKE ?"; $params[] = "%{$actFilter}%"; }
    if ($userFilter) { $where[] = "a.user_id=?"; $params[] = $userFilter; }

    $whereSQL = implode(' AND ', $where);
    try {
        $s = $pdo->prepare("
            SELECT a.created_at, u.name AS user_name, a.module, a.action, a.description, a.ip
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE {$whereSQL}
            ORDER BY a.created_at DESC
            LIMIT 5000
        ");
        $s->execute($params);
        $rows = $s->fetchAll();
    } catch (Exception $e) { $rows = []; }

    exportCsv('audit-trail-' . date('Y-m-d') . '.csv',
        ['Date / Time', 'User', 'Module', 'Action', 'Description', 'IP Address'],
        array_map(fn($r) => [
            $r['created_at'], $r['user_name'] ?? 'Unknown',
            $r['module'], $r['action'], $r['description'], $r['ip']
        ], $rows)
    );
}

// ── Clear Old Logs (POST action=clear_old) ────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_old') {
    verifyCsrf();
    try {
        $s = $pdo->prepare("DELETE FROM activity_log WHERE org_id=? AND created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
        $s->execute([$orgId]);
        $deleted = $s->rowCount();
        setFlash('success', "Cleared {$deleted} log entries older than 90 days.");
        logActivity('delete', 'audit_trail', "Cleared {$deleted} log entries older than 90 days");
    } catch (Exception $e) {
        setFlash('danger', 'Failed to clear logs.');
    }
    redirect(APP_URL . '/client/audit-trail.php');
}

// ── Filters ───────────────────────────────────────────────────────
$fromDate   = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$toDate     = $_GET['to']   ?? date('Y-m-d');
$modFilter  = $_GET['module'] ?? '';
$actFilter  = $_GET['action_search'] ?? '';
$userFilter = (int)($_GET['user_id'] ?? 0);

// ── Build query ───────────────────────────────────────────────────
$params = [$orgId];
$where  = ['a.org_id=?'];

if ($fromDate) { $where[] = "DATE(a.created_at) >= ?"; $params[] = $fromDate; }
if ($toDate)   { $where[] = "DATE(a.created_at) <= ?"; $params[] = $toDate;   }
if ($modFilter) { $where[] = "a.module=?"; $params[] = $modFilter; }
if ($actFilter) { $where[] = "a.action LIKE ?"; $params[] = "%{$actFilter}%"; }
if ($userFilter) { $where[] = "a.user_id=?"; $params[] = $userFilter; }

$whereSQL = implode(' AND ', $where);

// ── KPIs ──────────────────────────────────────────────────────────
$kpiTotal = $kpiUsers = 0;
$kpiTopModule = $kpiTopAction = '—';
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE org_id=? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
    $s->execute([$orgId]); $kpiTotal = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM activity_log WHERE org_id=? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
    $s->execute([$orgId]); $kpiUsers = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT module, COUNT(*) AS c FROM activity_log WHERE org_id=? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') GROUP BY module ORDER BY c DESC LIMIT 1");
    $s->execute([$orgId]); $row = $s->fetch();
    if ($row) $kpiTopModule = $row['module'] ?: '—';
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT action, COUNT(*) AS c FROM activity_log WHERE org_id=? AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') GROUP BY action ORDER BY c DESC LIMIT 1");
    $s->execute([$orgId]); $row = $s->fetch();
    if ($row) $kpiTopAction = $row['action'] ?: '—';
} catch (Exception $e) {}

// ── Module list for filter dropdown ──────────────────────────────
$moduleList = [];
try {
    $s = $pdo->prepare("SELECT DISTINCT module FROM activity_log WHERE org_id=? AND module != '' ORDER BY module");
    $s->execute([$orgId]);
    $moduleList = $s->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Users for filter dropdown ─────────────────────────────────────
$userList = [];
try {
    $s = $pdo->prepare("SELECT DISTINCT u.id, u.name FROM activity_log a JOIN users u ON u.id=a.user_id WHERE a.org_id=? ORDER BY u.name");
    $s->execute([$orgId]);
    $userList = $s->fetchAll();
} catch (Exception $e) {}

// ── Log entries ───────────────────────────────────────────────────
$logs = [];
try {
    $s = $pdo->prepare("
        SELECT a.*, u.name AS user_name
        FROM activity_log a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE {$whereSQL}
        ORDER BY a.created_at DESC
        LIMIT 500
    ");
    $s->execute($params);
    $logs = $s->fetchAll();
} catch (Exception $e) {}

// Build export URL with current filters
$exportUrl = APP_URL . '/client/audit-trail.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']));

$pageTitle = 'Audit Trail';
require_once __DIR__ . '/../includes/header-client.php';

// Action badge map
function actionBadge(string $action): string {
    $map = [
        'create'  => 'success',
        'update'  => 'info',
        'delete'  => 'danger',
        'login'   => 'primary',
        'logout'  => 'secondary',
        'export'  => 'warning',
        'import'  => 'warning',
        'view'    => 'light text-dark',
    ];
    $prefix = strtolower(explode('_', $action)[0]);
    $cls = $map[$action] ?? $map[$prefix] ?? 'secondary';
    return "<span class='badge bg-{$cls}'>" . e(ucfirst(str_replace('_',' ',$action))) . "</span>";
}
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-history me-2 text-green"></i>Audit Trail</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="<?= APP_URL ?>/client/index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Audit Trail</li>
    </ol></nav>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= e($exportUrl) ?>" class="btn btn-success btn-sm">
      <i class="fas fa-download me-1"></i>Export CSV
    </a>
    <form method="POST" onsubmit="return confirm('Delete all log entries older than 90 days for your organisation? This cannot be undone.')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="clear_old">
      <button type="submit" class="btn btn-outline-danger btn-sm">
        <i class="fas fa-trash-alt me-1"></i>Clear Old Logs
      </button>
    </form>
  </div>
</div>

<!-- KPI cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-3">
        <div class="fw-bold fs-5 text-success"><?= number_format($kpiTotal) ?></div>
        <div class="text-muted small">Events This Month</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-3">
        <div class="fw-bold fs-5 text-primary"><?= number_format($kpiUsers) ?></div>
        <div class="text-muted small">Unique Users</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-3">
        <div class="fw-bold fs-5 text-warning"><?= e($kpiTopModule) ?></div>
        <div class="text-muted small">Most Active Module</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-3">
        <div class="fw-bold fs-5 text-danger"><?= e($kpiTopAction) ?></div>
        <div class="text-muted small">Most Common Action</div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">Module</label>
        <select name="module" class="form-select form-select-sm">
          <option value="">All Modules</option>
          <?php foreach ($moduleList as $m): ?>
          <option value="<?= e($m) ?>" <?= $modFilter === $m ? 'selected' : '' ?>><?= e(ucfirst($m)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">Action</label>
        <input type="text" name="action_search" class="form-control form-control-sm" placeholder="e.g. create" value="<?= e($actFilter) ?>">
      </div>
      <div class="col-sm-6 col-md-2">
        <label class="form-label small fw-semibold">User</label>
        <select name="user_id" class="form-select form-select-sm">
          <option value="">All Users</option>
          <?php foreach ($userList as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $userFilter === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-success btn-sm w-100">
          <i class="fas fa-filter me-1"></i>Filter
        </button>
        <a href="<?= APP_URL ?>/client/audit-trail.php" class="btn btn-outline-secondary btn-sm">
          <i class="fas fa-times"></i>
        </a>
      </div>
    </form>
  </div>
</div>

<!-- DataTable -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <?php if (empty($logs)): ?>
    <div class="text-center text-muted py-5">
      <i class="fas fa-history fa-4x mb-3 opacity-25"></i>
      <p>No activity log entries found for the selected filters.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="auditTable">
        <thead class="table-light">
          <tr>
            <th style="white-space:nowrap">Date / Time</th>
            <th>User</th>
            <th>Module</th>
            <th>Action</th>
            <th>Description</th>
            <th>IP Address</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($logs as $log): ?>
          <tr>
            <td style="white-space:nowrap;font-size:.8rem"><?= formatDateTime($log['created_at']) ?></td>
            <td>
              <span class="fw-semibold small"><?= e($log['user_name'] ?? 'Unknown') ?></span>
            </td>
            <td>
              <?php if ($log['module']): ?>
              <span class="badge bg-secondary"><?= e(ucfirst($log['module'])) ?></span>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= actionBadge($log['action'] ?? '') ?></td>
            <td class="small" style="max-width:320px">
              <?= e(mb_strimwidth($log['description'] ?? '', 0, 200, '…')) ?>
            </td>
            <td class="small text-muted"><?= e($log['ip'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
$(document).ready(function() {
    if ($.fn.DataTable && document.getElementById('auditTable')) {
        $('#auditTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            columnDefs: [
                { orderable: false, targets: [4] }
            ],
            language: { search: 'Quick filter:' }
        });
    }
});
</script>
JS;

require_once __DIR__ . '/../includes/footer.php';
