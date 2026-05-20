<?php
$pageTitle = 'Client Management';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Handle actions
$action  = $_GET['action'] ?? '';
$viewId  = (int)($_GET['view'] ?? 0);
$editId  = (int)($_GET['edit'] ?? 0);
$deleteId= (int)($_GET['delete'] ?? 0);

if ($deleteId) {
    $pdo->prepare("UPDATE organizations SET status='inactive' WHERE id=?")->execute([$deleteId]);
    setFlash('success', 'Client deactivated successfully.');
    redirect(APP_URL . '/admin/clients.php');
}

// Fetch all clients with subscription info
$clients = $pdo->query("
    SELECT o.*, s.status as sub_status, s.trial_ends_at, s.ends_at,
           COUNT(DISTINCT u.id) as user_count,
           COUNT(DISTINCT sm.module_id) as module_count,
           p.name as plan_name
    FROM organizations o
    LEFT JOIN subscriptions s ON o.id = s.org_id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE org_id = o.id)
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN users u ON o.id = u.org_id AND u.role != 'super_admin'
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id AND sm.status = 'active'
    GROUP BY o.id ORDER BY o.created_at DESC
")->fetchAll();

// ── CSV Export ───────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';
    $headers = ['Organization', 'Email', 'Phone', 'City', 'Country', 'Status', 'Plan', 'Subscription Status', 'Created'];
    $rows = [];
    foreach ($clients as $c) {
        $rows[] = [
            $c['name']          ?? '',
            $c['email']         ?? '',
            $c['phone']         ?? '',
            $c['city']          ?? '',
            $c['country']       ?? '',
            $c['status']        ?? '',
            $c['plan_name']     ?? '',
            $c['sub_status']    ?? '',
            $c['created_at']    ?? '',
        ];
    }
    exportCsv('clients-' . date('Y-m-d') . '.csv', $headers, $rows);
}

$modules = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order")->fetchAll();
$plans   = $pdo->query("SELECT * FROM subscription_plans WHERE status='active'")->fetchAll();

require_once __DIR__ . '/../includes/header-admin.php';
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-building me-2 text-green"></i>Client Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Dashboard</a></li><li class="breadcrumb-item active">Clients</li></ol></nav>
  </div>
  <div class="d-flex gap-2">
    <a href="?export=csv" class="btn btn-sm btn-outline-success"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
      <i class="fas fa-plus me-2"></i>Add Client
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $cStats = [
    ['Total Clients',    count($clients),                                                                'navy-bg','fas fa-building','navy'],
    ['Active',           count(array_filter($clients, fn($c)=>$c['status']==='active')),                'green-bg','fas fa-check','green'],
    ['On Trial',         count(array_filter($clients, fn($c)=>($c['sub_status']??'')==='trial')),       'warning-bg','fas fa-clock','warning'],
    ['Inactive',         count(array_filter($clients, fn($c)=>$c['status']==='inactive')),              'danger-bg','fas fa-times','danger'],
  ];
  foreach($cStats as $s): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $s[4] ?>">
      <div class="stat-icon <?= $s[2] ?>"><i class="<?= $s[3] ?>"></i></div>
      <div><div class="stat-value"><?= $s[1] ?></div><div class="stat-label"><?= $s[0] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-center">
      <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search clients...">
      </div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="statusFilter">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="subFilter">
          <option value="">All Subscriptions</option>
          <option value="active">Active</option>
          <option value="trial">Trial</option>
          <option value="expired">Expired</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Clients table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table" id="clientsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Organization</th>
            <th>Plan</th>
            <th>Modules</th>
            <th>Users</th>
            <th>Subscription</th>
            <th>Trial/Expiry</th>
            <th>Status</th>
            <th>Joined</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($clients as $i => $c): ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-sm" style="background:var(--navy);font-size:.65rem"><?= strtoupper(substr($c['name'],0,2)) ?></div>
                <div>
                  <div class="fw-600 text-navy"><?= e($c['name']) ?></div>
                  <div class="text-muted small"><?= e($c['email'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge bg-info text-dark"><?= e($c['plan_name'] ?? 'No Plan') ?></span></td>
            <td><span class="badge bg-primary"><?= $c['module_count'] ?></span></td>
            <td><span class="badge bg-secondary"><?= $c['user_count'] ?></span></td>
            <td><?= statusBadge($c['sub_status'] ?? 'none') ?></td>
            <td class="small">
              <?php if ($c['trial_ends_at']): ?>
              <span class="text-warning"><?= formatDate($c['trial_ends_at']) ?></span>
              <?php elseif ($c['ends_at']): ?>
              <?= formatDate($c['ends_at']) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td class="small text-muted"><?= formatDate($c['created_at']) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="?view=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="View details"><i class="fas fa-eye"></i></a>
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                <a href="<?= APP_URL ?>/admin/subscriptions.php?org=<?= $c['id'] ?>" class="btn btn-xs btn-outline-info" title="Manage subscription"><i class="fas fa-credit-card"></i></a>
                <?php if($c['status'] === 'active'): ?>
                <a href="?delete=<?= $c['id'] ?>" class="btn btn-xs btn-outline-danger" data-confirm="Deactivate this client?" title="Deactivate"><i class="fas fa-ban"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($clients)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted">No clients yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Client</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="<?= APP_URL ?>/admin/clients-save.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Organization Name *</label>
              <input type="text" name="org_name" class="form-control" required placeholder="Business name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Business Email *</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" placeholder="Nairobi">
            </div>
            <div class="col-12"><hr class="my-1"><div class="fw-600 text-navy small">Admin Account</div></div>
            <div class="col-md-6">
              <label class="form-label">Admin Name *</label>
              <input type="text" name="admin_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Admin Password *</label>
              <input type="password" name="admin_password" class="form-control" required placeholder="Min. 8 chars">
            </div>
            <div class="col-12"><hr class="my-1"><div class="fw-600 text-navy small">Subscription</div></div>
            <div class="col-md-6">
              <label class="form-label">Plan</label>
              <select name="plan_id" class="form-select">
                <option value="">No Plan (Trial)</option>
                <?php foreach($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — KES <?= number_format($p['price_monthly']) ?>/mo</option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Subscription Status</label>
              <select name="sub_status" class="form-select">
                <option value="trial">Trial (14 days)</option>
                <option value="active">Active</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Modules to Activate</label>
              <div class="row g-1">
                <?php foreach($modules as $m): ?>
                <div class="col-6 col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($m['slug']) ?>" id="mod_<?= e($m['slug']) ?>">
                    <label class="form-check-label small" for="mod_<?= e($m['slug']) ?>"><?= e($m['name']) ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Create Client</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
