<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'manifest.php',   'icon' => 'fas fa-clipboard-list',  'label' => 'Manifests'],
    ['url' => 'delivery.php',   'icon' => 'fas fa-truck',           'label' => 'Deliveries'],
    ['url' => 'routes.php',     'icon' => 'fas fa-route',           'label' => 'Routes'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';
    $entity = $_POST['entity'] ?? '';

    // ---- BRAND SETTINGS ----
    if ($entity === 'settings' && $action === 'save') {
        $keys = ['site_title','company_tagline','primary_color','contact_email','contact_phone','contact_address',
                 'banner_headline','banner_subheadline','stat_packages','stat_customers','stat_success_rate',
                 'feature1_icon','feature1_title','feature1_desc',
                 'feature2_icon','feature2_title','feature2_desc',
                 'feature3_icon','feature3_title','feature3_desc',
                 'about_story','about_mission','team_heading'];
        foreach ($keys as $k) {
            $val = sanitize($_POST[$k] ?? '');
            $pdo->prepare("INSERT INTO courier_settings (org_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$orgId,$k,$val,$val]);
        }
        // Logo upload
        if (!empty($_FILES['logo']['name'])) {
            $uploadDir = __DIR__ . '/../../uploads/courier/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','webp','svg'])) {
                $fname = 'logo_' . $orgId . '.' . $ext;
                if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $fname)) {
                    $pdo->prepare("INSERT INTO courier_settings (org_id,setting_key,setting_value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=?")->execute([$orgId,'logo',$fname,$fname]);
                }
            }
        }
        setFlash('success', 'Brand settings saved successfully.');
        redirect('setup.php?tab=settings');
    }

    // ---- BRANCHES ----
    if ($entity === 'branch') {
        if ($action === 'save') {
            $id      = (int)($_POST['id'] ?? 0);
            $name    = sanitize($_POST['name'] ?? '');
            $address = sanitize($_POST['address'] ?? '');
            $city    = sanitize($_POST['city'] ?? '');
            $phone   = sanitize($_POST['phone'] ?? '');
            $email   = sanitize($_POST['email'] ?? '');
            $manager = sanitize($_POST['manager'] ?? '');
            $status  = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_branches SET name=?,address=?,city=?,phone=?,email=?,manager=?,status=? WHERE id=? AND org_id=?")->execute([$name,$address,$city,$phone,$email,$manager,$status,$id,$orgId]);
                setFlash('success', 'Branch updated.');
            } else {
                $pdo->prepare("INSERT INTO courier_branches (org_id,name,address,city,phone,email,manager,status) VALUES (?,?,?,?,?,?,?,?)")->execute([$orgId,$name,$address,$city,$phone,$email,$manager,$status]);
                setFlash('success', "Branch '$name' added.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'courier', "Branch: $name");
        }
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM courier_branches WHERE id=? AND org_id=?")->execute([$id,$orgId]);
            setFlash('success', 'Branch removed.');
        }
        redirect('setup.php?tab=branches');
    }

    // ---- SERVICE TYPES ----
    if ($entity === 'service_type') {
        if ($action === 'save') {
            $id       = (int)($_POST['id'] ?? 0);
            $name     = sanitize($_POST['name'] ?? '');
            $desc     = sanitize($_POST['description'] ?? '');
            $price    = (float)($_POST['base_price'] ?? 0);
            $days     = (int)($_POST['delivery_days'] ?? 1);
            $status   = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_service_types SET name=?,description=?,base_price=?,delivery_days=?,status=? WHERE id=? AND org_id=?")->execute([$name,$desc,$price,$days,$status,$id,$orgId]);
                setFlash('success', 'Service type updated.');
            } else {
                $pdo->prepare("INSERT INTO courier_service_types (org_id,name,description,base_price,delivery_days,status) VALUES (?,?,?,?,?,?)")->execute([$orgId,$name,$desc,$price,$days,$status]);
                setFlash('success', "Service '$name' added.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'courier', "Service Type: $name");
        }
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM courier_service_types WHERE id=? AND org_id=?")->execute([$id,$orgId]);
            setFlash('success', 'Service type removed.');
        }
        redirect('setup.php?tab=services');
    }

    // ---- CATEGORIES ----
    if ($entity === 'category') {
        if ($action === 'save') {
            $id     = (int)($_POST['id'] ?? 0);
            $name   = sanitize($_POST['name'] ?? '');
            $desc   = sanitize($_POST['description'] ?? '');
            $status = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_categories SET name=?,description=?,status=? WHERE id=? AND org_id=?")->execute([$name,$desc,$status,$id,$orgId]);
                setFlash('success', 'Category updated.');
            } else {
                $pdo->prepare("INSERT INTO courier_categories (org_id,name,description,status) VALUES (?,?,?,?)")->execute([$orgId,$name,$desc,$status]);
                setFlash('success', "Category '$name' added.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'courier', "Category: $name");
        }
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM courier_categories WHERE id=? AND org_id=?")->execute([$id,$orgId]);
            setFlash('success', 'Category removed.');
        }
        redirect('setup.php?tab=categories');
    }

    // ---- TRACKING STAGES ----
    if ($entity === 'tracking_stage') {
        if ($action === 'save') {
            $id        = (int)($_POST['id'] ?? 0);
            $stageName = sanitize($_POST['stage_name'] ?? '');
            $stageCode = preg_replace('/[^a-z0-9_]/', '_', strtolower(sanitize($_POST['stage_code'] ?? '')));
            $color     = sanitize($_POST['color'] ?? '#007bff');
            $icon      = sanitize($_POST['icon'] ?? 'fas fa-circle');
            $sortOrder = (int)($_POST['sort_order'] ?? 0);
            $isFinal   = isset($_POST['is_final']) ? 1 : 0;
            $status    = $_POST['status'] === 'inactive' ? 'inactive' : 'active';
            if ($id > 0) {
                $pdo->prepare("UPDATE courier_tracking_stages SET stage_name=?,stage_code=?,color=?,icon=?,sort_order=?,is_final=?,status=? WHERE id=? AND org_id=?")->execute([$stageName,$stageCode,$color,$icon,$sortOrder,$isFinal,$status,$id,$orgId]);
                setFlash('success', 'Tracking stage updated.');
            } else {
                $pdo->prepare("INSERT INTO courier_tracking_stages (org_id,stage_name,stage_code,color,icon,sort_order,is_final,status) VALUES (?,?,?,?,?,?,?,?)")->execute([$orgId,$stageName,$stageCode,$color,$icon,$sortOrder,$isFinal,$status]);
                setFlash('success', "Stage '$stageName' added.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'courier', "Tracking Stage: $stageName");
        }
        if ($action === 'delete') {
            $id = (int)$_POST['id'];
            $pdo->prepare("DELETE FROM courier_tracking_stages WHERE id=? AND org_id=?")->execute([$id,$orgId]);
            setFlash('success', 'Tracking stage removed.');
        }
        redirect('setup.php?tab=stages');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$activeTab = $_GET['tab'] ?? 'settings';

// Load all data
$settings    = [];
$branches    = [];
$serviceTypes= [];
$categories  = [];
$stages      = [];

try {
    $st = $pdo->prepare("SELECT setting_key, setting_value FROM courier_settings WHERE org_id=?");
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $row) $settings[$row['setting_key']] = $row['setting_value'];
} catch (Exception $e) {}

try {
    $st = $pdo->prepare("SELECT * FROM courier_branches WHERE org_id=? ORDER BY name");
    $st->execute([$orgId]); $branches = $st->fetchAll();
} catch (Exception $e) {}

try {
    $st = $pdo->prepare("SELECT * FROM courier_service_types WHERE org_id=? ORDER BY name");
    $st->execute([$orgId]); $serviceTypes = $st->fetchAll();
} catch (Exception $e) {}

try {
    $st = $pdo->prepare("SELECT * FROM courier_categories WHERE org_id=? ORDER BY name");
    $st->execute([$orgId]); $categories = $st->fetchAll();
} catch (Exception $e) {}

try {
    $st = $pdo->prepare("SELECT * FROM courier_tracking_stages WHERE org_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([$orgId]); $stages = $st->fetchAll();
} catch (Exception $e) {}

// AJAX fetch helpers
if (isset($_GET['fetch_branch'])) {
    $id = (int)$_GET['fetch_branch'];
    $st = $pdo->prepare("SELECT * FROM courier_branches WHERE id=? AND org_id=?"); $st->execute([$id,$orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC); if ($r) { header('Content-Type: application/json'); echo json_encode($r); exit; }
}
if (isset($_GET['fetch_service'])) {
    $id = (int)$_GET['fetch_service'];
    $st = $pdo->prepare("SELECT * FROM courier_service_types WHERE id=? AND org_id=?"); $st->execute([$id,$orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC); if ($r) { header('Content-Type: application/json'); echo json_encode($r); exit; }
}
if (isset($_GET['fetch_category'])) {
    $id = (int)$_GET['fetch_category'];
    $st = $pdo->prepare("SELECT * FROM courier_categories WHERE id=? AND org_id=?"); $st->execute([$id,$orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC); if ($r) { header('Content-Type: application/json'); echo json_encode($r); exit; }
}
if (isset($_GET['fetch_stage'])) {
    $id = (int)$_GET['fetch_stage'];
    $st = $pdo->prepare("SELECT * FROM courier_tracking_stages WHERE id=? AND org_id=?"); $st->execute([$id,$orgId]);
    $r = $st->fetch(PDO::FETCH_ASSOC); if ($r) { header('Content-Type: application/json'); echo json_encode($r); exit; }
}

$cfg = fn(string $k, string $def = '') => $settings[$k] ?? $def;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cog me-2" style="color:<?= $moduleColor ?>"></i>System Setup & Configuration</h4>
    <p class="text-muted mb-0">Configure brand identity, branches, service types, categories, and tracking workflow stages</p>
  </div>
</div>

<!-- Tab Navigation -->
<ul class="nav nav-tabs mb-4 border-bottom">
  <?php
  $tabs = [
    'settings'   => ['icon' => 'fas fa-palette',  'label' => 'Brand & Website'],
    'branches'   => ['icon' => 'fas fa-code-branch','label' => 'Branches'],
    'services'   => ['icon' => 'fas fa-layer-group','label' => 'Service Types'],
    'categories' => ['icon' => 'fas fa-tags',       'label' => 'Categories'],
    'stages'     => ['icon' => 'fas fa-route',      'label' => 'Tracking Stages'],
  ];
  foreach ($tabs as $tabKey => $tabData): ?>
  <li class="nav-item">
    <a href="setup.php?tab=<?= $tabKey ?>" class="nav-link <?= $activeTab === $tabKey ? 'active fw-bold' : '' ?>">
      <i class="<?= $tabData['icon'] ?> me-1"></i><?= $tabData['label'] ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- ==================== BRAND SETTINGS TAB ==================== -->
<?php if ($activeTab === 'settings'): ?>
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-palette me-2 text-primary"></i>Brand Identity & Website Configuration</h6></div>
  <div class="card-body">
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="entity" value="settings">
      <div class="row g-3">
        <div class="col-12"><h6 class="fw-semibold border-bottom pb-2" style="color:<?= $moduleColor ?>">Company Identity</h6></div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Company / Site Title</label>
          <input type="text" name="site_title" class="form-control" value="<?= e($cfg('site_title')) ?>" placeholder="e.g. SwiftCourier Express">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Company Tagline</label>
          <input type="text" name="company_tagline" class="form-control" value="<?= e($cfg('company_tagline')) ?>" placeholder="e.g. Delivering Trust, Every Mile">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Primary Color</label>
          <input type="color" name="primary_color" class="form-control form-control-color w-100" value="<?= e($cfg('primary_color','#1565c0')) ?>">
        </div>
        <div class="col-md-2">
          <label class="form-label fw-semibold">Logo Upload</label>
          <input type="file" name="logo" class="form-control" accept=".jpg,.jpeg,.png,.webp,.svg">
          <?php if ($cfg('logo')): ?>
          <small class="text-muted">Current: <img src="../../uploads/courier/<?= e($cfg('logo')) ?>" alt="Logo" style="height:20px;vertical-align:middle"> </small>
          <?php endif; ?>
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Contact Email</label>
          <input type="email" name="contact_email" class="form-control" value="<?= e($cfg('contact_email')) ?>" placeholder="info@courier.com">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Contact Phone</label>
          <input type="text" name="contact_phone" class="form-control" value="<?= e($cfg('contact_phone')) ?>" placeholder="+263...">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Physical Address</label>
          <input type="text" name="contact_address" class="form-control" value="<?= e($cfg('contact_address')) ?>" placeholder="123 Main St, Harare">
        </div>

        <div class="col-12 mt-2"><h6 class="fw-semibold border-bottom pb-2" style="color:<?= $moduleColor ?>">Homepage Banner</h6></div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Banner Headline</label>
          <input type="text" name="banner_headline" class="form-control" value="<?= e($cfg('banner_headline')) ?>" placeholder="e.g. Fast & Reliable Courier Services">
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Banner Sub-headline</label>
          <input type="text" name="banner_subheadline" class="form-control" value="<?= e($cfg('banner_subheadline')) ?>" placeholder="e.g. From pickup to delivery — we handle it all.">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Stat: Packages Delivered</label>
          <input type="text" name="stat_packages" class="form-control" value="<?= e($cfg('stat_packages','50,000+')) ?>" placeholder="50,000+">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Stat: Satisfied Customers</label>
          <input type="text" name="stat_customers" class="form-control" value="<?= e($cfg('stat_customers','12,000+')) ?>" placeholder="12,000+">
        </div>
        <div class="col-md-4">
          <label class="form-label fw-semibold">Stat: Success Rate</label>
          <input type="text" name="stat_success_rate" class="form-control" value="<?= e($cfg('stat_success_rate','99.2%')) ?>" placeholder="99.2%">
        </div>

        <div class="col-12 mt-2"><h6 class="fw-semibold border-bottom pb-2" style="color:<?= $moduleColor ?>">Feature Showcase (3 Features)</h6></div>
        <?php foreach ([1,2,3] as $fi): ?>
        <div class="col-md-4">
          <div class="border rounded p-2">
            <label class="form-label fw-semibold small">Feature <?= $fi ?> Icon (Font Awesome)</label>
            <input type="text" name="feature<?= $fi ?>_icon" class="form-control form-control-sm mb-1" value="<?= e($cfg("feature{$fi}_icon", 'fas fa-truck')) ?>" placeholder="fas fa-truck">
            <label class="form-label fw-semibold small">Title</label>
            <input type="text" name="feature<?= $fi ?>_title" class="form-control form-control-sm mb-1" value="<?= e($cfg("feature{$fi}_title")) ?>" placeholder="Feature title">
            <label class="form-label fw-semibold small">Description</label>
            <textarea name="feature<?= $fi ?>_desc" class="form-control form-control-sm" rows="2" placeholder="Short feature description..."><?= e($cfg("feature{$fi}_desc")) ?></textarea>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="col-12 mt-2"><h6 class="fw-semibold border-bottom pb-2" style="color:<?= $moduleColor ?>">About Page Content</h6></div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Company Story</label>
          <textarea name="about_story" class="form-control" rows="4" placeholder="Tell your company's history and journey..."><?= e($cfg('about_story')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Mission Statement</label>
          <textarea name="about_mission" class="form-control" rows="4" placeholder="Our mission is to..."><?= e($cfg('about_mission')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label fw-semibold">Team Section Heading</label>
          <input type="text" name="team_heading" class="form-control" value="<?= e($cfg('team_heading','Meet Our Delivery Team')) ?>" placeholder="Meet Our Delivery Team">
        </div>
        <div class="col-12">
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Brand Settings</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ==================== BRANCHES TAB ==================== -->
<?php elseif ($activeTab === 'branches'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="fw-bold mb-0"><i class="fas fa-code-branch me-2" style="color:<?= $moduleColor ?>"></i>Branch Offices (<?= count($branches) ?>)</h6>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#branchModal" onclick="resetBranch()"><i class="fas fa-plus me-1"></i>Add Branch</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Branch Name</th><th>City</th><th>Phone</th><th>Email</th><th>Manager</th><th>Status</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($branches)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No branches configured.</td></tr>
          <?php else: foreach ($branches as $b): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($b['name']) ?><?php if ($b['address']): ?><br><small class="text-muted fw-normal"><?= e($b['address']) ?></small><?php endif; ?></td>
            <td><?= e($b['city'] ?: '—') ?></td>
            <td><?= e($b['phone'] ?: '—') ?></td>
            <td><?= e($b['email'] ?: '—') ?></td>
            <td><?= e($b['manager'] ?: '—') ?></td>
            <td><span class="badge bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= strtoupper($b['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="editBranch(<?= $b['id'] ?>)"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delItem('branch',<?= $b['id'] ?>)"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ==================== SERVICE TYPES TAB ==================== -->
<?php elseif ($activeTab === 'services'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="fw-bold mb-0"><i class="fas fa-layer-group me-2" style="color:<?= $moduleColor ?>"></i>Service Types (<?= count($serviceTypes) ?>)</h6>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#serviceModal" onclick="resetService()"><i class="fas fa-plus me-1"></i>Add Service</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Service Name</th><th>Description</th><th>Base Price</th><th>Delivery Days</th><th>Status</th><th class="text-center">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($serviceTypes)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No service types configured.</td></tr>
        <?php else: foreach ($serviceTypes as $s): ?>
        <tr>
          <td class="fw-bold text-dark"><?= e($s['name']) ?></td>
          <td class="small text-muted"><?= e($s['description'] ?: '—') ?></td>
          <td class="fw-bold"><?= formatCurrency((float)$s['base_price']) ?></td>
          <td><?= $s['delivery_days'] ?> day<?= $s['delivery_days'] > 1 ? 's' : '' ?></td>
          <td><span class="badge bg-<?= $s['status'] === 'active' ? 'success' : 'secondary' ?>"><?= strtoupper($s['status']) ?></span></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-primary" onclick="editService(<?= $s['id'] ?>)"><i class="fas fa-edit"></i></button>
              <button class="btn btn-outline-danger" onclick="delItem('service',<?= $s['id'] ?>)"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ==================== CATEGORIES TAB ==================== -->
<?php elseif ($activeTab === 'categories'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="fw-bold mb-0"><i class="fas fa-tags me-2" style="color:<?= $moduleColor ?>"></i>Package Categories (<?= count($categories) ?>)</h6>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="resetCategory()"><i class="fas fa-plus me-1"></i>Add Category</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Category Name</th><th>Description</th><th>Status</th><th class="text-center">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($categories)): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">No categories configured.</td></tr>
        <?php else: foreach ($categories as $cat): ?>
        <tr>
          <td class="fw-bold text-dark"><?= e($cat['name']) ?></td>
          <td class="small text-muted"><?= e($cat['description'] ?: '—') ?></td>
          <td><span class="badge bg-<?= $cat['status'] === 'active' ? 'success' : 'secondary' ?>"><?= strtoupper($cat['status']) ?></span></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-primary" onclick="editCategory(<?= $cat['id'] ?>)"><i class="fas fa-edit"></i></button>
              <button class="btn btn-outline-danger" onclick="delItem('category',<?= $cat['id'] ?>)"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ==================== TRACKING STAGES TAB ==================== -->
<?php elseif ($activeTab === 'stages'): ?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h6 class="fw-bold mb-0"><i class="fas fa-route me-2" style="color:<?= $moduleColor ?>"></i>Tracking Workflow Stages (<?= count($stages) ?>)</h6>
  <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#stageModal" onclick="resetStage()"><i class="fas fa-plus me-1"></i>Add Stage</button>
</div>
<div class="card">
  <div class="card-body p-0">
    <table class="table table-hover mb-0">
      <thead class="table-light">
        <tr><th>Order</th><th>Stage Name</th><th>Stage Code</th><th>Icon</th><th>Color</th><th>Final Stage</th><th>Status</th><th class="text-center">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($stages)): ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No tracking stages configured. <a href="setup.php?tab=stages">Add default stages</a> to enable tracking.</td></tr>
        <?php else: foreach ($stages as $stg): ?>
        <tr>
          <td class="text-center"><span class="badge bg-secondary"><?= $stg['sort_order'] ?></span></td>
          <td><i class="<?= e($stg['icon']) ?> me-2" style="color:<?= e($stg['color']) ?>"></i><strong><?= e($stg['stage_name']) ?></strong></td>
          <td><code><?= e($stg['stage_code']) ?></code></td>
          <td class="small text-muted"><?= e($stg['icon']) ?></td>
          <td><span class="badge text-white" style="background:<?= e($stg['color']) ?>"><?= e($stg['color']) ?></span></td>
          <td class="text-center"><?= $stg['is_final'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-light text-muted">No</span>' ?></td>
          <td><span class="badge bg-<?= $stg['status'] === 'active' ? 'success' : 'secondary' ?>"><?= strtoupper($stg['status']) ?></span></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-primary" onclick="editStage(<?= $stg['id'] ?>)"><i class="fas fa-edit"></i></button>
              <button class="btn btn-outline-danger" onclick="delItem('stage',<?= $stg['id'] ?>)"><i class="fas fa-trash"></i></button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ===== MODALS ===== -->

<!-- Branch Modal -->
<div class="modal fade" id="branchModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="entity" value="branch"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="branchId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>"><h5 class="modal-title" id="branchModalTitle"><i class="fas fa-code-branch me-2"></i>Add Branch</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label><input type="text" name="name" id="branchName" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">City</label><input type="text" name="city" id="branchCity" class="form-control"></div>
      <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" id="branchAddress" class="form-control" rows="2"></textarea></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="branchPhone" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="branchEmail" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Manager</label><input type="text" name="manager" id="branchManager" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label><select name="status" id="branchStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Branch</button></div>
  </form>
</div></div></div>

<!-- Service Type Modal -->
<div class="modal fade" id="serviceModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="entity" value="service_type"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="serviceId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>"><h5 class="modal-title" id="serviceModalTitle"><i class="fas fa-layer-group me-2"></i>Add Service Type</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label><input type="text" name="name" id="serviceName" class="form-control" required placeholder="e.g. Same-Day Express"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Base Price</label><input type="number" name="base_price" id="servicePrice" class="form-control" step="0.01" min="0" value="0"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Est. Delivery Days</label><input type="number" name="delivery_days" id="serviceDays" class="form-control" min="1" value="1"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="serviceStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="serviceDesc" class="form-control" rows="2" placeholder="Service description..."></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Service</button></div>
  </form>
</div></div></div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="entity" value="category"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="categoryId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>"><h5 class="modal-title"><i class="fas fa-tags me-2"></i>Add Category</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label><input type="text" name="name" id="categoryName" class="form-control" required placeholder="e.g. Electronics, Documents, Perishables"></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Description</label><input type="text" name="description" id="categoryDesc" class="form-control"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="categoryStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Category</button></div>
  </form>
</div></div></div>

<!-- Tracking Stage Modal -->
<div class="modal fade" id="stageModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="entity" value="tracking_stage"><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="stageId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>"><h5 class="modal-title" id="stageModalTitle"><i class="fas fa-route me-2"></i>Add Tracking Stage</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">Stage Name <span class="text-danger">*</span></label><input type="text" name="stage_name" id="stageName" class="form-control" required placeholder="e.g. In Transit"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Stage Code <span class="text-danger">*</span></label><input type="text" name="stage_code" id="stageCode2" class="form-control" required placeholder="e.g. in_transit"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Color</label><input type="color" name="color" id="stageColor" class="form-control form-control-color w-100" value="#007bff"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Sort Order</label><input type="number" name="sort_order" id="stageOrder" class="form-control" min="0" value="0"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="stageStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Font Awesome Icon</label><input type="text" name="icon" id="stageIcon" class="form-control" value="fas fa-circle" placeholder="e.g. fas fa-truck"></div>
      <div class="col-md-4 d-flex align-items-end pb-1">
        <div class="form-check ms-2">
          <input class="form-check-input" type="checkbox" name="is_final" id="stageIsFinal" value="1">
          <label class="form-check-label fw-semibold" for="stageIsFinal">Final Stage</label>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Stage</button></div>
  </form>
</div></div></div>

<!-- Delete form -->
<form method="POST" id="delItemForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="entity" id="delEntity">
  <input type="hidden" name="id" id="delItemId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function resetBranch() {
  document.getElementById('branchModalTitle').textContent = 'Add Branch';
  document.getElementById('branchId').value = '0';
  ['branchName','branchCity','branchAddress','branchPhone','branchEmail','branchManager'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('branchStatus').value = 'active';
}
function editBranch(id) {
  fetch('setup.php?fetch_branch=' + id).then(r => r.json()).then(d => {
    document.getElementById('branchModalTitle').textContent = 'Edit Branch';
    document.getElementById('branchId').value = d.id;
    document.getElementById('branchName').value = d.name || '';
    document.getElementById('branchCity').value = d.city || '';
    document.getElementById('branchAddress').value = d.address || '';
    document.getElementById('branchPhone').value = d.phone || '';
    document.getElementById('branchEmail').value = d.email || '';
    document.getElementById('branchManager').value = d.manager || '';
    document.getElementById('branchStatus').value = d.status || 'active';
    new bootstrap.Modal(document.getElementById('branchModal')).show();
  });
}
function resetService() {
  document.getElementById('serviceId').value = '0';
  ['serviceName','serviceDesc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('servicePrice').value = '0';
  document.getElementById('serviceDays').value = '1';
  document.getElementById('serviceStatus').value = 'active';
}
function editService(id) {
  fetch('setup.php?fetch_service=' + id).then(r => r.json()).then(d => {
    document.getElementById('serviceId').value = d.id;
    document.getElementById('serviceName').value = d.name || '';
    document.getElementById('serviceDesc').value = d.description || '';
    document.getElementById('servicePrice').value = d.base_price || '0';
    document.getElementById('serviceDays').value = d.delivery_days || '1';
    document.getElementById('serviceStatus').value = d.status || 'active';
    new bootstrap.Modal(document.getElementById('serviceModal')).show();
  });
}
function resetCategory() {
  document.getElementById('categoryId').value = '0';
  ['categoryName','categoryDesc'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('categoryStatus').value = 'active';
}
function editCategory(id) {
  fetch('setup.php?fetch_category=' + id).then(r => r.json()).then(d => {
    document.getElementById('categoryId').value = d.id;
    document.getElementById('categoryName').value = d.name || '';
    document.getElementById('categoryDesc').value = d.description || '';
    document.getElementById('categoryStatus').value = d.status || 'active';
    new bootstrap.Modal(document.getElementById('categoryModal')).show();
  });
}
function resetStage() {
  document.getElementById('stageId').value = '0';
  document.getElementById('stageName').value = '';
  document.getElementById('stageCode2').value = '';
  document.getElementById('stageColor').value = '#007bff';
  document.getElementById('stageIcon').value = 'fas fa-circle';
  document.getElementById('stageOrder').value = '0';
  document.getElementById('stageStatus').value = 'active';
  document.getElementById('stageIsFinal').checked = false;
}
function editStage(id) {
  fetch('setup.php?fetch_stage=' + id).then(r => r.json()).then(d => {
    document.getElementById('stageId').value = d.id;
    document.getElementById('stageName').value = d.stage_name || '';
    document.getElementById('stageCode2').value = d.stage_code || '';
    document.getElementById('stageColor').value = d.color || '#007bff';
    document.getElementById('stageIcon').value = d.icon || 'fas fa-circle';
    document.getElementById('stageOrder').value = d.sort_order || '0';
    document.getElementById('stageStatus').value = d.status || 'active';
    document.getElementById('stageIsFinal').checked = d.is_final == 1;
    new bootstrap.Modal(document.getElementById('stageModal')).show();
  });
}
function delItem(entity, id) {
  const labels = {branch:'branch',service:'service type',category:'category',stage:'tracking stage'};
  Swal.fire({
    title: 'Delete ' + (labels[entity] || entity) + '?',
    text: 'This item will be permanently removed.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delEntity').value = entity + (entity==='service'?'_type':entity==='stage'?'_stage':'');
      document.getElementById('delItemId').value = id;
      document.getElementById('delItemForm').submit();
    }
  });
}
// Auto-fill stage code from stage name
document.getElementById('stageName').addEventListener('input', function() {
  if (document.getElementById('stageId').value === '0') {
    document.getElementById('stageCode2').value = this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_|_$/g,'');
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
