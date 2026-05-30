<?php
// ── POST handlers BEFORE header (prevents headers-already-sent on redirect) ──
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_plan') {
        $id           = (int)($_POST['plan_id']       ?? 0);
        $name         = sanitize($_POST['name']        ?? '');
        $description  = sanitize($_POST['description'] ?? '');
        $maxUsers     = max(1,  (int)($_POST['max_users']     ?? 5));
        $maxModules   = max(1,  (int)($_POST['max_modules']   ?? 3));
        $priceMonthly = max(0, (float)($_POST['price_monthly'] ?? 0));
        $priceAnnual  = max(0, (float)($_POST['price_annual']  ?? 0));
        $isPopular    = isset($_POST['is_popular']) ? 1 : 0;
        $status       = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
        $features     = json_encode(array_values(array_filter(array_map('trim', explode("\n", $_POST['features'] ?? '')))));

        if (!$name) {
            setFlash('danger', 'Plan name is required.');
        } else {
            if ($id) {
                $pdo->prepare("UPDATE subscription_plans SET name=?,description=?,max_users=?,max_modules=?,price_monthly=?,price_annual=?,is_popular=?,status=?,features=? WHERE id=?")
                    ->execute([$name,$description,$maxUsers,$maxModules,$priceMonthly,$priceAnnual,$isPopular,$status,$features,$id]);
                setFlash('success', "Plan '{$name}' updated successfully.");
            } else {
                $pdo->prepare("INSERT INTO subscription_plans (name,description,max_users,max_modules,price_monthly,price_annual,is_popular,status,features) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$name,$description,$maxUsers,$maxModules,$priceMonthly,$priceAnnual,$isPopular,$status,$features]);
                setFlash('success', "Plan '{$name}' created successfully.");
            }
        }
        redirect(APP_URL . '/admin/plans.php');
    }
}

// ── Page ──────────────────────────────────────────────────────────
$pageTitle = 'Subscription Plans';
require_once __DIR__ . '/../includes/header-admin.php';

$plans = $pdo->query("
    SELECT p.*, COUNT(s.id) AS subscriber_count
    FROM subscription_plans p
    LEFT JOIN subscriptions s ON p.id = s.plan_id AND s.status IN ('active','trial')
    GROUP BY p.id ORDER BY p.price_monthly ASC
")->fetchAll();

// USD exchange rate (editable in settings; default 130 KES per 1 USD)
$usdRate = (float)(getSetting('usd_rate', '130') ?: 130);
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-layer-group me-2 text-green"></i>Subscription Plans</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Plans</li></ol></nav>
  </div>
  <div class="d-flex align-items-center gap-3">
    <!-- Currency toggle -->
    <div class="btn-group btn-group-sm" role="group" aria-label="Currency">
      <button type="button" id="btnKES" class="btn btn-success" onclick="setCurrency('KES')">
        <i class="fas fa-coins me-1"></i>KES
      </button>
      <button type="button" id="btnUSD" class="btn btn-outline-secondary" onclick="setCurrency('USD')">
        <i class="fas fa-dollar-sign me-1"></i>USD
      </button>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#planModal" onclick="resetPlanForm()">
      <i class="fas fa-plus me-2"></i>New Plan
    </button>
  </div>
</div>

<?= flashAlert() ?>

<!-- Plan cards -->
<div class="row g-4 mb-4" id="planCards">
  <?php foreach ($plans as $p):
    $savePct = ($p['price_monthly'] > 0)
      ? round((1 - ($p['price_annual'] / (12 * $p['price_monthly']))) * 100)
      : 0;
  ?>
  <div class="col-md-6 col-lg-4" id="planCard<?= $p['id'] ?>">
    <div class="card h-100 <?= $p['is_popular'] ? 'border-success border-2' : '' ?>">
      <?php if ($p['is_popular']): ?>
      <div class="card-header bg-success text-white py-1 text-center small fw-semibold">⭐ Most Popular</div>
      <?php endif; ?>
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-3">
          <div>
            <h5 class="fw-bold text-navy mb-0"><?= e($p['name']) ?></h5>
            <div class="text-muted small"><?= e($p['description']) ?></div>
          </div>
          <?= statusBadge($p['status']) ?>
        </div>

        <!-- Prices — raw KES values embedded as data attrs; JS switches display -->
        <div class="mb-3">
          <div class="fw-bold text-success" style="font-size:1.55rem">
            <span class="currency-symbol">KES</span>
            <span class="price-monthly" data-kes="<?= (int)$p['price_monthly'] ?>">
              <?= number_format($p['price_monthly']) ?>
            </span>
            <span class="text-muted fw-normal" style="font-size:.85rem">/mo</span>
          </div>
          <div class="text-muted small">
            <span class="currency-symbol">KES</span>
            <span class="price-annual" data-kes="<?= (int)$p['price_annual'] ?>">
              <?= number_format($p['price_annual']) ?>
            </span>/year
            <?php if ($savePct > 0): ?>
            <span class="badge bg-success ms-1">Save <?= $savePct ?>%</span>
            <?php endif; ?>
          </div>
        </div>

        <ul class="list-unstyled mb-3 small">
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Up to <strong><?= $p['max_users'] ?></strong> users</li>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Up to <strong><?= $p['max_modules'] ?></strong> modules</li>
          <li><i class="fas fa-users text-primary me-2"></i><strong><?= $p['subscriber_count'] ?></strong> active subscribers</li>
        </ul>

        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary flex-fill"
                  onclick='editPlan(<?= htmlspecialchars(json_encode($p), ENT_QUOTES) ?>)'>
            <i class="fas fa-edit me-1"></i>Edit
          </button>
          <button class="btn btn-sm btn-outline-danger"
                  onclick="deletePlan(<?= $p['id'] ?>, '<?= e($p['name']) ?>', <?= $p['subscriber_count'] ?>)"
                  <?= $p['subscriber_count'] > 0 ? 'title="Plan has active subscribers"' : '' ?>>
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- Add new placeholder -->
  <div class="col-md-6 col-lg-4">
    <div class="card h-100" style="border:2px dashed #dee2e6;cursor:pointer"
         data-bs-toggle="modal" data-bs-target="#planModal" onclick="resetPlanForm()">
      <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted py-5">
        <i class="fas fa-plus-circle fa-2x mb-2 opacity-25"></i>
        <div class="fw-semibold">Add New Plan</div>
      </div>
    </div>
  </div>
</div>

<!-- Plans comparison table -->
<div class="card">
  <div class="card-header fw-semibold"><i class="fas fa-table text-success me-2"></i>Plans Comparison</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Plan</th>
            <th>Monthly</th>
            <th>Annual</th>
            <th>Max Users</th>
            <th>Max Modules</th>
            <th>Subscribers</th>
            <th>Status</th>
            <th>Popular</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($plans as $p): ?>
          <tr>
            <td class="fw-semibold"><?= e($p['name']) ?></td>
            <td>
              <span class="currency-symbol">KES</span>
              <span class="price-monthly" data-kes="<?= (int)$p['price_monthly'] ?>">
                <?= number_format($p['price_monthly']) ?>
              </span>
            </td>
            <td>
              <span class="currency-symbol">KES</span>
              <span class="price-annual" data-kes="<?= (int)$p['price_annual'] ?>">
                <?= number_format($p['price_annual']) ?>
              </span>
            </td>
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

<!-- Plan Add / Edit Modal -->
<div class="modal fade" id="planModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title" id="planModalTitle"><i class="fas fa-layer-group me-2"></i>New Plan</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_plan">
        <input type="hidden" name="plan_id" id="planId" value="0">

        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Plan Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="planName" class="form-control" required placeholder="e.g. Starter, Professional">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="planStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" id="planDesc" class="form-control" placeholder="Brief description of this plan">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Monthly Price (KES) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="price_monthly" id="planPriceM" class="form-control" required min="0" step="1" placeholder="0">
              </div>
              <div class="form-text" id="priceMonthlyUsd"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Annual Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="price_annual" id="planPriceA" class="form-control" min="0" step="1" placeholder="0">
              </div>
              <div class="form-text" id="priceAnnualUsd"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Max Users</label>
              <input type="number" name="max_users" id="planMaxUsers" class="form-control" min="1" value="5">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Max Modules</label>
              <input type="number" name="max_modules" id="planMaxMods" class="form-control" min="1" max="25" value="5">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Features <span class="text-muted fw-normal">(one per line)</span></label>
              <textarea name="features" id="planFeatures" class="form-control" rows="4"
                        placeholder="24/7 Support&#10;API Access&#10;Custom Branding"></textarea>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="is_popular" id="planPopular">
                <label class="form-check-label" for="planPopular">Mark as <strong>Most Popular</strong> (highlighted on pricing page)</label>
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
// Embed PHP values as JS constants then append the static JS block (nowdoc — no PHP interpolation)
$extraJs = '<script>
const USD_RATE = ' . (float)$usdRate . ';
const AJAX_URL  = ' . json_encode(APP_URL . '/admin/ajax.php') . ';
</script>
' . <<<'JSEOF'
<script>
let activeCurrency = localStorage.getItem('planCurrency') || 'KES';

// ── Currency toggle ────────────────────────────────────────────────
function setCurrency(cur) {
  activeCurrency = cur;
  localStorage.setItem('planCurrency', cur);

  document.getElementById('btnKES').className = cur === 'KES'
    ? 'btn btn-sm btn-success' : 'btn btn-sm btn-outline-secondary';
  document.getElementById('btnUSD').className = cur === 'USD'
    ? 'btn btn-sm btn-primary' : 'btn btn-sm btn-outline-secondary';

  document.querySelectorAll('.price-monthly, .price-annual').forEach(function(el) {
    var kes = parseFloat(el.dataset.kes) || 0;
    el.textContent = cur === 'USD'
      ? (kes / USD_RATE).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2})
      : kes.toLocaleString('en-KE');
  });

  document.querySelectorAll('.currency-symbol').forEach(function(el) {
    el.textContent = cur === 'USD' ? 'USD ' : 'KES ';
  });

  updateModalHints();
}

// ── Modal USD equivalent hints ─────────────────────────────────────
function updateModalHints() {
  var m = parseFloat(document.getElementById('planPriceM').value) || 0;
  var a = parseFloat(document.getElementById('planPriceA').value) || 0;
  document.getElementById('priceMonthlyUsd').textContent = m > 0
    ? '≈ USD ' + (m / USD_RATE).toFixed(2) + ' per month' : '';
  document.getElementById('priceAnnualUsd').textContent  = a > 0
    ? '≈ USD ' + (a / USD_RATE).toFixed(2) + ' per year'  : '';
}

document.getElementById('planPriceM').addEventListener('input', updateModalHints);
document.getElementById('planPriceA').addEventListener('input', updateModalHints);

// Apply saved currency on page load
setCurrency(activeCurrency);

// ── Plan modal helpers ─────────────────────────────────────────────
function resetPlanForm() {
  document.getElementById('planModalTitle').innerHTML = '<i class="fas fa-layer-group me-2"></i>New Plan';
  ['planId','planName','planDesc','planPriceM','planPriceA'].forEach(function(id) {
    document.getElementById(id).value = id === 'planId' ? '0' : '';
  });
  document.getElementById('planMaxUsers').value  = '5';
  document.getElementById('planMaxMods').value   = '5';
  document.getElementById('planFeatures').value  = '';
  document.getElementById('planPopular').checked = false;
  document.getElementById('planStatus').value    = 'active';
  document.getElementById('priceMonthlyUsd').textContent = '';
  document.getElementById('priceAnnualUsd').textContent  = '';
}

function editPlan(p) {
  document.getElementById('planModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Plan: ' + p.name;
  document.getElementById('planId').value        = p.id;
  document.getElementById('planName').value      = p.name        || '';
  document.getElementById('planDesc').value      = p.description || '';
  document.getElementById('planPriceM').value    = p.price_monthly;
  document.getElementById('planPriceA').value    = p.price_annual;
  document.getElementById('planMaxUsers').value  = p.max_users;
  document.getElementById('planMaxMods').value   = p.max_modules;
  document.getElementById('planStatus').value    = p.status;
  document.getElementById('planPopular').checked = p.is_popular == 1;
  try {
    var feats = JSON.parse(p.features || '[]');
    document.getElementById('planFeatures').value = Array.isArray(feats) ? feats.join('\n') : (p.features || '');
  } catch(e) {
    document.getElementById('planFeatures').value = p.features || '';
  }
  updateModalHints();
  new bootstrap.Modal(document.getElementById('planModal')).show();
}

// ── AJAX delete with SweetAlert confirmation ───────────────────────
function deletePlan(id, name, subCount) {
  if (subCount > 0) {
    Swal.fire({
      icon: 'warning',
      title: 'Cannot Delete',
      html: '<strong>' + name + '</strong> has <strong>' + subCount + '</strong> active subscriber(s).<br>Deactivate the plan instead.',
      confirmButtonColor: '#0B2D4E'
    });
    return;
  }
  Swal.fire({
    title: 'Delete Plan?',
    html: 'Are you sure you want to permanently delete <strong>' + name + '</strong>? This cannot be undone.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    cancelButtonColor:  '#6c757d',
    confirmButtonText: '<i class="fas fa-trash me-1"></i>Yes, Delete',
    cancelButtonText:  'Cancel'
  }).then(function(result) {
    if (!result.isConfirmed) return;

    fetch(AJAX_URL, {
      method:  'POST',
      headers: {'Content-Type': 'application/json'},
      body:    JSON.stringify({action: 'delete_plan', id: id})
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        var card = document.getElementById('planCard' + id);
        if (card) card.remove();
        Swal.fire({icon:'success', title:'Deleted', text: name + ' has been removed.', timer:1800, showConfirmButton:false});
      } else {
        Swal.fire({icon:'error', title:'Error', text: res.error || 'Could not delete plan.'});
      }
    })
    .catch(function() {
      Swal.fire({icon:'error', title:'Network Error', text:'Could not reach server.'});
    });
  });
}
</script>
JSEOF;
require_once __DIR__ . '/../includes/footer.php';
?>
