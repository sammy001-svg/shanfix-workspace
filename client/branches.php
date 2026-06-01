<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
sendSecurityHeaders();
requireClientAdmin();
requireAdminRole('Branch management requires administrator access.');

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── POST handlers ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = sanitize($_POST['name']    ?? '');
        $code    = sanitize($_POST['code']    ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $city    = sanitize($_POST['city']    ?? '');
        $phone   = sanitize($_POST['phone']   ?? '');
        $email   = sanitize($_POST['email']   ?? '');
        $manager = sanitize($_POST['manager'] ?? '');
        $status  = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('danger','Branch name is required.'); redirect(APP_URL.'/client/branches.php'); }

        if ($id) {
            $pdo->prepare("UPDATE org_branches SET name=?,code=?,address=?,city=?,phone=?,email=?,manager=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name,$code,$address,$city,$phone,$email,$manager,$status,$id,$orgId]);
            setFlash('success','Branch updated.');
        } else {
            $pdo->prepare("INSERT INTO org_branches (org_id,name,code,address,city,phone,email,manager,status) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$code,$address,$city,$phone,$email,$manager,$status]);
            setFlash('success','Branch <strong>'.e($name).'</strong> created.');
        }
        logActivity('manage_branches','client',"Branch ".($id?'updated':'created').": $name");
        redirect(APP_URL.'/client/branches.php');
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $s  = $pdo->prepare("SELECT status FROM org_branches WHERE id=? AND org_id=?");
        $s->execute([$id,$orgId]);
        $cur = $s->fetchColumn();
        $new = $cur === 'active' ? 'inactive' : 'active';
        $pdo->prepare("UPDATE org_branches SET status=? WHERE id=? AND org_id=?")->execute([$new,$id,$orgId]);
        setFlash('success','Branch '.($new==='active'?'activated':'deactivated').'.');
        redirect(APP_URL.'/client/branches.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Check if any users are assigned to this branch
        try {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE branch_id=? AND org_id=?");
            $cnt->execute([$id,$orgId]);
            if ((int)$cnt->fetchColumn() > 0) {
                setFlash('danger','Cannot delete — staff members are assigned to this branch. Reassign them first.');
                redirect(APP_URL.'/client/branches.php');
            }
        } catch (Throwable $e) {}
        $pdo->prepare("DELETE FROM org_branches WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Branch removed.');
        redirect(APP_URL.'/client/branches.php');
    }
}

// ── Page data ──────────────────────────────────────────────────
$pageTitle = 'Branch Management';
require_once __DIR__ . '/../includes/header-client.php';

$branches = [];
try {
    $s = $pdo->prepare("
        SELECT b.*,
               COUNT(u.id) AS staff_count
        FROM org_branches b
        LEFT JOIN users u ON u.branch_id = b.id AND u.status='active'
        WHERE b.org_id = ?
        GROUP BY b.id
        ORDER BY b.status DESC, b.name ASC
    ");
    $s->execute([$orgId]);
    $branches = $s->fetchAll();
} catch (Throwable $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-code-branch me-2 text-green"></i>Branch Management</h4>
    <p class="text-muted mb-0">Manage your organisation's locations and branches</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal">
    <i class="fas fa-plus me-2"></i>Add Branch
  </button>
</div>

<?php if (empty($branches)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-code-branch fa-3x mb-3 d-block opacity-25"></i>
    <h5>No branches yet</h5>
    <p class="small">Add your first branch to start filtering module data by location.</p>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#branchModal">
      <i class="fas fa-plus me-1"></i>Add First Branch
    </button>
  </div>
</div>

<?php else: ?>
<div class="row g-3">
<?php foreach ($branches as $b): $isActive = $b['status'] === 'active'; ?>
<div class="col-md-6 col-lg-4">
  <div class="card border-0 shadow-sm h-100 <?= $isActive ? '' : 'opacity-75' ?>">
    <div class="card-body">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="d-flex align-items-center gap-3">
          <div class="d-flex align-items-center justify-content-center rounded-3 text-white flex-shrink-0"
               style="width:44px;height:44px;background:<?= $isActive ? 'var(--green)' : '#94a3b8' ?>">
            <i class="fas fa-building"></i>
          </div>
          <div>
            <div class="fw-700"><?= e($b['name']) ?></div>
            <?php if ($b['code']): ?><div class="text-muted small"><?= e($b['code']) ?></div><?php endif; ?>
          </div>
        </div>
        <?= statusBadge($b['status']) ?>
      </div>

      <div class="text-muted small mb-3">
        <?php if ($b['city']): ?><div><i class="fas fa-map-marker-alt me-1 text-green"></i><?= e($b['city']) ?><?= $b['address'] ? ' — '.e($b['address']) : '' ?></div><?php endif; ?>
        <?php if ($b['phone']): ?><div><i class="fas fa-phone me-1"></i><?= e($b['phone']) ?></div><?php endif; ?>
        <?php if ($b['email']): ?><div><i class="fas fa-envelope me-1"></i><?= e($b['email']) ?></div><?php endif; ?>
        <?php if ($b['manager']): ?><div><i class="fas fa-user-tie me-1"></i>Manager: <?= e($b['manager']) ?></div><?php endif; ?>
      </div>

      <div class="d-flex align-items-center justify-content-between mb-3">
        <div class="small text-muted">
          <i class="fas fa-users me-1"></i><?= $b['staff_count'] ?> staff assigned
        </div>
        <a href="<?= APP_URL ?>/client/set-branch.php?branch_id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-success">
          <i class="fas fa-eye me-1"></i>View Branch
        </a>
      </div>

      <div class="d-flex gap-1 flex-wrap">
        <button class="btn btn-xs btn-outline-primary btn-edit-branch"
                data-id="<?= $b['id'] ?>"
                data-name="<?= e($b['name']) ?>"
                data-code="<?= e($b['code']??'') ?>"
                data-address="<?= e($b['address']??'') ?>"
                data-city="<?= e($b['city']??'') ?>"
                data-phone="<?= e($b['phone']??'') ?>"
                data-email="<?= e($b['email']??'') ?>"
                data-manager="<?= e($b['manager']??'') ?>"
                data-status="<?= $b['status'] ?>">
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="toggle">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-xs <?= $isActive ? 'btn-outline-warning' : 'btn-outline-success' ?>">
            <i class="fas fa-<?= $isActive ? 'pause' : 'play' ?> me-1"></i><?= $isActive ? 'Deactivate' : 'Activate' ?>
          </button>
        </form>
        <form method="POST" class="d-inline">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-xs btn-outline-danger" onclick="return confirm('Remove branch \'<?= e($b['name']) ?>\'?')">
            <i class="fas fa-trash"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>

<!-- Quick tip -->
<div class="alert alert-info d-flex gap-2 mt-4" style="font-size:.85rem">
  <i class="fas fa-info-circle flex-shrink-0 mt-1"></i>
  <div>
    Use the <strong>branch selector</strong> in the top header to switch between branches.
    Assign staff to branches via <a href="<?= APP_URL ?>/client/users.php" class="alert-link">Team Management</a>.
    Module data (hotel rooms, POS sales, health patients, SACCO members) is filtered by the active branch.
  </div>
</div>
<?php endif; ?>

<!-- Branch Modal (Add/Edit) -->
<div class="modal fade" id="branchModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:#fff">
        <h5 class="modal-title" id="branchModalTitle"><i class="fas fa-code-branch me-2"></i>Add Branch</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="branchId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="bName" class="form-control" required maxlength="150" placeholder="e.g. Nairobi CBD Branch">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Branch Code</label>
              <input type="text" name="code" id="bCode" class="form-control" maxlength="20" placeholder="e.g. NBI-01">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">City</label>
              <input type="text" name="city" id="bCity" class="form-control" maxlength="100" placeholder="Nairobi">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="bAddress" class="form-control" maxlength="255" placeholder="Street, building, floor">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="tel" name="phone" id="bPhone" class="form-control" maxlength="30" placeholder="+254 7xx xxx xxx">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="bEmail" class="form-control" maxlength="150" placeholder="branch@company.com">
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Branch Manager</label>
              <input type="text" name="manager" id="bManager" class="form-control" maxlength="150" placeholder="Full name">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="bStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Branch</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
document.querySelectorAll('.btn-edit-branch').forEach(btn => {
  btn.addEventListener('click', function() {
    const d = this.dataset;
    document.getElementById('branchModalTitle').innerHTML = '<i class="fas fa-code-branch me-2"></i>Edit Branch';
    document.getElementById('branchId').value    = d.id;
    document.getElementById('bName').value       = d.name;
    document.getElementById('bCode').value       = d.code;
    document.getElementById('bAddress').value    = d.address;
    document.getElementById('bCity').value       = d.city;
    document.getElementById('bPhone').value      = d.phone;
    document.getElementById('bEmail').value      = d.email;
    document.getElementById('bManager').value    = d.manager;
    document.getElementById('bStatus').value     = d.status;
    new bootstrap.Modal(document.getElementById('branchModal')).show();
  });
});
document.getElementById('branchModal').addEventListener('hidden.bs.modal', function() {
  document.getElementById('branchId').value = '0';
  document.getElementById('branchModalTitle').innerHTML = '<i class="fas fa-code-branch me-2"></i>Add Branch';
  this.querySelector('form').reset();
});
</script>
JS;
require_once __DIR__ . '/../includes/footer.php'; ?>
