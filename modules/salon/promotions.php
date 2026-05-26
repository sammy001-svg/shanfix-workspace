<?php
// ── Salon: Promotions & Discount Campaigns ──────────────────────
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $title        = sanitize($_POST['title'] ?? '');
        $promoType    = in_array($_POST['promo_type'] ?? '', ['percentage','fixed','buy_x_get_y','free_service']) ? $_POST['promo_type'] : 'percentage';
        $discountVal  = (float)($_POST['discount_value'] ?? 0);
        $startDate    = sanitize($_POST['start_date'] ?? '');
        $endDate      = sanitize($_POST['end_date'] ?? '');
        $minPurchase  = (float)($_POST['min_purchase'] ?? 0);
        $usageLimit   = (int)($_POST['usage_limit'] ?? 0) ?: null;
        $status       = in_array($_POST['status'] ?? '', ['active','paused','expired']) ? $_POST['status'] : 'active';
        $description  = sanitize($_POST['description'] ?? '');

        if ($id) {
            $pdo->prepare("UPDATE salon_promotions SET title=?,promo_type=?,discount_value=?,start_date=?,end_date=?,min_purchase=?,usage_limit=?,status=?,description=? WHERE id=? AND org_id=?")
                ->execute([$title,$promoType,$discountVal,$startDate,$endDate,$minPurchase,$usageLimit,$status,$description,$id,$orgId]);
            setFlash('success', 'Promotion updated.');
        } else {
            $pdo->prepare("INSERT INTO salon_promotions (org_id,title,promo_type,discount_value,start_date,end_date,min_purchase,usage_limit,status,description) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$title,$promoType,$discountVal,$startDate,$endDate,$minPurchase,$usageLimit,$status,$description]);
            setFlash('success', 'Promotion created.');
        }
    } elseif ($action === 'toggle') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = sanitize($_POST['status'] ?? 'paused');
        $pdo->prepare("UPDATE salon_promotions SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
        setFlash('success', 'Promotion status changed.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM salon_promotions WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Promotion deleted.');
    }
    redirect('promotions.php');
}

// Fetch
$statusFilter = sanitize($_GET['status'] ?? '');
$sql = "SELECT * FROM salon_promotions WHERE org_id=?";
$params = [$orgId];
if ($statusFilter) { $sql .= " AND status=?"; $params[] = $statusFilter; }
$sql .= " ORDER BY start_date DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params);
$promos = $stmt->fetchAll();

// KPIs
$activePromos  = countRows($pdo, 'salon_promotions', 'org_id=? AND status=?', [$orgId,'active']);
$totalPromos   = countRows($pdo, 'salon_promotions', 'org_id=?', [$orgId]);
$today = date('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM salon_promotions WHERE org_id=? AND status='active' AND start_date<=? AND (end_date IS NULL OR end_date>=?)");
$stmt->execute([$orgId,$today,$today]); $liveNow = (int)$stmt->fetchColumn();

// Edit prefill
$editRow = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare("SELECT * FROM salon_promotions WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['edit'],$orgId]); $editRow = $stmt->fetch();
}

$promoTypeLabel = ['percentage'=>'% Discount','fixed'=>'Fixed Amount Off','buy_x_get_y'=>'Buy X Get Y','free_service'=>'Free Service'];
$statusColor = ['active'=>'success','paused'=>'warning','expired'=>'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-tag me-2" style="color:<?= $moduleColor ?>"></i>Promotions & Campaigns</h4>
    <p class="text-muted mb-0">Create discount offers and track their validity periods</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#promoModal" onclick="resetForm()">
    <i class="fas fa-plus me-1"></i>New Promotion
  </button>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-tag"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPromos ?></div><div class="stat-label">Total Promotions</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activePromos ?></div><div class="stat-label">Active Promotions</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-bolt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $liveNow ?></div><div class="stat-label">Live Today</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['active','paused','expired'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($statusFilter): ?><div class="col-auto"><a href="promotions.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<!-- Promotions Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Title</th>
            <th>Type</th>
            <th class="text-center">Discount</th>
            <th class="text-center">Period</th>
            <th class="text-center">Min Purchase</th>
            <th class="text-center">Usage Limit</th>
            <th class="text-center">Status</th>
            <th class="text-end pe-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($promos)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No promotions created yet.</td></tr>
          <?php else: foreach ($promos as $pr): ?>
          <?php $isLive = $pr['status']==='active' && $pr['start_date']<=$today && (!$pr['end_date']||$pr['end_date']>=$today); ?>
          <tr>
            <td class="ps-3 fw-semibold">
              <?= e($pr['title']) ?>
              <?php if ($isLive): ?><span class="badge bg-success ms-1">Live</span><?php endif; ?>
            </td>
            <td><span class="badge bg-info"><?= $promoTypeLabel[$pr['promo_type']] ?? $pr['promo_type'] ?></span></td>
            <td class="text-center fw-bold">
              <?php if ($pr['promo_type']==='percentage'): ?>
              <?= (float)$pr['discount_value'] ?>%
              <?php elseif ($pr['promo_type']==='fixed'): ?>
              <?= formatCurrency($pr['discount_value']) ?> off
              <?php else: ?>
              —
              <?php endif; ?>
            </td>
            <td class="text-center small">
              <?= $pr['start_date'] ? formatDate($pr['start_date']) : '—' ?>
              <?php if ($pr['end_date']): ?> → <?= formatDate($pr['end_date']) ?><?php endif; ?>
            </td>
            <td class="text-center"><?= $pr['min_purchase']>0 ? formatCurrency($pr['min_purchase']) : '—' ?></td>
            <td class="text-center"><?= $pr['usage_limit'] ? (int)$pr['usage_limit'] : 'Unlimited' ?></td>
            <td class="text-center"><span class="badge bg-<?= $statusColor[$pr['status']] ?? 'secondary' ?>"><?= ucfirst($pr['status']) ?></span></td>
            <td class="text-end pe-3">
              <!-- Toggle status -->
              <form method="post" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $pr['id'] ?>">
                <input type="hidden" name="status" value="<?= $pr['status']==='active'?'paused':'active' ?>">
                <button class="btn btn-sm btn-outline-<?= $pr['status']==='active'?'warning':'success' ?> me-1" title="<?= $pr['status']==='active'?'Pause':'Activate' ?>">
                  <i class="fas fa-<?= $pr['status']==='active'?'pause':'play' ?>"></i>
                </button>
              </form>
              <a href="promotions.php?edit=<?= $pr['id'] ?>" class="btn btn-sm btn-outline-primary me-1"
                 data-bs-toggle="modal" data-bs-target="#promoModal"
                 onclick="fillForm(<?= htmlspecialchars(json_encode($pr), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete this promotion?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $pr['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="promoModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-tag me-2"></i><span id="modalTitle">New Promotion</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="fId" value="0">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Promotion Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="fTitle" class="form-control" required placeholder="e.g. January Flash Sale">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Promotion Type</label>
              <select name="promo_type" id="fType" class="form-select" onchange="toggleDiscount()">
                <option value="percentage">% Discount</option>
                <option value="fixed">Fixed Amount Off</option>
                <option value="buy_x_get_y">Buy X Get Y</option>
                <option value="free_service">Free Service</option>
              </select>
            </div>
            <div class="col-md-6" id="discountField">
              <label class="form-label fw-semibold">Discount Value</label>
              <input type="number" name="discount_value" id="fDiscount" class="form-control" step="0.01" min="0" value="0">
              <div class="form-text" id="discountHint">Enter percentage (e.g. 20 = 20%)</div>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="fStart" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" id="fEnd" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="fStatus" class="form-select">
                <option value="active">Active</option>
                <option value="paused">Paused</option>
                <option value="expired">Expired</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Min Purchase (KES)</label>
              <input type="number" name="min_purchase" id="fMinPurchase" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Usage Limit <small class="text-muted">(0 = unlimited)</small></label>
              <input type="number" name="usage_limit" id="fUsageLimit" class="form-control" min="0" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description / Terms</label>
              <textarea name="description" id="fDesc" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Promotion</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function resetForm() {
  document.getElementById('modalTitle').textContent = 'New Promotion';
  document.getElementById('fId').value          = '0';
  document.getElementById('fTitle').value       = '';
  document.getElementById('fType').value        = 'percentage';
  document.getElementById('fDiscount').value    = '0';
  document.getElementById('fStart').value       = new Date().toISOString().substr(0,10);
  document.getElementById('fEnd').value         = '';
  document.getElementById('fStatus').value      = 'active';
  document.getElementById('fMinPurchase').value = '0';
  document.getElementById('fUsageLimit').value  = '0';
  document.getElementById('fDesc').value        = '';
  toggleDiscount();
}
function fillForm(p) {
  document.getElementById('modalTitle').textContent = 'Edit Promotion';
  document.getElementById('fId').value          = p.id;
  document.getElementById('fTitle').value       = p.title;
  document.getElementById('fType').value        = p.promo_type;
  document.getElementById('fDiscount').value    = p.discount_value;
  document.getElementById('fStart').value       = p.start_date;
  document.getElementById('fEnd').value         = p.end_date ?? '';
  document.getElementById('fStatus').value      = p.status;
  document.getElementById('fMinPurchase').value = p.min_purchase;
  document.getElementById('fUsageLimit').value  = p.usage_limit ?? 0;
  document.getElementById('fDesc').value        = p.description ?? '';
  toggleDiscount();
}
function toggleDiscount() {
  var t    = document.getElementById('fType').value;
  var show = (t === 'percentage' || t === 'fixed');
  document.getElementById('discountField').style.display = show ? '' : 'none';
  document.getElementById('discountHint').textContent = t === 'percentage' ? 'Enter percentage (e.g. 20 = 20%)' : 'Enter KES amount off';
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
