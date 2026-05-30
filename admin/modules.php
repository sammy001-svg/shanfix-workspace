<?php
// ── POST: save_module BEFORE header to avoid headers-already-sent ─
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if (($_POST['action'] ?? '') === 'save_module') {
        $id           = (int)($_POST['module_id']      ?? 0);
        $name         = sanitize($_POST['name']         ?? '');
        $description  = sanitize($_POST['description']  ?? '');
        $priceMonthly = max(0, (float)($_POST['monthly_price'] ?? 0));
        $priceAnnual  = max(0, (float)($_POST['annual_price']  ?? 0));
        $icon         = sanitize($_POST['icon']         ?? 'fas fa-puzzle-piece');
        $color        = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['color'] ?? '') ? $_POST['color'] : '#1A8A4E';
        $sortOrder    = (int)($_POST['sort_order']      ?? 0);
        $status       = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$id || !$name) {
            setFlash('danger', 'Module ID and name are required.');
        } else {
            $pdo->prepare("
                UPDATE modules
                SET name=?,description=?,monthly_price=?,annual_price=?,
                    icon=?,color=?,sort_order=?,status=?
                WHERE id=?
            ")->execute([$name,$description,$priceMonthly,$priceAnnual,$icon,$color,$sortOrder,$status,$id]);
            logActivity('edit_module', 'admin', "Updated module: $name");
            setFlash('success', "Module '{$name}' updated.");
        }
        redirect(APP_URL . '/admin/modules.php');
    }
}

// ── Page ──────────────────────────────────────────────────────────
$pageTitle = 'Module Management';
require_once __DIR__ . '/../includes/header-admin.php';

$modules = $pdo->query("
    SELECT m.*, COUNT(sm.id) AS subscriber_count
    FROM modules m
    LEFT JOIN subscription_modules sm ON m.id = sm.module_id AND sm.status='active'
    GROUP BY m.id ORDER BY m.sort_order
")->fetchAll();

$totalActive   = count(array_filter($modules, fn($m) => $m['status'] === 'active'));
$totalInactive = count($modules) - $totalActive;
$totalSubs     = array_sum(array_column($modules, 'subscriber_count'));

$usdRate = max(1, (float)(getSetting('usd_rate', '130') ?: 130));

// ── Module preview feature map (shown in preview modal) ───────────
$previewData = [
    'accounting'     => ['Ledger & chart of accounts','Invoicing & receipts','Expense tracking','VAT & tax reports','Bank reconciliation','Profit & loss / balance sheet','Journal entries','Budget management'],
    'crm'            => ['Lead & opportunity pipeline','Contact & company management','Activity & follow-up logging','Deal stages & conversion','Customer interaction history','Sales performance analytics','Email & call logging','Quotation builder'],
    'sales'          => ['Sales order management','Quotation & proposal builder','Customer & pricing management','Product catalogue with variants','Sales rep performance reports','Revenue & margin analytics','Order fulfillment tracking','Delivery management'],
    'meetings'       => ['Meeting scheduling & calendar','Attendee & RSVP management','Agenda creation & distribution','Action item & minutes tracking','Recurring meetings','Video & location details','Post-meeting follow-ups','Meeting analytics'],
    'school'         => ['Student enrollment & profiles','Class & subject management','Timetable generator','Attendance tracking','Exam & results management','Fee billing & receipts','Library management','Parent portal & communication'],
    'health'         => ['Patient registration & profiles','Appointment scheduling','Doctor & staff management','Prescriptions management','Lab test management','Pharmacy & dispensing','In-patient ward management','Billing & insurance'],
    'pos'            => ['Point of sale terminal','Product & category management','Inventory tracking','Customer management','Discounts & promotions','Returns & refunds','Shift management','Sales analytics & reports'],
    'sacco'          => ['Member registration & KYC','Savings deposits & withdrawals','Loan applications & disbursements','Guarantor management','Loan repayment schedules','Penalties & interest calculation','Financial statements','Member statements'],
    'rental'         => ['Property & unit management','Tenant registration & agreements','Rent payment tracking','Utility billing','Maintenance & inspection','Vacancy management','Lease renewals','Income & expense reports'],
    'church'         => ['Member registration & groups','Attendance tracking','Offering & tithe management','Event & fellowship management','Pastoral visits','Pledge & project management','Cell group management','Communication & notices'],
    'finance'        => ['Budget planning & tracking','Income & expenditure management','Financial forecasting','Grant & fund management','Donor management','Audit trail & reports','Multi-currency support','Department allocation'],
    'hotel'          => ['Room & property management','Guest check-in & check-out','Booking & reservation calendar','Housekeeping management','Restaurant & F&B management','Invoicing & billing','Room service tracking','Occupancy analytics'],
    'salon'          => ['Appointment booking & calendar','Service & pricing catalogue','Stylist & staff scheduling','Client visit history','POS & product retail sales','Loyalty points & membership','Staff commission tracking','Revenue reports'],
    'retail'         => ['Product & inventory management','Stock level tracking','Purchase orders & suppliers','Sales & cashier POS','Customer management','Transfers between branches','Expense management','Sales & stock reports'],
    'tour'           => ['Tour package creation','Booking & itinerary management','Guide & vehicle assignment','Customer billing & receipts','Booking availability calendar','Revenue & booking reports','Agent commission tracking','Inquiry management'],
    'events'         => ['Event creation & publishing','Attendee registration & tickets','Venue & seat management','Speaker & sponsor management','Check-in management','Payment & ticket sales','Post-event analytics','Notification & reminders'],
    'manufacturing'  => ['Bill of materials (BOM)','Work order management','Production planning','Raw material tracking','Machine & equipment management','Quality control checks','Procurement & suppliers','Cost of production reports'],
    'hrm'            => ['Employee profiles & contracts','Payroll processing & payslips','Attendance & time tracking','Leave request & approval','Recruitment pipeline','Performance appraisals','Department & org chart','HR analytics & reports'],
    'caryard'        => ['Vehicle inventory management','Customer inquiry management','Test drive scheduling','Sales & financing management','Vehicle reconditioning','Insurance & valuation','Parts inventory','Sales performance reports'],
    'shopping-mall'  => ['Tenant & unit management','Lease agreement tracking','Rent & service charge billing','Maintenance requests','Common area management','Visitor & footfall tracking','Tenant communication','Financial reporting'],
    'courier'        => ['Shipment creation & tracking','Branch & agent management','Delivery route management','Customer portal & notifications','Proof of delivery','Pricing & billing','Real-time tracking dashboard','Performance reports'],
    'driving'        => ['Student registration & enrollment','Lesson scheduling & timetable','Instructor management','Vehicle assignment','Test & exam management','Fee billing & receipts','Attendance tracking','Certification management'],
];
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-puzzle-piece me-2 text-green"></i>Module Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Modules</li>
    </ol></nav>
  </div>
  <!-- Currency toggle -->
  <div class="currency-pill-admin d-flex align-items-center gap-1">
    <span class="small text-muted me-1">Display prices in:</span>
    <button id="btnUSD" class="cur-btn active" onclick="setCurrency('USD')">$ USD</button>
    <button id="btnKES" class="cur-btn"         onclick="setCurrency('KES')">KES</button>
  </div>
</div>

<style>
.currency-pill-admin { background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:.4rem .75rem; }
.cur-btn { border:none; background:transparent; padding:.28rem .75rem; border-radius:6px; font-size:.78rem; font-weight:700; color:#64748b; cursor:pointer; transition:all .18s; }
.cur-btn.active { background:var(--navy,#0B2D4E); color:#fff; }
.module-price-box { border-radius:8px; padding:.5rem .75rem; text-align:center; }
.preview-feature { padding:.3rem 0; border-bottom:1px solid #f1f5f9; font-size:.83rem; color:#374151; }
.preview-feature:last-child { border-bottom:none; }
.role-chip { display:inline-flex;align-items:center;gap:.35rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:20px;padding:.25rem .65rem;font-size:.72rem;font-weight:600;color:#374151;margin:.15rem; }
</style>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon"><i class="fas fa-puzzle-piece"></i></div>
      <div><div class="stat-value"><?= count($modules) ?></div><div class="stat-label">Total Modules</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning"><div class="stat-icon"><i class="fas fa-pause-circle"></i></div>
      <div><div class="stat-value"><?= $totalInactive ?></div><div class="stat-label">Inactive</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon"><i class="fas fa-users"></i></div>
      <div><div class="stat-value"><?= $totalSubs ?></div><div class="stat-label">Subscriptions</div></div></div>
  </div>
</div>

<!-- Module grid -->
<div class="row g-3">
  <?php foreach ($modules as $m):
    $kesMo  = (float)$m['monthly_price'];
    $kesAnn = (float)$m['annual_price'];
    $usdMo  = $kesMo  > 0 ? round($kesMo  / $usdRate, 2) : 0;
    $usdAnn = $kesAnn > 0 ? round($kesAnn / $usdRate, 2) : 0;
    $slug   = $m['slug'] ?? '';
    $feats  = $previewData[$slug] ?? [];
  ?>
  <div class="col-md-6 col-lg-4" id="modCard<?= $m['id'] ?>">
    <div class="card h-100 <?= $m['status'] === 'inactive' ? 'opacity-75' : '' ?>"
         style="<?= $m['status'] === 'inactive' ? 'border-style:dashed' : '' ?>">
      <div class="card-body">

        <!-- Header row -->
        <div class="d-flex align-items-start gap-3 mb-3">
          <div style="width:46px;height:46px;border-radius:12px;background:<?= e($m['color']) ?>1a;
                      color:<?= e($m['color']) ?>;display:flex;align-items:center;
                      justify-content:center;font-size:1.15rem;flex-shrink:0">
            <i class="<?= e($m['icon']) ?>"></i>
          </div>
          <div class="flex-fill min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-bold text-navy"><?= e($m['name']) ?></span>
              <?= statusBadge($m['status']) ?>
            </div>
            <div class="text-muted small"><?= e($m['category'] ?? '') ?> · #<?= $m['sort_order'] ?></div>
          </div>
        </div>

        <!-- Description -->
        <p class="small text-muted mb-3" style="min-height:2.4rem;font-size:.8rem">
          <?= e(mb_substr($m['description'] ?? '', 0, 88)) ?><?= mb_strlen($m['description'] ?? '') > 88 ? '…' : '' ?>
        </p>

        <!-- Pricing row — data-* holds all 4 values; JS switches display -->
        <div class="row g-2 mb-3">
          <div class="col-6">
            <div class="module-price-box" style="background:<?= e($m['color']) ?>15">
              <div class="fw-bold small"
                   data-usd="<?= number_format($usdMo,2) ?>"
                   data-kes="<?= number_format($kesMo,0,'.',',') ?>"
                   style="color:<?= e($m['color']) ?>">
                $ <?= number_format($usdMo,2) ?>
              </div>
              <div class="text-muted" style="font-size:.68rem">/ month</div>
            </div>
          </div>
          <div class="col-6">
            <div class="module-price-box" style="background:#f8fafc">
              <div class="fw-bold small text-navy"
                   data-usd="<?= number_format($usdAnn,2) ?>"
                   data-kes="<?= number_format($kesAnn,0,'.',',') ?>">
                $ <?= number_format($usdAnn,2) ?>
              </div>
              <div class="text-muted" style="font-size:.68rem">/ year</div>
            </div>
          </div>
        </div>

        <!-- Actions row -->
        <div class="d-flex align-items-center justify-content-between">
          <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
            <i class="fas fa-users me-1"></i><?= $m['subscriber_count'] ?> subscribers
          </span>
          <div class="d-flex gap-1">
            <!-- Preview -->
            <button class="btn btn-xs btn-outline-info"
                    onclick="openPreview(<?= htmlspecialchars(json_encode([
                        'id'          => (int)$m['id'],
                        'name'        => $m['name'],
                        'slug'        => $slug,
                        'icon'        => $m['icon'],
                        'color'       => $m['color'],
                        'description' => $m['description'],
                        'category'    => $m['category'] ?? '',
                        'status'      => $m['status'],
                        'subscribers' => (int)$m['subscriber_count'],
                        'monthly_kes' => $kesMo,
                        'annual_kes'  => $kesAnn,
                        'monthly_usd' => $usdMo,
                        'annual_usd'  => $usdAnn,
                        'features'    => $feats,
                        'roles'       => array_values(array_map(
                            fn($r) => ['key' => $r, 'name' => getModuleRoles($slug)[$r]['name'] ?? $r,
                                       'desc' => getModuleRoles($slug)[$r]['desc'] ?? '', 'color' => getModuleRoles($slug)[$r]['color'] ?? '#64748b'],
                            array_keys(getModuleRoles($slug))
                        )),
                    ]), ENT_QUOTES) ?>)"
                    title="Preview module">
              <i class="fas fa-eye"></i>
            </button>
            <!-- Edit -->
            <button class="btn btn-xs btn-outline-primary"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)"
                    title="Edit module">
              <i class="fas fa-edit"></i>
            </button>
            <!-- Toggle active/inactive via AJAX -->
            <button class="btn btn-xs <?= $m['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                    id="toggleBtn<?= $m['id'] ?>"
                    onclick="toggleModule(<?= $m['id'] ?>, '<?= e($m['status']) ?>', '<?= e($m['name']) ?>')"
                    title="<?= $m['status'] === 'active' ? 'Pause module' : 'Activate module' ?>">
              <i class="fas fa-<?= $m['status'] === 'active' ? 'pause' : 'play' ?>"></i>
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══════════════════════════════════════════════════════
     MODULE PREVIEW MODAL
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">

      <!-- Dynamic header coloured to the module's accent -->
      <div class="modal-header text-white" id="previewHeader" style="background:#0B2D4E">
        <div class="d-flex align-items-center gap-3">
          <div id="previewIconWrap"
               style="width:52px;height:52px;border-radius:14px;background:rgba(255,255,255,.18);
                      display:flex;align-items:center;justify-content:center;font-size:1.4rem">
            <i id="previewIcon" class="fas fa-puzzle-piece"></i>
          </div>
          <div>
            <h5 class="modal-title mb-0 fw-bold" id="previewName">Module</h5>
            <div class="small opacity-75" id="previewCat"></div>
          </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- Status + subscribers row -->
        <div class="d-flex align-items-center gap-2 mb-3" id="previewBadges"></div>

        <!-- Description -->
        <p class="text-muted" id="previewDesc" style="font-size:.88rem"></p>

        <div class="row g-4">

          <!-- Left: features list -->
          <div class="col-md-7">
            <h6 class="fw-bold text-navy mb-2"><i class="fas fa-check-circle text-success me-2"></i>Key Features</h6>
            <div id="previewFeatures"></div>
          </div>

          <!-- Right: pricing + roles -->
          <div class="col-md-5">

            <!-- Pricing -->
            <h6 class="fw-bold text-navy mb-2"><i class="fas fa-tags me-2 text-success"></i>Pricing</h6>
            <div class="row g-2 mb-4">
              <div class="col-6">
                <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                  <div class="fw-bold text-success" id="pvMonthly" style="font-size:1rem"></div>
                  <div class="text-muted small">per month</div>
                </div>
              </div>
              <div class="col-6">
                <div class="rounded-3 p-3 text-center" style="background:#eff6ff;border:1px solid #bfdbfe">
                  <div class="fw-bold text-primary" id="pvAnnual" style="font-size:1rem"></div>
                  <div class="text-muted small">per year</div>
                </div>
              </div>
            </div>

            <!-- Roles -->
            <h6 class="fw-bold text-navy mb-2"><i class="fas fa-user-tag me-2 text-success"></i>Available Roles</h6>
            <div id="previewRoles"></div>
          </div>

        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-outline-primary"
                id="previewEditBtn"
                onclick="switchToEdit()">
          <i class="fas fa-edit me-2"></i>Edit Module
        </button>
        <a id="previewGoBtn" href="#" class="btn btn-primary" target="_blank">
          <i class="fas fa-external-link-alt me-2"></i>Open Module
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ══════════════════════════════════════════════════════
     MODULE EDIT MODAL
══════════════════════════════════════════════════════ -->
<div class="modal fade" id="editModuleModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Module: <span id="editModTitle"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action"    value="save_module">
        <input type="hidden" name="module_id" id="editModId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Module Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="editModName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="editModStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Sort Order</label>
              <input type="number" name="sort_order" id="editModSort" class="form-control" min="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <textarea name="description" id="editModDesc" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Monthly Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="monthly_price" id="editModPriceM" class="form-control" min="0" step="1">
              </div>
              <div class="form-text" id="editPriceUsdHintM"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Annual Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="annual_price" id="editModPriceA" class="form-control" min="0" step="1">
              </div>
              <div class="form-text" id="editPriceUsdHintA"></div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Icon <span class="text-muted fw-normal small">(Font Awesome class)</span></label>
              <div class="input-group">
                <span class="input-group-text"><i id="iconPreview" class="fas fa-puzzle-piece"></i></span>
                <input type="text" name="icon" id="editModIcon" class="form-control"
                       placeholder="fas fa-puzzle-piece"
                       oninput="document.getElementById('iconPreview').className=this.value">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Accent Color</label>
              <div class="input-group">
                <input type="color" name="color" id="editModColor" class="form-control form-control-color" style="max-width:48px">
                <input type="text" id="editModColorText" class="form-control" readonly>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$usdRateJs  = (float)$usdRate;
$ajaxUrl    = json_encode(APP_URL . '/admin/ajax.php');
$modBaseUrl = json_encode(APP_URL . '/modules/');
$extraJs    = '<script>
const USD_RATE   = ' . $usdRateJs . ';
const AJAX_URL   = ' . $ajaxUrl . ';
const MOD_URL    = ' . $modBaseUrl . ';
</script>
' . <<<'MODJS'
<script>
let activeCur = localStorage.getItem('adminModCurrency') || 'USD';
let _pvData   = null; // current preview data — used by switchToEdit()

// ── Currency toggle ───────────────────────────────────────────────
function setCurrency(cur) {
  activeCur = cur;
  localStorage.setItem('adminModCurrency', cur);

  document.getElementById('btnUSD').classList.toggle('active', cur === 'USD');
  document.getElementById('btnKES').classList.toggle('active', cur === 'KES');

  var isUSD = (cur === 'USD');
  var sym   = isUSD ? '$ ' : 'KES ';

  document.querySelectorAll('[data-usd][data-kes]').forEach(function(el) {
    el.textContent = sym + (isUSD ? el.dataset.usd : el.dataset.kes);
  });
}

// Apply on load
setCurrency(activeCur);

// ── Edit modal USD hints ──────────────────────────────────────────
function updateEditHints() {
  var m = parseFloat(document.getElementById('editModPriceM').value) || 0;
  var a = parseFloat(document.getElementById('editModPriceA').value) || 0;
  document.getElementById('editPriceUsdHintM').textContent = m > 0 ? '≈ USD ' + (m / USD_RATE).toFixed(2) + '/mo' : '';
  document.getElementById('editPriceUsdHintA').textContent = a > 0 ? '≈ USD ' + (a / USD_RATE).toFixed(2) + '/yr' : '';
}
document.getElementById('editModPriceM').addEventListener('input', updateEditHints);
document.getElementById('editModPriceA').addEventListener('input', updateEditHints);
document.getElementById('editModColor').addEventListener('input', function() {
  document.getElementById('editModColorText').value = this.value;
});

// ── Edit modal open ───────────────────────────────────────────────
function openEditModal(m) {
  document.getElementById('editModTitle').textContent    = m.name;
  document.getElementById('editModId').value             = m.id;
  document.getElementById('editModName').value           = m.name;
  document.getElementById('editModDesc').value           = m.description || '';
  document.getElementById('editModPriceM').value         = m.monthly_price;
  document.getElementById('editModPriceA').value         = m.annual_price;
  document.getElementById('editModSort').value           = m.sort_order;
  document.getElementById('editModStatus').value         = m.status;
  document.getElementById('editModIcon').value           = m.icon || '';
  document.getElementById('iconPreview').className       = m.icon || 'fas fa-puzzle-piece';
  document.getElementById('editModColor').value          = m.color || '#1A8A4E';
  document.getElementById('editModColorText').value      = m.color || '#1A8A4E';
  document.getElementById('editPriceUsdHintM').textContent = '';
  document.getElementById('editPriceUsdHintA').textContent = '';
  new bootstrap.Modal(document.getElementById('editModuleModal')).show();
}

// ── Preview modal ─────────────────────────────────────────────────
function openPreview(m) {
  _pvData = m;
  var isUSD = (activeCur === 'USD');
  var sym   = isUSD ? 'USD ' : 'KES ';
  var moVal = isUSD ? m.monthly_usd.toFixed(2) : Number(m.monthly_kes).toLocaleString('en-KE');
  var anVal = isUSD ? m.annual_usd.toFixed(2)  : Number(m.annual_kes).toLocaleString('en-KE');

  // Header
  var hdr = document.getElementById('previewHeader');
  hdr.style.background = m.color;
  document.getElementById('previewIcon').className = m.icon + ' text-white';
  document.getElementById('previewName').textContent = m.name;
  document.getElementById('previewCat').textContent  = (m.category || '') + ' Module';

  // Badges
  var badgeHtml = (m.status === 'active'
    ? '<span class="badge bg-success">Active</span>'
    : '<span class="badge bg-secondary">Inactive</span>');
  badgeHtml += '<span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 ms-1"><i class="fas fa-users me-1"></i>' + m.subscribers + ' subscribers</span>';
  document.getElementById('previewBadges').innerHTML = badgeHtml;

  // Description
  document.getElementById('previewDesc').textContent = m.description || '';

  // Features
  var fHtml = '';
  if (m.features && m.features.length) {
    m.features.forEach(function(f) {
      fHtml += '<div class="preview-feature"><i class="fas fa-check text-success me-2 small"></i>' + f + '</div>';
    });
  } else {
    fHtml = '<div class="text-muted small">Feature list coming soon.</div>';
  }
  document.getElementById('previewFeatures').innerHTML = fHtml;

  // Pricing
  document.getElementById('pvMonthly').textContent = '$ ' + m.monthly_usd.toFixed(2) + ' / mo\n≈ KES ' + Number(m.monthly_kes).toLocaleString('en-KE');
  document.getElementById('pvAnnual').textContent  = '$ ' + m.annual_usd.toFixed(2)  + ' / yr\n≈ KES ' + Number(m.annual_kes).toLocaleString('en-KE');
  // Use innerHTML for line breaks
  document.getElementById('pvMonthly').innerHTML =
    '<span style="font-size:1.05rem">$ ' + m.monthly_usd.toFixed(2) + '</span>' +
    '<div class="text-muted small mt-1" style="font-size:.72rem">≈ KES ' + Number(m.monthly_kes).toLocaleString('en-KE') + '</div>';
  document.getElementById('pvAnnual').innerHTML  =
    '<span style="font-size:1.05rem">$ ' + m.annual_usd.toFixed(2) + '</span>' +
    '<div class="text-muted small mt-1" style="font-size:.72rem">≈ KES ' + Number(m.annual_kes).toLocaleString('en-KE') + '</div>';

  // Roles
  var rHtml = '';
  if (m.roles && m.roles.length) {
    m.roles.forEach(function(r) {
      rHtml += '<div class="mb-2 p-2 rounded" style="background:#f8fafc;border-left:3px solid ' + (r.color||'#64748b') + '">' +
               '<div class="fw-semibold small" style="color:' + (r.color||'#374151') + '">' + r.name + '</div>' +
               '<div class="text-muted small" style="font-size:.72rem">' + (r.desc||'') + '</div></div>';
    });
  }
  document.getElementById('previewRoles').innerHTML = rHtml || '<div class="text-muted small">Roles not configured.</div>';

  // Footer buttons
  document.getElementById('previewGoBtn').href    = MOD_URL + m.slug + '/index.php';
  document.getElementById('previewGoBtn').style.display = m.status === 'active' ? '' : 'none';

  new bootstrap.Modal(document.getElementById('previewModal')).show();
}

function switchToEdit() {
  if (!_pvData) return;
  bootstrap.Modal.getInstance(document.getElementById('previewModal'))?.hide();
  setTimeout(function() { openEditModal(_pvData); }, 300);
}

// ── AJAX toggle (pause / activate) ───────────────────────────────
function toggleModule(id, current, name) {
  var action  = current === 'active' ? 'Pause' : 'Activate';
  var icon    = current === 'active' ? 'fa-pause' : 'fa-play';
  var message = current === 'active'
    ? 'Pausing <strong>' + name + '</strong> will hide it from all client workspaces. Continue?'
    : 'Activating <strong>' + name + '</strong> will make it available to subscribers. Continue?';

  Swal.fire({
    title: action + ' Module?',
    html:  message,
    icon:  current === 'active' ? 'warning' : 'question',
    showCancelButton: true,
    confirmButtonColor: current === 'active' ? '#e74c3c' : '#1A8A4E',
    confirmButtonText: '<i class="fas ' + icon + ' me-1"></i>' + action,
    cancelButtonText: 'Cancel'
  }).then(function(result) {
    if (!result.isConfirmed) return;

    fetch(AJAX_URL, {
      method:  'POST',
      headers: {'Content-Type': 'application/json'},
      body:    JSON.stringify({action: 'toggle_module_status', id: id, current: current})
    })
    .then(function(r) { return r.json(); })
    .then(function(res) {
      if (res.success) {
        var newStatus = res.new_status;
        var card      = document.getElementById('modCard' + id);
        var btn       = document.getElementById('toggleBtn' + id);

        // Update card opacity / border
        card.querySelector('.card').classList.toggle('opacity-75', newStatus === 'inactive');
        card.querySelector('.card').style.borderStyle = newStatus === 'inactive' ? 'dashed' : '';

        // Update status badge (first badge in the header row)
        var badgeEl = card.querySelector('.badge');
        if (badgeEl) {
          badgeEl.className = 'badge bg-' + (newStatus === 'active' ? 'success' : 'secondary');
          badgeEl.textContent = newStatus.charAt(0).toUpperCase() + newStatus.slice(1);
        }

        // Update button
        btn.className = 'btn btn-xs ' + (newStatus === 'active' ? 'btn-outline-danger' : 'btn-outline-success');
        btn.setAttribute('onclick', 'toggleModule(' + id + ', "' + newStatus + '", "' + name + '")');
        btn.title = newStatus === 'active' ? 'Pause module' : 'Activate module';
        btn.innerHTML = '<i class="fas fa-' + (newStatus === 'active' ? 'pause' : 'play') + '"></i>';

        Swal.fire({
          icon: 'success', title: 'Done',
          text: name + ' is now ' + newStatus + '.',
          timer: 1600, showConfirmButton: false
        });
      } else {
        Swal.fire({icon: 'error', title: 'Error', text: res.error || 'Could not update module.'});
      }
    })
    .catch(function() {
      Swal.fire({icon: 'error', title: 'Network Error', text: 'Could not reach server.'});
    });
  });
}
</script>
MODJS;
require_once __DIR__ . '/../includes/footer.php';
?>
