<?php
$pageTitle = 'Subscription Plans';
require_once __DIR__ . '/../includes/header-admin.php';

// Handle CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $id          = (int)($_POST['plan_id']      ?? 0);
        $name        = sanitize($_POST['name']       ?? '');
        $description = sanitize($_POST['description']?? '');
        $maxUsers    = (int)($_POST['max_users']     ?? 5);
        $maxModules  = (int)($_POST['max_modules']   ?? 3);
        $priceMonthly= (float)($_POST['price_monthly']??0);
        $priceAnnual = (float)($_POST['price_annual'] ??0);
        $isPopular   = isset($_POST['is_popular']) ? 1 : 0;
        $status      = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('danger','Plan name is required.'); }
        else {
            $features = json_encode(array_filter(explode("\n", trim($_POST['features'] ?? ''))));
            if ($id) {
                $pdo->prepare("UPDATE subscription_plans SET name=?,description=?,max_users=?,max_modules=?,price_monthly=?,price_annual=?,is_popular=?,status=? WHERE id=?")
                    ->execute([$name,$description,$maxUsers,$maxModules,$priceMonthly,$priceAnnual,$isPopular,$status,$id]);
                setFlash('success',"Plan '$name' updated.");
            } else {
                $pdo->prepare("INSERT INTO subscription_plans (name,description,max_users,max_modules,price_monthly,price_annual,is_popular,status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$name,$description,$maxUsers,$maxModules,$priceMonthly,$priceAnnual,$isPopular,$status]);
                setFlash('success',"Plan '$name' created.");
            }
        }
        redirect(APP_URL . '/admin/plans.php');
    }

    if ($action === 'delete_plan') {
        $id = (int)($_POST['plan_id'] ?? 0);
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id=?");
        $inUse->execute([$id]);
        if ($inUse->fetchColumn() > 0) {
            setFlash('danger','Cannot delete a plan that has active subscriptions.');
        } else {
            $pdo->prepare("DELETE FROM subscription_plans WHERE id=?")->execute([$id]);
            setFlash('success','Plan deleted.');
        }
        redirect(APP_URL . '/admin/plans.php');
    }
}

$plans = $pdo->query("
    SELECT p.*, COUNT(s.id) as subscriber_count
    FROM subscription_plans p
    LEFT JOIN subscriptions s ON p.id = s.plan_id AND s.status IN ('active','trial')
    GROUP BY p.id ORDER BY p.price_monthly ASC
")->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-layer-group me-2 text-green"></i>Subscription Plans</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Plans</li></ol></nav>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#planModal" onclick="resetPlanForm()">
    <i class="fas fa-plus me-2"></i>New Plan
  </button>
</div>

<div class="row g-4 mb-4">
  <?php foreach($plans as $p): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 <?= $p['is_popular'] ? 'border-success' : '' ?>">
      <?php if($p['is_popular']): ?><div class="card-header bg-success text-white py-1 text-center small fw-600">⭐ Most Popular</div><?php endif; ?>
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div>
            <h5 class="fw-800 text-navy mb-0"><?= e($p['name']) ?></h5>
            <div class="text-muted small"><?= e($p['description']) ?></div>
          </div>
          <?= statusBadge($p['status']) ?>
        </div>
        <div class="mb-3">
          <div class="fw-800 text-green" style="font-size:1.6rem">KES <?= number_format($p['price_monthly']) ?><span class="text-muted fw-400" style="font-size:.85rem">/mo</span></div>
          <div class="text-muted small">KES <?= number_format($p['price_annual']) ?>/year (save <?= round((1-($p['price_annual']/(12*$p['price_monthly'])))*100) ?>%)</div>
        </div>
        <ul class="list-unstyled mb-3 small">
          <li class="mb-1"><i class="fas fa-check text-green me-2"></i>Up to <strong><?= $p['max_users'] ?></strong> users</li>
          <li class="mb-1"><i class="fas fa-check text-green me-2"></i>Up to <strong><?= $p['max_modules'] ?></strong> modules</li>
          <li><i class="fas fa-users text-blue me-2"></i><strong><?= $p['subscriber_count'] ?></strong> active subscribers</li>
        </ul>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editPlan(<?= htmlspecialchars(json_encode($p)) ?>)">
            <i class="fas fa-edit me-1"></i>Edit
          </button>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="delete_plan">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    data-confirm="Delete plan '<?= e($p['name']) ?>'? This cannot be undone if subscribers exist.">
              <i class="fas fa-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Add new card placeholder -->
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 border-dashed" style="border:2px dashed var(--gray-200);cursor:pointer" data-bs-toggle="modal" data-bs-target="#planModal" onclick="resetPlanForm()">
      <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted py-5">
        <i class="fas fa-plus-circle fa-2x mb-2" style="color:var(--gray-200)"></i>
        <div class="fw-600">Add New Plan</div>
      </div>
    </div>
  </div>
</div>

<!-- Plans comparison table -->
<div class="card">
  <div class="card-header"><i class="fas fa-table text-green me-2"></i>Plans Comparison</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>Plan</th>
            <th>Monthly Price</th>
            <th>Annual Price</th>
            <th>Max Users</th>
            <th>Max Modules</th>
            <th>Subscribers</th>
            <th>Status</th>
            <th>Popular</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($plans as $p): ?>
          <tr>
            <td class="fw-600"><?= e($p['name']) ?></td>
            <td><?= formatCurrency($p['price_monthly']) ?></td>
            <td><?= formatCurrency($p['price_annual']) ?></td>
            <td><?= $p['max_users'] ?></td>
            <td><?= $p['max_modules'] ?></td>
            <td><span class="badge bg-primary"><?= $p['subscriber_count'] ?></span></td>
            <td><?= statusBadge($p['status']) ?></td>
            <td><?= $p['is_popular'] ? '<span class="badge bg-warning text-dark">Yes</span>' : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Plan Modal -->
<div class="modal fade" id="planModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title" id="planModalTitle"><i class="fas fa-layer-group me-2"></i>New Plan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="plan_id" id="planId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Plan Name *</label>
              <input type="text" name="name" id="planName" class="form-control" required placeholder="e.g. Starter, Professional">
            </div>
            <div class="col-md-6">
              <label class="form-label">Status</label>
              <select name="status" id="planStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <input type="text" name="description" id="planDesc" class="form-control" placeholder="Brief description of this plan">
            </div>
            <div class="col-md-6">
              <label class="form-label">Monthly Price (KES) *</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="price_monthly" id="planPriceM" class="form-control" required min="0" step="1">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Annual Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="price_annual" id="planPriceA" class="form-control" min="0" step="1">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Max Users</label>
              <input type="number" name="max_users" id="planMaxUsers" class="form-control" min="1" value="5">
            </div>
            <div class="col-md-6">
              <label class="form-label">Max Modules</label>
              <input type="number" name="max_modules" id="planMaxMods" class="form-control" min="1" max="20" value="3">
            </div>
            <div class="col-12">
              <label class="form-label">Features (one per line)</label>
              <textarea name="features" id="planFeatures" class="form-control" rows="4" placeholder="24/7 support&#10;API access&#10;Custom branding"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_popular" id="planPopular">
                <label class="form-check-label" for="planPopular">Mark as Most Popular (shown on pricing page)</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Plan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function resetPlanForm() {
  document.getElementById("planModalTitle").innerHTML = \'<i class="fas fa-layer-group me-2"></i>New Plan\';
  document.getElementById("planId").value = "0";
  document.getElementById("planName").value = "";
  document.getElementById("planDesc").value = "";
  document.getElementById("planPriceM").value = "";
  document.getElementById("planPriceA").value = "";
  document.getElementById("planMaxUsers").value = "5";
  document.getElementById("planMaxMods").value = "3";
  document.getElementById("planFeatures").value = "";
  document.getElementById("planPopular").checked = false;
  document.getElementById("planStatus").value = "active";
}
function editPlan(p) {
  document.getElementById("planModalTitle").innerHTML = \'<i class="fas fa-edit me-2"></i>Edit Plan: \' + p.name;
  document.getElementById("planId").value = p.id;
  document.getElementById("planName").value = p.name;
  document.getElementById("planDesc").value = p.description || "";
  document.getElementById("planPriceM").value = p.price_monthly;
  document.getElementById("planPriceA").value = p.price_annual;
  document.getElementById("planMaxUsers").value = p.max_users;
  document.getElementById("planMaxMods").value = p.max_modules;
  document.getElementById("planPopular").checked = p.is_popular == 1;
  document.getElementById("planStatus").value = p.status;
  new bootstrap.Modal(document.getElementById("planModal")).show();
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
