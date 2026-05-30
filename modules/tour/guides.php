<?php
// ── TOUR: Guide Management ─────────────────────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour Management';
$moduleIcon  = 'fas fa-map-marked-alt';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'itineraries.php', 'icon' => 'fas fa-route',           'label' => 'Itineraries'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-bus',             'label' => 'Vehicles'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id               = (int)($_POST['id']               ?? 0);
        $name             = sanitize($_POST['name']             ?? '');
        $phone            = sanitize($_POST['phone']            ?? '');
        $email            = sanitize($_POST['email']            ?? '');
        $idNumber         = sanitize($_POST['id_number']        ?? '');
        $specialization   = sanitize($_POST['specialization']   ?? '');
        $languages        = sanitize($_POST['languages']        ?? '');
        $experienceYears  = (int)($_POST['experience_years']    ?? 0);
        $dailyRate        = (float)($_POST['daily_rate']        ?? 0);
        $status           = in_array($_POST['status'] ?? '', ['active','inactive','on_assignment']) ? $_POST['status'] : 'active';

        if (!$name) {
            setFlash('danger', 'Guide name is required.');
            redirect('guides.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE tour_guides SET name=?,phone=?,email=?,id_number=?,specialization=?,languages=?,experience_years=?,daily_rate=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name, $phone, $email, $idNumber, $specialization, $languages, $experienceYears, $dailyRate, $status, $id, $orgId]);
            setFlash('success', 'Guide updated.');
            logActivity('update', 'tour', "Updated guide: $name");
        } else {
            $pdo->prepare("INSERT INTO tour_guides (org_id,name,phone,email,id_number,specialization,languages,experience_years,daily_rate,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId, $name, $phone, $email, $idNumber, $specialization, $languages, $experienceYears, $dailyRate, $status]);
            setFlash('success', "Guide '$name' added.");
            logActivity('create', 'tour', "Added guide: $name");
        }
        redirect('guides.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_guides WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Guide deleted.');
        logActivity('delete', 'tour', "Deleted guide #$id");
        redirect('guides.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$filterStatus = $_GET['status'] ?? '';
$where  = 'org_id = ?';
$params = [$orgId];
if ($filterStatus) { $where .= ' AND status = ?'; $params[] = $filterStatus; }

$guides = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tour_guides WHERE $where ORDER BY name ASC");
    $stmt->execute($params);
    $guides = $stmt->fetchAll();
} catch (Exception $e) {}

$totalCount      = countRows('tour_guides', 'org_id=?', [$orgId]);
$activeCount     = countRows('tour_guides', "org_id=? AND status='active'", [$orgId]);
$assignedCount   = countRows('tour_guides', "org_id=? AND status='on_assignment'", [$orgId]);
$inactiveCount   = countRows('tour_guides', "org_id=? AND status='inactive'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-hiking me-2" style="color:<?= $moduleColor ?>"></i>Tour Guides</h4>
    <p class="text-muted mb-0">Manage your tour guides, their specializations, and availability</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#guideModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Guide
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(22,160,133,.12);color:#16a085"><i class="fas fa-hiking"></i></div>
      <div><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">Total Guides</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Available</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-map-signs"></i></div>
      <div><div class="stat-value"><?= $assignedCount ?></div><div class="stat-label">On Assignment</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-user-slash"></i></div>
      <div><div class="stat-value"><?= $inactiveCount ?></div><div class="stat-label">Inactive</div></div></div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4 col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="active"        <?= $filterStatus==='active'        ?'selected':'' ?>>Available</option>
          <option value="on_assignment" <?= $filterStatus==='on_assignment' ?'selected':'' ?>>On Assignment</option>
          <option value="inactive"      <?= $filterStatus==='inactive'      ?'selected':'' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="guides.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-hiking me-2" style="color:<?= $moduleColor ?>"></i>Guide Roster</h6>
    <span class="badge bg-secondary"><?= count($guides) ?> guides</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Name</th>
            <th>Specialization</th>
            <th>Languages</th>
            <th>Experience</th>
            <th class="text-end">Daily Rate</th>
            <th>Contact</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($guides)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-hiking fa-2x mb-2 d-block"></i>No guides added yet.</td></tr>
          <?php else: foreach ($guides as $g): ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($g['name']) ?></div>
              <?php if ($g['id_number']): ?><div class="small text-muted">ID: <?= e($g['id_number']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= e($g['specialization'] ?: '—') ?></td>
            <td class="small text-muted"><?= e($g['languages'] ?: '—') ?></td>
            <td class="small"><?= $g['experience_years'] ? $g['experience_years'].' yrs' : '—' ?></td>
            <td class="text-end fw-semibold"><?= $g['daily_rate'] ? formatCurrency((float)$g['daily_rate']) : '—' ?></td>
            <td>
              <div class="small"><?= e($g['phone'] ?: '—') ?></div>
              <?php if ($g['email']): ?><div class="small text-muted"><?= e($g['email']) ?></div><?php endif; ?>
            </td>
            <td><?= statusBadge($g['status']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($g), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this guide?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="guideModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="gdeId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="gdeTitle"><i class="fas fa-hiking me-2"></i>Add Guide</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="gdeName" class="form-control" required placeholder="Guide's full name">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">ID / Passport Number</label>
              <input type="text" name="id_number" id="gdeIdNum" class="form-control" placeholder="National ID or passport">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="gdePhone" class="form-control" placeholder="+254...">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="gdeEmail" class="form-control" placeholder="email@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Specialization</label>
              <input type="text" name="specialization" id="gdeSpec" class="form-control" placeholder="e.g. Wildlife, Mountain, Cultural">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Languages Spoken</label>
              <input type="text" name="languages" id="gdeLangs" class="form-control" placeholder="e.g. English, Swahili, French">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Years of Experience</label>
              <input type="number" name="experience_years" id="gdeExp" class="form-control" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Daily Rate (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="daily_rate" id="gdeRate" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="gdeStatus" class="form-select">
                <option value="active">Available</option>
                <option value="on_assignment">On Assignment</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Guide</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("gdeTitle").innerHTML = "<i class=\"fas fa-hiking me-2\"></i>Add Guide";
  document.getElementById("gdeId").value     = 0;
  document.getElementById("gdeName").value   = "";
  document.getElementById("gdeIdNum").value  = "";
  document.getElementById("gdePhone").value  = "";
  document.getElementById("gdeEmail").value  = "";
  document.getElementById("gdeSpec").value   = "";
  document.getElementById("gdeLangs").value  = "";
  document.getElementById("gdeExp").value    = 0;
  document.getElementById("gdeRate").value   = 0;
  document.getElementById("gdeStatus").value = "active";
}
function openEdit(g) {
  document.getElementById("gdeTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Guide";
  document.getElementById("gdeId").value     = g.id;
  document.getElementById("gdeName").value   = g.name             || "";
  document.getElementById("gdeIdNum").value  = g.id_number        || "";
  document.getElementById("gdePhone").value  = g.phone            || "";
  document.getElementById("gdeEmail").value  = g.email            || "";
  document.getElementById("gdeSpec").value   = g.specialization   || "";
  document.getElementById("gdeLangs").value  = g.languages        || "";
  document.getElementById("gdeExp").value    = g.experience_years || 0;
  document.getElementById("gdeRate").value   = g.daily_rate       || 0;
  document.getElementById("gdeStatus").value = g.status           || "active";
  new bootstrap.Modal(document.getElementById("guideModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
