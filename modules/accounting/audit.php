<?php
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',       'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',       'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',       'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',          'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',          'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',         'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon' => 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',          'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];
require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fFrom   = $_GET['from']   ?? date('Y-m-01');
$fTo     = $_GET['to']     ?? date('Y-m-d');
$fModule = $_GET['module'] ?? '';
$fAction = $_GET['action_filter'] ?? '';

$where  = 'org_id=?';
$params = [$orgId];
if ($fFrom)   { $where .= ' AND DATE(created_at) >= ?'; $params[] = $fFrom; }
if ($fTo)     { $where .= ' AND DATE(created_at) <= ?'; $params[] = $fTo; }
if ($fModule) { $where .= ' AND module=?';              $params[] = $fModule; }
if ($fAction) { $where .= ' AND action=?';              $params[] = $fAction; }

$logs = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_audit_log WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (Exception $e) {}

// Available modules for filter
$modules = [];
try {
    $stmt = $pdo->prepare("SELECT DISTINCT module FROM acc_audit_log WHERE org_id=? ORDER BY module");
    $stmt->execute([$orgId]);
    $modules = $stmt->fetchAll(\PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// KPIs
$todayCount = 0; $weekCount = 0; $uniqueUsers = 0; $modulesTouched = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_audit_log WHERE org_id=? AND DATE(created_at)=CURDATE()");
    $stmt->execute([$orgId]); $todayCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM acc_audit_log WHERE org_id=? AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $stmt->execute([$orgId]); $weekCount = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) FROM acc_audit_log WHERE org_id=? AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $stmt->execute([$orgId]); $uniqueUsers = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT module) FROM acc_audit_log WHERE org_id=? AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)");
    $stmt->execute([$orgId]); $modulesTouched = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Audit Trail</h4>
    <p class="text-muted mb-0">Read-only log of all system activity and data changes</p>
  </div>
  <span class="badge fs-6" style="background:<?= $moduleColor ?>">Read Only</span>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?= $todayCount ?></div><div class="stat-label">Events Today</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-week"></i></div><div class="stat-body"><div class="stat-value"><?= $weekCount ?></div><div class="stat-label">This Week</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?= $uniqueUsers ?></div><div class="stat-label">Unique Users (7d)</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-th-large"></i></div><div class="stat-body"><div class="stat-value"><?= $modulesTouched ?></div><div class="stat-label">Modules (7d)</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Module</label><select name="module" class="form-select form-select-sm"><option value="">All Modules</option><?php foreach ($modules as $m): ?><option value="<?= e($m) ?>" <?= $fModule===$m?'selected':'' ?>><?= ucfirst($m) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Action</label><select name="action_filter" class="form-select form-select-sm"><option value="">All Actions</option><?php foreach (['create','update','delete','view'] as $a): ?><option value="<?= $a ?>" <?= $fAction===$a?'selected':'' ?>><?= ucfirst($a) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="audit.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-history me-2" style="color:<?= $moduleColor ?>"></i>Activity Log</h6>
    <span class="badge bg-secondary"><?= count($logs) ?> events</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="auditTable">
        <thead class="table-light">
          <tr><th>Timestamp</th><th>User</th><th>Action</th><th>Module</th><th>Record</th><th>IP Address</th><th>Change Summary</th></tr>
        </thead>
        <tbody>
          <?php if (empty($logs)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No audit events found for the selected filters.</td></tr>
          <?php else: foreach ($logs as $lg):
            $actionColors = ['create'=>'success','update'=>'info','delete'=>'danger','view'=>'secondary'];
            $ac = $actionColors[$lg['action']] ?? 'secondary';
          ?>
          <tr>
            <td class="text-nowrap"><?= formatDate($lg['created_at'] ?? '') ?></td>
            <td class="fw-semibold"><?= e($lg['user_name'] ?? 'System') ?></td>
            <td><span class="badge bg-<?= $ac ?> <?= in_array($ac,['info'])?'text-dark':'' ?>"><?= ucfirst($lg['action'] ?? '—') ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($lg['module'] ?? '—') ?></span></td>
            <td class="text-muted small"><?= e(($lg['record_type'] ?? '') . ' #' . ($lg['record_id'] ?? '')) ?></td>
            <td class="text-muted small"><?= e($lg['ip_address'] ?? '—') ?></td>
            <td class="text-muted small" style="max-width:280px">
              <?php if (!empty($lg['new_value'])): ?>
                <span title="<?= htmlspecialchars($lg['new_value'], ENT_QUOTES) ?>"><?= e(mb_substr($lg['new_value'], 0, 80)) ?><?= strlen($lg['new_value']) > 80 ? '…' : '' ?></span>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php
$extraJs = <<<'JS'
<script>
$(document).ready(function(){
  $('#auditTable').DataTable({
    pageLength:25,
    order:[[0,'desc']],
    language:{emptyTable:'No audit events found.'}
  });
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
