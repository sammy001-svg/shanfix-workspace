<?php
$pageTitle = 'Activity Log';
require_once __DIR__ . '/../includes/header-admin.php';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$module = sanitize($_GET['module'] ?? '');
$search = sanitize($_GET['search'] ?? '');
$date   = sanitize($_GET['date']   ?? '');

$where  = '1=1';
$params = [];
if ($module) { $where .= ' AND l.module = ?'; $params[] = $module; }
if ($search) { $where .= ' AND (l.action LIKE ? OR l.description LIKE ? OR u.name LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($date)   { $where .= ' AND DATE(l.created_at) = ?'; $params[] = $date; }

$total = $pdo->prepare("SELECT COUNT(*) FROM activity_log l LEFT JOIN users u ON l.user_id = u.id WHERE $where");
$total->execute($params);
$totalCount = $total->fetchColumn();

$logs = $pdo->prepare("
    SELECT l.*, u.name as user_name, u.email as user_email, o.name as org_name
    FROM activity_log l
    LEFT JOIN users u ON l.user_id = u.id
    LEFT JOIN organizations o ON l.org_id = o.id
    WHERE $where
    ORDER BY l.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$logs->execute($params);
$logs = $logs->fetchAll();

$modules = $pdo->query("SELECT DISTINCT module FROM activity_log WHERE module != '' ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-history me-2 text-green"></i>Activity Log</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Activity Log</li></ol></nav>
  </div>
  <div class="text-muted small"><?= number_format($totalCount) ?> total entries</div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-md-4">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search actions, descriptions, users..." value="<?= e($search) ?>">
      </div>
      <div class="col-md-2">
        <select name="module" class="form-select form-select-sm">
          <option value="">All Modules</option>
          <?php foreach($modules as $m): ?><option value="<?= e($m) ?>" <?= $module===$m ? 'selected' : '' ?>><?= e(ucfirst($m)) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($date) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-sm btn-primary me-1"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead>
          <tr><th>Time</th><th>User</th><th>Organization</th><th>Module</th><th>Action</th><th>Description</th><th>IP</th></tr>
        </thead>
        <tbody>
          <?php foreach($logs as $log): ?>
          <tr>
            <td class="text-muted" style="white-space:nowrap"><?= formatDateTime($log['created_at']) ?></td>
            <td>
              <div class="fw-600"><?= e($log['user_name'] ?? 'System') ?></div>
              <div class="text-muted small"><?= e($log['user_email'] ?? '') ?></div>
            </td>
            <td class="text-muted"><?= e($log['org_name'] ?? '—') ?></td>
            <td><?php if($log['module']): ?><span class="badge bg-info text-dark"><?= e($log['module']) ?></span><?php else: ?>—<?php endif; ?></td>
            <td class="fw-600"><?= e($log['action']) ?></td>
            <td class="text-muted"><?= e($log['description']) ?></td>
            <td class="text-muted font-monospace small"><?= e($log['ip'] ?? '') ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($logs)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No activity log entries found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if ($totalCount > $perPage): ?>
    <div class="p-3 border-top d-flex justify-content-between align-items-center">
      <span class="small text-muted">Showing <?= ($offset+1) ?>–<?= min($offset+$perPage,$totalCount) ?> of <?= $totalCount ?></span>
      <?= paginate($totalCount, $perPage, $page, '?' . http_build_query(array_filter(['module'=>$module,'search'=>$search,'date'=>$date]))) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
