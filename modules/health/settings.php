<?php
$moduleSlug  = 'health';
$moduleName  = 'Health & Clinic';
$moduleIcon  = 'fas fa-heartbeat';
$moduleColor = '#e74c3c';
$moduleNav   = [
    ['url'=>'index.php',         'icon'=>'fas fa-tachometer-alt',      'label'=>'Dashboard'],
    ['url'=>'patients.php',      'icon'=>'fas fa-procedures',          'label'=>'Patients'],
    ['url'=>'appointments.php',  'icon'=>'fas fa-calendar-check',      'label'=>'Appointments'],
    ['url'=>'doctors.php',       'icon'=>'fas fa-user-md',             'label'=>'Doctors'],
    ['url'=>'staff.php',         'icon'=>'fas fa-id-badge',            'label'=>'Clinical Staff'],
    ['url'=>'records.php',       'icon'=>'fas fa-file-medical',        'label'=>'Medical Records'],
    ['url'=>'vitals.php',        'icon'=>'fas fa-heartbeat',           'label'=>'Vital Signs'],
    ['url'=>'lab.php',           'icon'=>'fas fa-flask',               'label'=>'Laboratory'],
    ['url'=>'pharmacy.php',      'icon'=>'fas fa-pills',               'label'=>'Pharmacy'],
    ['url'=>'nursing.php',       'icon'=>'fas fa-user-nurse',          'label'=>'Nursing'],
    ['url'=>'wards.php',         'icon'=>'fas fa-bed',                 'label'=>'Wards & Beds'],
    ['url'=>'admissions.php',    'icon'=>'fas fa-hospital-user',       'label'=>'Admissions (IPD)'],
    ['url'=>'surgery.php',       'icon'=>'fas fa-syringe',             'label'=>'Surgery / Theatre'],
    ['url'=>'emergency.php',     'icon'=>'fas fa-ambulance',           'label'=>'Emergency / Triage'],
    ['url'=>'billing.php',       'icon'=>'fas fa-file-invoice-dollar', 'label'=>'Billing'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Ensure tables exist ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_departments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        description TEXT,
        head_doctor_id INT DEFAULT NULL,
        color VARCHAR(10) NOT NULL DEFAULT '#e74c3c',
        icon VARCHAR(60) NOT NULL DEFAULT 'fas fa-stethoscope',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS health_consultation_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        duration_minutes INT NOT NULL DEFAULT 30,
        fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        description TEXT,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS health_working_hours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        day_of_week TINYINT NOT NULL COMMENT '0=Monday … 6=Sunday',
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        open_time TIME NOT NULL DEFAULT '08:00:00',
        close_time TIME NOT NULL DEFAULT '17:00:00',
        UNIQUE KEY uq_day (org_id, day_of_week),
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS health_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        org_id INT NOT NULL,
        setting_key VARCHAR(100) NOT NULL,
        setting_value TEXT,
        UNIQUE KEY uq_setting (org_id, setting_key),
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Optional org columns
    $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS website VARCHAR(255) DEFAULT NULL AFTER country");
    $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS brand_tagline VARCHAR(255) DEFAULT NULL AFTER website");
    $pdo->exec("ALTER TABLE organizations ADD COLUMN IF NOT EXISTS reg_number VARCHAR(100) DEFAULT NULL AFTER brand_tagline");
} catch (Throwable $e) {}

// ── Helper: upsert a health_setting ──────────────────────────────
function hSet(PDO $pdo, int $orgId, string $key, $value): void {
    $pdo->prepare("INSERT INTO health_settings (org_id,setting_key,setting_value) VALUES (?,?,?)
                   ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")
        ->execute([$orgId, $key, $value]);
}
function hGet(array $settings, string $key, $default = ''): string {
    return $settings[$key] ?? $default;
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';
    $tab    = $_POST['_tab'] ?? 'profile';

    // ── 1. Clinic Profile ─────────────────────────────────────────
    if ($action === 'save_profile') {
        $name    = sanitize($_POST['name']       ?? '');
        $email   = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $phone   = sanitize($_POST['phone']      ?? '');
        $address = sanitize($_POST['address']    ?? '');
        $city    = sanitize($_POST['city']       ?? '');
        $country = sanitize($_POST['country']    ?? '');
        $website = sanitize($_POST['website']    ?? '');
        $tagline = sanitize($_POST['brand_tagline'] ?? '');
        $regNo   = sanitize($_POST['reg_number'] ?? '');

        if (!$name) { setFlash('error', 'Clinic name is required.'); redirect('settings.php?tab=profile'); }

        // Logo upload
        $logoSet = ''; $logoParams = [];
        if (!empty($_FILES['logo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp','svg'])) {
                setFlash('error', 'Logo must be JPG, PNG, WebP or SVG.'); redirect('settings.php?tab=profile');
            }
            if ($_FILES['logo']['size'] > 2 * 1024 * 1024) {
                setFlash('error', 'Logo must be under 2 MB.'); redirect('settings.php?tab=profile');
            }
            $uploadDir = __DIR__ . '/../../uploads/logos/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $filename = 'logo_'.$orgId.'_'.time().'.'.$ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir.$filename)) {
                $logoSet    = ', logo=?';
                $logoParams = [$filename];
            }
        }

        $params = array_merge([$name,$email,$phone,$address,$city,$country,$website,$tagline,$regNo], $logoParams, [$orgId]);
        try {
            $pdo->prepare("UPDATE organizations SET name=?,email=?,phone=?,address=?,city=?,country=?,website=?,brand_tagline=?,reg_number=?{$logoSet} WHERE id=?")
                ->execute($params);
            setFlash('success', 'Clinic profile saved.');
        } catch (Throwable $e) { setFlash('error', 'Could not save profile.'); }
        redirect('settings.php?tab=profile');
    }

    if ($action === 'remove_logo') {
        try { $pdo->prepare("UPDATE organizations SET logo=NULL WHERE id=?")->execute([$orgId]); setFlash('success','Logo removed.'); } catch (Throwable $e) {}
        redirect('settings.php?tab=profile');
    }

    // ── 2. Department CRUD ────────────────────────────────────────
    if ($action === 'save_department') {
        $id    = (int)($_POST['dept_id'] ?? 0);
        $name  = sanitize($_POST['dept_name'] ?? '');
        $desc  = sanitize($_POST['dept_desc'] ?? '');
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['dept_color'] ?? '') ? $_POST['dept_color'] : '#e74c3c';
        $icon  = preg_match('/^[a-z\s\-]+$/', $_POST['dept_icon'] ?? '') ? sanitize($_POST['dept_icon']) : 'fas fa-stethoscope';
        $order = (int)($_POST['dept_order'] ?? 0);
        $active = (int)!empty($_POST['dept_active']);
        $headDoc = (int)($_POST['dept_head_doctor'] ?? 0) ?: null;

        if (!$name) { setFlash('error', 'Department name required.'); redirect('settings.php?tab=departments'); }
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE health_departments SET name=?,description=?,color=?,icon=?,display_order=?,is_active=?,head_doctor_id=? WHERE id=? AND org_id=?")
                    ->execute([$name,$desc,$color,$icon,$order,$active,$headDoc,$id,$orgId]);
                setFlash('success', "Department '$name' updated.");
            } else {
                $pdo->prepare("INSERT INTO health_departments (org_id,name,description,color,icon,display_order,is_active,head_doctor_id) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$orgId,$name,$desc,$color,$icon,$order,$active,$headDoc]);
                setFlash('success', "Department '$name' added.");
            }
        } catch (Throwable $e) { setFlash('error', 'Could not save department.'); }
        redirect('settings.php?tab=departments');
    }

    if ($action === 'delete_department') {
        $id = (int)($_POST['dept_id'] ?? 0);
        try { $pdo->prepare("DELETE FROM health_departments WHERE id=? AND org_id=?")->execute([$id,$orgId]); setFlash('success','Department removed.'); } catch (Throwable $e) {}
        redirect('settings.php?tab=departments');
    }

    if ($action === 'seed_departments') {
        $defaults = [
            ['Outpatient (OPD)',   'fas fa-user-injured',    '#2980b9'],
            ['Inpatient (IPD)',    'fas fa-bed',             '#8e44ad'],
            ['Emergency',         'fas fa-ambulance',       '#e74c3c'],
            ['Maternity',         'fas fa-baby',            '#e91e8c'],
            ['Laboratory',        'fas fa-flask',           '#16a085'],
            ['Pharmacy',          'fas fa-pills',           '#d35400'],
            ['Radiology',         'fas fa-x-ray',           '#2c3e50'],
            ['Surgery / Theatre', 'fas fa-syringe',         '#c0392b'],
            ['Dental',            'fas fa-tooth',           '#27ae60'],
            ['Paediatrics',       'fas fa-child',           '#f39c12'],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO health_departments (org_id,name,icon,color,is_active,display_order) VALUES (?,?,?,?,1,?)");
        foreach ($defaults as $i => $d) {
            try { $stmt->execute([$orgId,$d[0],$d[1],$d[2],$i]); } catch (Throwable $e) {}
        }
        setFlash('success', 'Default departments loaded.');
        redirect('settings.php?tab=departments');
    }

    // ── 3. Consultation Types ──────────────────────────────────────
    if ($action === 'save_consult_type') {
        $id   = (int)($_POST['ct_id'] ?? 0);
        $name = sanitize($_POST['ct_name'] ?? '');
        $dur  = max(5, (int)($_POST['ct_duration'] ?? 30));
        $fee  = max(0, (float)($_POST['ct_fee'] ?? 0));
        $desc = sanitize($_POST['ct_desc'] ?? '');
        $ord  = (int)($_POST['ct_order'] ?? 0);
        $act  = (int)!empty($_POST['ct_active']);

        if (!$name) { setFlash('error', 'Consultation type name required.'); redirect('settings.php?tab=consult_types'); }
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE health_consultation_types SET name=?,duration_minutes=?,fee=?,description=?,display_order=?,is_active=? WHERE id=? AND org_id=?")
                    ->execute([$name,$dur,$fee,$desc,$ord,$act,$id,$orgId]);
                setFlash('success', "Consultation type '$name' updated.");
            } else {
                $pdo->prepare("INSERT INTO health_consultation_types (org_id,name,duration_minutes,fee,description,display_order,is_active) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$orgId,$name,$dur,$fee,$desc,$ord,$act]);
                setFlash('success', "Consultation type '$name' added.");
            }
        } catch (Throwable $e) { setFlash('error','Could not save consultation type.'); }
        redirect('settings.php?tab=consult_types');
    }

    if ($action === 'delete_consult_type') {
        $id = (int)($_POST['ct_id'] ?? 0);
        try { $pdo->prepare("DELETE FROM health_consultation_types WHERE id=? AND org_id=?")->execute([$id,$orgId]); setFlash('success','Type removed.'); } catch (Throwable $e) {}
        redirect('settings.php?tab=consult_types');
    }

    if ($action === 'seed_consult_types') {
        $defaults = [
            ['General Consultation',     30, 500],
            ['Specialist Consultation',  45, 1500],
            ['Follow-up Visit',          20, 300],
            ['Emergency Consultation',   15, 1000],
            ['Antenatal Care (ANC)',     30, 600],
            ['Telemedicine / Virtual',   20, 400],
            ['Dental Checkup',           40, 800],
            ['Paediatric Consultation',  30, 700],
        ];
        $stmt = $pdo->prepare("INSERT IGNORE INTO health_consultation_types (org_id,name,duration_minutes,fee,is_active,display_order) VALUES (?,?,?,?,1,?)");
        foreach ($defaults as $i => $d) {
            try { $stmt->execute([$orgId,$d[0],$d[1],$d[2],$i]); } catch (Throwable $e) {}
        }
        setFlash('success', 'Default consultation types loaded.');
        redirect('settings.php?tab=consult_types');
    }

    // ── 4. Working Hours ──────────────────────────────────────────
    if ($action === 'save_working_hours') {
        $stmt = $pdo->prepare("INSERT INTO health_working_hours (org_id,day_of_week,is_open,open_time,close_time)
                               VALUES (?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE is_open=VALUES(is_open),open_time=VALUES(open_time),close_time=VALUES(close_time)");
        for ($d = 0; $d <= 6; $d++) {
            $isOpen = !empty($_POST["day_{$d}_open"]) ? 1 : 0;
            $open   = $_POST["day_{$d}_from"] ?? '08:00';
            $close  = $_POST["day_{$d}_to"]   ?? '17:00';
            try { $stmt->execute([$orgId,$d,$isOpen,$open,$close]); } catch (Throwable $e) {}
        }
        setFlash('success', 'Working hours saved.');
        redirect('settings.php?tab=hours');
    }

    // ── 5. Notification settings ──────────────────────────────────
    // ── 7. Custom Domain & White Label ───────────────────────────
    if ($action === 'save_domain_settings') {
        $domain = strtolower(trim($_POST['custom_domain'] ?? ''));
        $domain = preg_replace('#^https?://#', '', $domain); // strip protocol
        $domain = rtrim($domain, '/');                        // strip trailing slash
        $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['portal_accent'] ?? '')
                  ? $_POST['portal_accent'] : '#e74c3c';
        $bg     = in_array($_POST['portal_bg'] ?? '', ['medical','gradient','white','dark'])
                  ? $_POST['portal_bg'] : 'medical';

        hSet($pdo, $orgId, 'custom_domain',       $domain);
        hSet($pdo, $orgId, 'portal_title',         sanitize($_POST['portal_title']   ?? ''));
        hSet($pdo, $orgId, 'portal_tagline',       sanitize($_POST['portal_tagline'] ?? ''));
        hSet($pdo, $orgId, 'portal_accent',        $accent);
        hSet($pdo, $orgId, 'portal_bg',            $bg);
        hSet($pdo, $orgId, 'portal_show_powered',  empty($_POST['portal_show_powered']) ? '0' : '1');

        setFlash('success', 'Custom domain settings saved.');
        redirect('settings.php?tab=domain');
    }

    if ($action === 'save_notifications') {
        $keys = [
            'notif_appt_reminder_sms', 'notif_appt_reminder_email',
            'notif_appt_reminder_hours', 'notif_lab_result_sms',
            'notif_lab_result_email', 'notif_bill_sms', 'notif_bill_email',
            'notif_discharge_sms', 'notif_discharge_email',
            'notif_email_footer', 'notif_sms_sender',
        ];
        foreach ($keys as $key) {
            $val = $_POST[$key] ?? '';
            // Checkbox fields — not posted when unchecked
            if (in_array($key, ['notif_appt_reminder_sms','notif_appt_reminder_email',
                'notif_lab_result_sms','notif_lab_result_email',
                'notif_bill_sms','notif_bill_email',
                'notif_discharge_sms','notif_discharge_email'])) {
                $val = isset($_POST[$key]) ? '1' : '0';
            }
            try { hSet($pdo, $orgId, $key, $val); } catch (Throwable $e) {}
        }
        setFlash('success', 'Notification preferences saved.');
        redirect('settings.php?tab=notifications');
    }

    redirect('settings.php');
}

// ── Load data ─────────────────────────────────────────────────────
$org  = [];
try { $s=$pdo->prepare("SELECT * FROM organizations WHERE id=? LIMIT 1"); $s->execute([$orgId]); $org=$s->fetch()?:[]; } catch (Throwable $e) {}

$departments = [];
try { $s=$pdo->prepare("SELECT d.*,CONCAT(doc.first_name,' ',doc.last_name) AS head_name FROM health_departments d LEFT JOIN health_doctors doc ON doc.id=d.head_doctor_id WHERE d.org_id=? ORDER BY d.display_order,d.name"); $s->execute([$orgId]); $departments=$s->fetchAll(); } catch (Throwable $e) {}

$consultTypes = [];
try { $s=$pdo->prepare("SELECT * FROM health_consultation_types WHERE org_id=? ORDER BY display_order,name"); $s->execute([$orgId]); $consultTypes=$s->fetchAll(); } catch (Throwable $e) {}

$workingHours = [];
try { $s=$pdo->prepare("SELECT * FROM health_working_hours WHERE org_id=? ORDER BY day_of_week"); $s->execute([$orgId]); foreach($s->fetchAll() as $r) $workingHours[$r['day_of_week']]=$r; } catch (Throwable $e) {}

$notifSettings = [];
try { $s=$pdo->prepare("SELECT setting_key,setting_value FROM health_settings WHERE org_id=?"); $s->execute([$orgId]); foreach($s->fetchAll() as $r) $notifSettings[$r['setting_key']]=$r['setting_value']; } catch (Throwable $e) {}

$doctors = [];
try { $s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM health_doctors WHERE org_id=? AND status='active' ORDER BY first_name"); $s->execute([$orgId]); $doctors=$s->fetchAll(); } catch (Throwable $e) {}

$orgSlug = $org['slug'] ?? '';
$activeTab = $_GET['tab'] ?? 'profile';

$dayNames = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

require_once __DIR__ . '/../../includes/header-module.php';
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-cog me-2" style="color:<?= $moduleColor ?>"></i>Health Module Settings</h4>
    <p class="text-muted mb-0">Configure your clinic profile, departments, working hours, and notifications</p>
  </div>
</div>

<!-- Nav tabs -->
<ul class="nav nav-tabs mb-4" id="settingsTabs">
  <?php
  $tabs = [
    'profile'       => ['fas fa-hospital',         'Clinic Profile'],
    'departments'   => ['fas fa-door-open',         'Departments'],
    'consult_types' => ['fas fa-stethoscope',       'Consultation Types'],
    'hours'         => ['fas fa-clock',             'Working Hours'],
    'notifications' => ['fas fa-bell',              'Notifications'],
    'portals'       => ['fas fa-external-link-alt', 'Portals & Links'],
    'domain'        => ['fas fa-globe',             'Custom Domain'],
  ];
  foreach ($tabs as $slug => [$icon, $label]):
  ?>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab===$slug?'active':'' ?>" href="?tab=<?= $slug ?>">
      <i class="<?= $icon ?> me-1"></i><?= $label ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<!-- ════════════════════════════════════════════════════
     TAB 1: Clinic Profile
     ════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'profile'): ?>
<div class="row g-4">
  <div class="col-lg-8">
    <form method="POST" enctype="multipart/form-data">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_profile">

      <div class="card mb-4">
        <div class="card-header"><h6 class="fw-bold mb-0"><i class="fas fa-hospital me-2 text-danger"></i>Clinic Identity</h6></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold small">Clinic / Hospital Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required value="<?= e($org['name'] ?? '') ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Contact Email</label>
              <input type="email" name="email" class="form-control" value="<?= e($org['email'] ?? '') ?>" placeholder="info@clinic.co.ke">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Phone Number</label>
              <input type="tel" name="phone" class="form-control" value="<?= e($org['phone'] ?? '') ?>" placeholder="+254 700 000 000">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small">Physical Address</label>
              <textarea name="address" class="form-control" rows="2" placeholder="Street, Building, Floor"><?= e($org['address'] ?? '') ?></textarea>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">City / Town</label>
              <input type="text" name="city" class="form-control" value="<?= e($org['city'] ?? '') ?>" placeholder="Nairobi">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Country</label>
              <input type="text" name="country" class="form-control" value="<?= e($org['country'] ?? 'Kenya') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold small">Website</label>
              <input type="url" name="website" class="form-control" value="<?= e($org['website'] ?? '') ?>" placeholder="https://clinic.co.ke">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Tagline / Motto</label>
              <input type="text" name="brand_tagline" class="form-control" value="<?= e($org['brand_tagline'] ?? '') ?>" placeholder="e.g. Your Health, Our Priority">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold small">Registration / License No.</label>
              <input type="text" name="reg_number" class="form-control" value="<?= e($org['reg_number'] ?? '') ?>" placeholder="Ministry of Health Reg. No.">
            </div>
          </div>
        </div>
      </div>

      <div class="card mb-4">
        <div class="card-header"><h6 class="fw-bold mb-0"><i class="fas fa-image me-2 text-danger"></i>Logo</h6></div>
        <div class="card-body">
          <?php if (!empty($org['logo'])): ?>
          <div class="d-flex align-items-center gap-3 mb-3">
            <img src="<?= APP_URL ?>/uploads/logos/<?= e($org['logo']) ?>" alt="Logo"
                 style="height:64px;max-width:160px;object-fit:contain;border:1px solid #dee2e6;border-radius:8px;padding:6px;background:#fff">
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove this logo?')">
              <?= csrfField() ?><input type="hidden" name="action" value="remove_logo">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash me-1"></i>Remove Logo</button>
            </form>
          </div>
          <?php else: ?>
          <p class="text-muted small mb-3"><i class="fas fa-image me-1"></i>No logo uploaded — initials will be used on documents and portals.</p>
          <?php endif; ?>
          <input type="file" name="logo" class="form-control form-control-sm" accept="image/*" onchange="previewLogo(this)">
          <div class="form-text">JPG, PNG, WebP or SVG. Max 2 MB. Appears on professional documents and portal login pages.</div>
          <div class="mt-2 d-none" id="logoPreviewWrap">
            <img id="logoPreview" style="height:52px;border-radius:6px;border:1px solid #dee2e6">
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-danger px-4"><i class="fas fa-save me-1"></i>Save Clinic Profile</button>
    </form>
  </div>

  <div class="col-lg-4">
    <!-- Quick stats -->
    <div class="card">
      <div class="card-header"><h6 class="fw-bold mb-0">Module Overview</h6></div>
      <div class="card-body p-0">
        <?php
        $quickStats = [
            ['health_patients','Patients','fas fa-procedures','#e74c3c'],
            ['health_doctors','Doctors','fas fa-user-md','#2980b9'],
            ['health_appointments','Appointments','fas fa-calendar-check','#27ae60'],
            ['health_records','Medical Records','fas fa-file-medical','#8e44ad'],
        ];
        foreach ($quickStats as [$table, $label, $icon, $color]):
            try { $cnt=(int)$pdo->query("SELECT COUNT(*) FROM $table WHERE org_id=$orgId")->fetchColumn(); } catch (Throwable $e) { $cnt=0; }
        ?>
        <div class="d-flex align-items-center justify-content-between px-3 py-2 border-bottom border-light">
          <div class="d-flex align-items-center gap-2">
            <i class="<?= $icon ?> small" style="color:<?= $color ?>;width:14px"></i>
            <span class="small text-muted"><?= $label ?></span>
          </div>
          <span class="fw-bold small"><?= number_format($cnt) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     TAB 2: Departments
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'departments'): ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="text-muted small"><?= count($departments) ?> department<?= count($departments)!==1?'s':'' ?> configured</div>
  <div class="d-flex gap-2">
    <?php if (empty($departments)): ?>
    <form method="POST" class="d-inline">
      <?= csrfField() ?><input type="hidden" name="action" value="seed_departments">
      <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-magic me-1"></i>Load Defaults</button>
    </form>
    <?php endif; ?>
    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="resetDeptForm()">
      <i class="fas fa-plus me-1"></i>Add Department
    </button>
  </div>
</div>

<?php if (empty($departments)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-door-open fa-3x mb-3 d-block opacity-25"></i>
    <p>No departments yet. Click "Load Defaults" to seed common departments or "Add Department" to create one.</p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($departments as $dept): ?>
  <div class="col-sm-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-start gap-3">
        <div style="width:44px;height:44px;border-radius:10px;background:<?= e($dept['color']) ?>22;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <i class="<?= e($dept['icon']) ?>" style="color:<?= e($dept['color']) ?>"></i>
        </div>
        <div class="flex-grow-1 min-width-0">
          <div class="fw-semibold"><?= e($dept['name']) ?></div>
          <?php if ($dept['description']): ?><div class="text-muted small text-truncate"><?= e($dept['description']) ?></div><?php endif; ?>
          <?php if ($dept['head_name']): ?><div class="text-muted small">Head: Dr. <?= e($dept['head_name']) ?></div><?php endif; ?>
          <span class="badge mt-1 <?= $dept['is_active'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>" style="font-size:.68rem">
            <?= $dept['is_active'] ? 'Active' : 'Inactive' ?>
          </span>
        </div>
      </div>
      <div class="card-footer bg-transparent border-0 pt-0 d-flex gap-2 px-3 pb-3">
        <button class="btn btn-xs btn-outline-primary flex-grow-1"
                onclick='editDept(<?= json_encode(['id'=>$dept['id'],'name'=>$dept['name'],'desc'=>$dept['description'],'color'=>$dept['color'],'icon'=>$dept['icon'],'order'=>$dept['display_order'],'active'=>$dept['is_active'],'head'=>$dept['head_doctor_id']]) ?>)'>
          <i class="fas fa-edit me-1"></i>Edit
        </button>
        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this department?')">
          <?= csrfField() ?><input type="hidden" name="action" value="delete_department"><input type="hidden" name="dept_id" value="<?= $dept['id'] ?>">
          <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Department Modal -->
<div class="modal fade" id="deptModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_department">
        <input type="hidden" name="dept_id" id="deptId" value="0">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="deptModalTitle"><i class="fas fa-door-open me-2 text-danger"></i>Add Department</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
              <input type="text" name="dept_name" id="deptName" class="form-control form-control-sm" required placeholder="e.g. Outpatient, Emergency, Laboratory">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Description</label>
              <textarea name="dept_desc" id="deptDesc" class="form-control form-control-sm" rows="2" placeholder="Brief description of this department"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Icon Class</label>
              <input type="text" name="dept_icon" id="deptIcon" class="form-control form-control-sm" placeholder="fas fa-stethoscope">
              <div class="form-text"><a href="https://fontawesome.com/icons" target="_blank" class="small">Browse Font Awesome icons</a></div>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Colour</label>
              <input type="color" name="dept_color" id="deptColor" class="form-control form-control-color form-control-sm w-100" value="#e74c3c">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold">Sort Order</label>
              <input type="number" name="dept_order" id="deptOrder" class="form-control form-control-sm" value="0" min="0">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Head Doctor</label>
              <select name="dept_head_doctor" id="deptHead" class="form-select form-select-sm">
                <option value="">— None —</option>
                <?php foreach ($doctors as $d): ?>
                <option value="<?= $d['id'] ?>">Dr. <?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="dept_active" id="deptActive" value="1" checked>
                <label class="form-check-label small" for="deptActive">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-save me-1"></i>Save Department</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     TAB 3: Consultation Types
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'consult_types'): ?>

<div class="d-flex align-items-center justify-content-between mb-3">
  <div class="text-muted small"><?= count($consultTypes) ?> consultation type<?= count($consultTypes)!==1?'s':'' ?></div>
  <div class="d-flex gap-2">
    <?php if (empty($consultTypes)): ?>
    <form method="POST" class="d-inline">
      <?= csrfField() ?><input type="hidden" name="action" value="seed_consult_types">
      <button class="btn btn-sm btn-outline-secondary"><i class="fas fa-magic me-1"></i>Load Defaults</button>
    </form>
    <?php endif; ?>
    <button class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#ctModal" onclick="resetCtForm()">
      <i class="fas fa-plus me-1"></i>Add Type
    </button>
  </div>
</div>

<?php if (empty($consultTypes)): ?>
<div class="card border-0 shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="fas fa-stethoscope fa-3x mb-3 d-block opacity-25"></i>
    <p>No consultation types yet. Click "Load Defaults" for common types.</p>
  </div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Consultation Type</th><th>Duration</th><th>Fee</th><th>Description</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($consultTypes as $ct): ?>
          <tr>
            <td class="fw-semibold"><?= e($ct['name']) ?></td>
            <td class="small"><?= $ct['duration_minutes'] ?> min</td>
            <td class="fw-semibold"><?= formatCurrency($ct['fee']) ?></td>
            <td class="small text-muted"><?= e(mb_substr($ct['description'] ?? '', 0, 60)) ?: '—' ?></td>
            <td>
              <span class="badge <?= $ct['is_active'] ? 'bg-success' : 'bg-secondary' ?>">
                <?= $ct['is_active'] ? 'Active' : 'Inactive' ?>
              </span>
            </td>
            <td>
              <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-primary"
                        onclick='editCt(<?= json_encode(['id'=>$ct['id'],'name'=>$ct['name'],'dur'=>$ct['duration_minutes'],'fee'=>$ct['fee'],'desc'=>$ct['description'],'order'=>$ct['display_order'],'active'=>$ct['is_active']]) ?>)'>
                  <i class="fas fa-edit"></i>
                </button>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this type?')">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_consult_type"><input type="hidden" name="ct_id" value="<?= $ct['id'] ?>">
                  <button class="btn btn-xs btn-outline-danger"><i class="fas fa-trash"></i></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Consultation Type Modal -->
<div class="modal fade" id="ctModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_consult_type">
        <input type="hidden" name="ct_id" id="ctId" value="0">
        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="ctModalTitle"><i class="fas fa-stethoscope me-2 text-danger"></i>Consultation Type</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
              <input type="text" name="ct_name" id="ctName" class="form-control form-control-sm" required placeholder="e.g. General Consultation">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Duration (minutes)</label>
              <input type="number" name="ct_duration" id="ctDuration" class="form-control form-control-sm" value="30" min="5">
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Consultation Fee (<?= defined('CURRENCY') ? CURRENCY : 'KES' ?>)</label>
              <input type="number" name="ct_fee" id="ctFee" class="form-control form-control-sm" value="0" min="0" step="0.01">
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold">Description</label>
              <textarea name="ct_desc" id="ctDesc" class="form-control form-control-sm" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small fw-semibold">Sort Order</label>
              <input type="number" name="ct_order" id="ctOrder" class="form-control form-control-sm" value="0">
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="ct_active" id="ctActive" value="1" checked>
                <label class="form-check-label small" for="ctActive">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     TAB 4: Working Hours
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'hours'): ?>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-0 py-3 px-4">
    <h6 class="fw-bold mb-0"><i class="fas fa-clock me-2 text-danger"></i>Clinic Operating Hours</h6>
    <div class="text-muted small">Set the days and times your clinic is open for business</div>
  </div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_working_hours">
      <div class="table-responsive">
        <table class="table table-borderless align-middle mb-0">
          <thead class="table-light">
            <tr><th style="width:130px">Day</th><th style="width:100px">Open?</th><th>Opening Time</th><th>Closing Time</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dayNames as $i => $day):
              $hr = $workingHours[$i] ?? ['is_open'=>($i<5?1:0),'open_time'=>'08:00','close_time'=>'17:00'];
              $isWeekend = $i >= 5;
            ?>
            <tr class="<?= $isWeekend ? 'table-light' : '' ?>">
              <td>
                <span class="fw-semibold small"><?= $day ?></span>
                <?php if ($isWeekend): ?><span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:.65rem">Weekend</span><?php endif; ?>
              </td>
              <td>
                <div class="form-check form-switch">
                  <input class="form-check-input" type="checkbox" name="day_<?= $i ?>_open"
                         id="dayOpen<?= $i ?>" value="1"
                         <?= $hr['is_open'] ? 'checked' : '' ?>
                         onchange="toggleDay(<?= $i ?>, this.checked)">
                </div>
              </td>
              <td>
                <input type="time" name="day_<?= $i ?>_from" class="form-control form-control-sm day-time-<?= $i ?>"
                       value="<?= substr($hr['open_time'],0,5) ?>" style="max-width:130px"
                       <?= !$hr['is_open'] ? 'disabled' : '' ?>>
              </td>
              <td>
                <input type="time" name="day_<?= $i ?>_to" class="form-control form-control-sm day-time-<?= $i ?>"
                       value="<?= substr($hr['close_time'],0,5) ?>" style="max-width:130px"
                       <?= !$hr['is_open'] ? 'disabled' : '' ?>>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="mt-3">
        <button type="submit" class="btn btn-danger btn-sm px-4"><i class="fas fa-save me-1"></i>Save Working Hours</button>
      </div>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     TAB 5: Notifications
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'notifications'): ?>

<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_notifications">

  <!-- Appointment reminders -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 px-4">
      <h6 class="fw-bold mb-0"><i class="fas fa-calendar-check me-2 text-danger"></i>Appointment Reminders</h6>
      <div class="text-muted small">Automatically remind patients of upcoming appointments</div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_appt_reminder_sms" id="apptSms" value="1"
                   <?= hGet($notifSettings,'notif_appt_reminder_sms','1')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="apptSms">SMS Reminder</label>
          </div>
          <div class="form-text">Send SMS to patient before appointment</div>
        </div>
        <div class="col-md-4">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_appt_reminder_email" id="apptEmail" value="1"
                   <?= hGet($notifSettings,'notif_appt_reminder_email','0')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="apptEmail">Email Reminder</label>
          </div>
          <div class="form-text">Send email to patient before appointment</div>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold">Reminder Lead Time (hours)</label>
          <select name="notif_appt_reminder_hours" class="form-select form-select-sm">
            <?php foreach ([1,2,4,6,12,24,48] as $h): ?>
            <option value="<?= $h ?>" <?= hGet($notifSettings,'notif_appt_reminder_hours','24')==$h?'selected':'' ?>>
              <?= $h === 1 ? '1 hour' : "$h hours" ?> before
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- Lab results -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 px-4">
      <h6 class="fw-bold mb-0"><i class="fas fa-flask me-2 text-danger"></i>Lab Results Ready</h6>
      <div class="text-muted small">Notify patients when laboratory results are available</div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_lab_result_sms" id="labSms" value="1"
                   <?= hGet($notifSettings,'notif_lab_result_sms','1')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="labSms">SMS Notification</label>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_lab_result_email" id="labEmail" value="1"
                   <?= hGet($notifSettings,'notif_lab_result_email','0')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="labEmail">Email Notification</label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Billing -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 px-4">
      <h6 class="fw-bold mb-0"><i class="fas fa-receipt me-2 text-danger"></i>Billing &amp; Invoices</h6>
      <div class="text-muted small">Send invoice notifications to patients</div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_bill_sms" id="billSms" value="1"
                   <?= hGet($notifSettings,'notif_bill_sms','1')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="billSms">SMS Invoice Alert</label>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_bill_email" id="billEmail" value="1"
                   <?= hGet($notifSettings,'notif_bill_email','0')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="billEmail">Email Invoice</label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Discharge -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 px-4">
      <h6 class="fw-bold mb-0"><i class="fas fa-sign-out-alt me-2 text-danger"></i>Patient Discharge</h6>
      <div class="text-muted small">Send discharge summary notification</div>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_discharge_sms" id="disSms" value="1"
                   <?= hGet($notifSettings,'notif_discharge_sms','0')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="disSms">SMS at Discharge</label>
          </div>
        </div>
        <div class="col-md-6">
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" name="notif_discharge_email" id="disEmail" value="1"
                   <?= hGet($notifSettings,'notif_discharge_email','0')==='1' ? 'checked' : '' ?>>
            <label class="form-check-label small fw-semibold" for="disEmail">Email at Discharge</label>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Sender identity -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 py-3 px-4">
      <h6 class="fw-bold mb-0"><i class="fas fa-id-badge me-2 text-danger"></i>Sender Identity</h6>
    </div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label small fw-semibold">SMS Sender Name</label>
          <input type="text" name="notif_sms_sender" class="form-control form-control-sm"
                 value="<?= e(hGet($notifSettings,'notif_sms_sender','')) ?>"
                 placeholder="e.g. MyClinic (max 11 chars)" maxlength="11">
          <div class="form-text">Alphanumeric sender ID shown on SMS. Leave blank for default.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label small fw-semibold">Email Footer Text</label>
          <textarea name="notif_email_footer" class="form-control form-control-sm" rows="2"
                    placeholder="e.g. This is an automated message from our clinic. Do not reply directly."><?= e(hGet($notifSettings,'notif_email_footer','')) ?></textarea>
        </div>
      </div>
    </div>
  </div>

  <button type="submit" class="btn btn-danger btn-sm px-4"><i class="fas fa-save me-1"></i>Save Notification Settings</button>
</form>

<!-- ════════════════════════════════════════════════════
     TAB 6: Portals & Links
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'portals'): ?>

<div class="row g-4">
  <!-- Patient portal -->
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:48px;height:48px;border-radius:12px;background:#fde8e8;display:flex;align-items:center;justify-content:center;color:#e74c3c;font-size:1.3rem">
            <i class="fas fa-procedures"></i>
          </div>
          <div>
            <div class="fw-bold">Patient Portal</div>
            <div class="text-muted small">Login via main app credentials</div>
          </div>
        </div>
        <div class="bg-light rounded p-2 font-monospace small text-break mb-2"><?= APP_URL ?>/patient/index.php</div>
        <div class="d-flex gap-2">
          <a href="<?= APP_URL ?>/patient/index.php" target="_blank" class="btn btn-sm btn-outline-danger flex-grow-1">
            <i class="fas fa-external-link-alt me-1"></i>Open Portal
          </a>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyUrl('<?= APP_URL ?>/patient/index.php')">
            <i class="fas fa-copy"></i>
          </button>
        </div>
        <div class="mt-3 text-muted small">
          <i class="fas fa-info-circle me-1"></i>Patients log in at <strong><?= APP_URL ?>/auth/login.php</strong> with their account email and password.
          Enroll patients and create accounts from the <a href="patients.php">Patients</a> page.
        </div>
      </div>
    </div>
  </div>

  <!-- Doctor portal -->
  <div class="col-md-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex align-items-center gap-3 mb-3">
          <div style="width:48px;height:48px;border-radius:12px;background:#e8f0f8;display:flex;align-items:center;justify-content:center;color:#1a4e7c;font-size:1.3rem">
            <i class="fas fa-user-md"></i>
          </div>
          <div>
            <div class="fw-bold">Doctor Portal</div>
            <div class="text-muted small">Dedicated portal for clinical doctors</div>
          </div>
        </div>
        <?php $docLoginUrl = $orgSlug ? APP_URL.'/doctor/login.php?org='.rawurlencode($orgSlug) : APP_URL.'/doctor/login.php'; ?>
        <div class="bg-light rounded p-2 font-monospace small text-break mb-2"><?= e($docLoginUrl) ?></div>
        <div class="d-flex gap-2">
          <a href="<?= e($docLoginUrl) ?>" target="_blank" class="btn btn-sm btn-outline-primary flex-grow-1">
            <i class="fas fa-external-link-alt me-1"></i>Open Portal
          </a>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyUrl('<?= e($docLoginUrl) ?>')">
            <i class="fas fa-copy"></i>
          </button>
        </div>
        <div class="mt-3 text-muted small">
          <i class="fas fa-info-circle me-1"></i>Doctors log in using their clinic email and the credentials issued from the <a href="doctors.php">Doctors</a> page.
        </div>
      </div>
    </div>
  </div>

  <!-- Documents -->
  <div class="col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-0 py-3 px-4">
        <h6 class="fw-bold mb-0"><i class="fas fa-file-medical me-2 text-danger"></i>Professional Documents</h6>
        <div class="text-muted small">Direct links to generate and print clinical documents</div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <?php
          $docs = [
            ['fas fa-prescription','Prescription','Generate a patient prescription','prescription.php'],
            ['fas fa-file-medical','Medical Certificate','Sick leave, fit-for-work, or referral','medical-certificate-pdf.php'],
            ['fas fa-file-invoice','Invoice / Bill','Patient billing invoice','billing.php'],
            ['fas fa-file-alt','Discharge Summary','IPD patient discharge document','admissions.php'],
          ];
          foreach ($docs as [$icon, $title, $desc, $url]):
          ?>
          <div class="col-sm-6 col-lg-3">
            <div class="d-flex align-items-start gap-2 p-3 border rounded" style="border-radius:8px!important">
              <i class="<?= $icon ?> text-danger mt-1"></i>
              <div>
                <div class="fw-semibold small"><?= $title ?></div>
                <div class="text-muted" style="font-size:.78rem"><?= $desc ?></div>
                <a href="<?= $url ?>" class="small text-danger text-decoration-none mt-1 d-inline-block">
                  Open <i class="fas fa-arrow-right ms-1" style="font-size:.65rem"></i>
                </a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<!-- ════════════════════════════════════════════════════
     TAB 7: Custom Domain & White Label
     ════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'domain'):
  $cd        = hGet($notifSettings, 'custom_domain',      '');
  $ptitle    = hGet($notifSettings, 'portal_title',       $org['name'] ?? '');
  $ptagline  = hGet($notifSettings, 'portal_tagline',     $org['brand_tagline'] ?? '');
  $paccent   = hGet($notifSettings, 'portal_accent',      '#e74c3c');
  $pbg       = hGet($notifSettings, 'portal_bg',          'medical');
  $ppowered  = hGet($notifSettings, 'portal_show_powered','1');

  // DNS check
  $dnsOk = false; $serverIp = '';
  if ($cd) {
      try {
          $serverIp = gethostbyname(gethostname() ?: '') ?: ($_SERVER['SERVER_ADDR'] ?? '');
          $domainIp = gethostbyname($cd);
          $dnsOk    = ($domainIp && $domainIp !== $cd && filter_var($domainIp, FILTER_VALIDATE_IP));
      } catch (Throwable $e) {}
  }
  if (!$serverIp) $serverIp = $_SERVER['SERVER_ADDR'] ?? '(check cPanel)';

  $portalUrl = $cd
    ? 'https://'.$cd.'/modules/health/portal-login.php'
    : APP_URL.'/modules/health/portal-login.php?org='.rawurlencode($orgSlug);
?>

<form method="POST">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="save_domain_settings">

  <div class="row g-4">

    <!-- ── Left column: Domain config ───────────────────────── -->
    <div class="col-lg-7">

      <!-- Domain input -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 py-3 px-4">
          <h6 class="fw-bold mb-0"><i class="fas fa-globe me-2 text-danger"></i>Custom Domain</h6>
          <div class="text-muted small">Point your clinic's domain to this health system</div>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">Domain Name</label>
            <div class="input-group">
              <span class="input-group-text text-muted" style="font-size:.82rem">https://</span>
              <input type="text" name="custom_domain" class="form-control"
                     value="<?= e($cd) ?>" placeholder="health.yourclinic.com"
                     pattern="^[a-zA-Z0-9][a-zA-Z0-9\-\.]+[a-zA-Z0-9]$"
                     title="Enter domain without https:// (e.g. health.yourclinic.com)">
            </div>
            <div class="form-text">Enter your subdomain or domain without the protocol. Do not include a trailing slash.</div>
          </div>

          <?php if ($cd): ?>
          <!-- DNS status -->
          <div class="alert <?= $dnsOk ? 'alert-success' : 'alert-warning' ?> py-2 mb-3">
            <i class="fas <?= $dnsOk ? 'fa-check-circle' : 'fa-exclamation-triangle' ?> me-2"></i>
            <?php if ($dnsOk): ?>
              <strong>Domain resolving.</strong> DNS is pointing to a valid IP. Verify it matches your server below.
            <?php else: ?>
              <strong>DNS not yet verified.</strong> The domain <code><?= e($cd) ?></code> is not resolving to a reachable IP — or it may take up to 24 h to propagate.
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Portal URL -->
          <div class="mb-0">
            <label class="form-label fw-semibold small">Portal Login URL</label>
            <div class="d-flex gap-2 align-items-center">
              <div class="bg-light rounded px-3 py-2 font-monospace small flex-grow-1 text-break" style="word-break:break-all"><?= e($portalUrl) ?></div>
              <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0"
                      onclick="copyUrl('<?= e($portalUrl) ?>')"><i class="fas fa-copy"></i></button>
              <a href="<?= e($portalUrl) ?>" target="_blank" class="btn btn-sm btn-outline-danger flex-shrink-0">
                <i class="fas fa-external-link-alt"></i>
              </a>
            </div>
          </div>
        </div>
      </div>

      <!-- DNS & SSL setup guide -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 py-3 px-4">
          <h6 class="fw-bold mb-0"><i class="fas fa-server me-2 text-danger"></i>Setup Instructions</h6>
        </div>
        <div class="card-body">

          <!-- Step 1 -->
          <div class="d-flex gap-3 mb-4">
            <div style="width:28px;height:28px;border-radius:50%;background:#e74c3c;color:#fff;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">1</div>
            <div>
              <div class="fw-semibold small mb-1">Create a DNS A Record</div>
              <p class="text-muted small mb-2">In your domain registrar (GoDaddy, Namecheap, Cloudflare, etc.), add an <strong>A Record</strong>:</p>
              <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:.82rem">
                  <thead class="table-light"><tr><th>Type</th><th>Name / Host</th><th>Value / Points To</th><th>TTL</th></tr></thead>
                  <tbody>
                    <tr>
                      <td><strong>A</strong></td>
                      <td><code><?= $cd ? e(explode('.', $cd)[0]) : 'health' ?></code></td>
                      <td>
                        <code><?= e($serverIp) ?></code>
                        <button type="button" class="btn btn-xs btn-outline-secondary ms-1" onclick="copyUrl('<?= e($serverIp) ?>')"><i class="fas fa-copy"></i></button>
                      </td>
                      <td>Auto / 3600</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="form-text mt-1">DNS propagation can take 1–24 hours. Use <a href="https://dnschecker.org" target="_blank">dnschecker.org</a> to verify.</div>
            </div>
          </div>

          <!-- Step 2 -->
          <div class="d-flex gap-3 mb-4">
            <div style="width:28px;height:28px;border-radius:50%;background:#e74c3c;color:#fff;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">2</div>
            <div>
              <div class="fw-semibold small mb-1">Add Addon Domain / Subdomain in cPanel</div>
              <p class="text-muted small mb-1">In your cPanel account:</p>
              <ol class="text-muted small ps-3 mb-0" style="line-height:1.8">
                <li>Go to <strong>Domains</strong> → <strong>Addon Domains</strong> (or <strong>Subdomains</strong> if using a subdomain)</li>
                <li>Enter <code><?= e($cd ?: 'health.yourclinic.com') ?></code> as the domain</li>
                <li>Set the <strong>Document Root</strong> to the <em>same folder</em> as your main app (e.g. <code>public_html</code>)</li>
                <li>Click <strong>Add Domain</strong></li>
              </ol>
            </div>
          </div>

          <!-- Step 3 -->
          <div class="d-flex gap-3 mb-0">
            <div style="width:28px;height:28px;border-radius:50%;background:#1a8a4e;color:#fff;font-size:.8rem;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0">3</div>
            <div>
              <div class="fw-semibold small mb-1">Enable SSL (HTTPS) — Recommended</div>
              <p class="text-muted small mb-1">Secure your portal with a free SSL certificate:</p>
              <ol class="text-muted small ps-3 mb-0" style="line-height:1.8">
                <li>In cPanel go to <strong>Security</strong> → <strong>SSL/TLS Status</strong></li>
                <li>Find <code><?= e($cd ?: 'health.yourclinic.com') ?></code> and click <strong>Run AutoSSL</strong></li>
                <li>Or go to <strong>Let's Encrypt SSL</strong> and issue a certificate for your domain</li>
                <li>Once installed, your portal will be available at <code>https://<?= e($cd ?: 'health.yourclinic.com') ?></code></li>
              </ol>
              <div class="alert alert-info py-2 mt-2 mb-0" style="font-size:.8rem">
                <i class="fas fa-lock me-1"></i><strong>SSL is strongly recommended</strong> for a health portal since it transmits patient login credentials.
              </div>
            </div>
          </div>
        </div>
      </div>

    </div><!-- /col-lg-7 -->

    <!-- ── Right column: Branding ────────────────────────────── -->
    <div class="col-lg-5">

      <!-- Branding settings -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 py-3 px-4">
          <h6 class="fw-bold mb-0"><i class="fas fa-paint-brush me-2 text-danger"></i>Portal Branding</h6>
          <div class="text-muted small">Customise the login page your staff see</div>
        </div>
        <div class="card-body">
          <div class="mb-3">
            <label class="form-label fw-semibold small">Portal Title</label>
            <input type="text" name="portal_title" class="form-control form-control-sm"
                   value="<?= e($ptitle) ?>"
                   placeholder="e.g. Sunshine Medical Centre"
                   oninput="updatePreview()">
            <div class="form-text">Shown as the main heading on the login page.</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Tagline</label>
            <input type="text" name="portal_tagline" class="form-control form-control-sm"
                   value="<?= e($ptagline) ?>"
                   placeholder="e.g. Your Health, Our Priority"
                   oninput="updatePreview()">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Accent Colour</label>
            <div class="d-flex align-items-center gap-2">
              <input type="color" name="portal_accent" id="portalAccent"
                     class="form-control form-control-color form-control-sm"
                     value="<?= e($paccent) ?>"
                     oninput="updatePreview()">
              <span class="text-muted small" id="accentHex"><?= e($paccent) ?></span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Login Page Background</label>
            <div class="d-flex gap-2 flex-wrap">
              <?php foreach (['medical'=>'Medical','gradient'=>'Gradient','white'=>'Clean White','dark'=>'Dark'] as $bgv=>$bgl): ?>
              <label class="d-flex align-items-center gap-1 cursor-pointer">
                <input type="radio" name="portal_bg" value="<?= $bgv ?>" <?= $pbg===$bgv?'checked':'' ?> onchange="updatePreview()">
                <span class="small"><?= $bgl ?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="mb-0">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="portal_show_powered"
                     id="showPowered" value="1" <?= $ppowered==='1'?'checked':'' ?>>
              <label class="form-check-label small" for="showPowered">Show "Powered by" footer</label>
            </div>
          </div>
        </div>
      </div>

      <!-- Live preview -->
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white border-0 py-2 px-4">
          <div class="fw-bold small"><i class="fas fa-eye me-1 text-danger"></i>Login Page Preview</div>
        </div>
        <div class="card-body p-0">
          <div id="portalPreview" style="border-radius:0 0 8px 8px;overflow:hidden;min-height:220px;position:relative;display:flex;align-items:stretch">
            <!-- Left branding panel -->
            <div id="previewLeft" style="width:42%;padding:20px 16px;display:flex;flex-direction:column;align-items:center;justify-content:center;background:<?= e($paccent) ?>">
              <div id="previewLogo" style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:#fff;font-weight:800;margin-bottom:10px">
                <?= strtoupper(substr($ptitle ?: ($org['name'] ?? 'C'), 0, 1)) ?>
              </div>
              <div id="previewTitle" style="color:#fff;font-size:.8rem;font-weight:800;text-align:center;line-height:1.3"><?= e($ptitle ?: ($org['name'] ?? 'Your Clinic')) ?></div>
              <div id="previewTagline" style="color:rgba(255,255,255,.7);font-size:.65rem;text-align:center;margin-top:4px"><?= e($ptagline) ?></div>
            </div>
            <!-- Right form panel -->
            <div style="flex:1;padding:20px 16px;background:#fff;display:flex;flex-direction:column;justify-content:center">
              <div style="font-size:.7rem;font-weight:700;color:#1a1a2e;margin-bottom:12px">Staff Login</div>
              <div style="background:#f8f9fa;border-radius:6px;height:28px;margin-bottom:8px;border:1px solid #e0e0e0"></div>
              <div style="background:#f8f9fa;border-radius:6px;height:28px;margin-bottom:10px;border:1px solid #e0e0e0"></div>
              <div id="previewBtn" style="background:<?= e($paccent) ?>;border-radius:6px;height:28px;display:flex;align-items:center;justify-content:center">
                <span style="color:#fff;font-size:.68rem;font-weight:700">Sign In</span>
              </div>
              <div style="text-align:center;margin-top:8px;font-size:.6rem;color:#aaa">No OrbitDesk branding visible to staff</div>
            </div>
          </div>
        </div>
      </div>

      <button type="submit" class="btn btn-danger w-100"><i class="fas fa-save me-1"></i>Save Domain & Branding</button>
    </div><!-- /col-lg-5 -->
  </div>
</form>

<?php endif; ?>

<?php
$extraJs = <<<'JS'
<script>
// ── Department modal ────────────────────────────────────────────
function resetDeptForm() {
  document.getElementById('deptId').value    = '0';
  document.getElementById('deptModalTitle').innerHTML = '<i class="fas fa-door-open me-2 text-danger"></i>Add Department';
  document.getElementById('deptName').value  = '';
  document.getElementById('deptDesc').value  = '';
  document.getElementById('deptIcon').value  = 'fas fa-stethoscope';
  document.getElementById('deptColor').value = '#e74c3c';
  document.getElementById('deptOrder').value = '0';
  document.getElementById('deptActive').checked = true;
  document.getElementById('deptHead').value  = '';
}
function editDept(d) {
  document.getElementById('deptId').value    = d.id;
  document.getElementById('deptModalTitle').innerHTML = '<i class="fas fa-door-open me-2 text-danger"></i>Edit Department';
  document.getElementById('deptName').value  = d.name;
  document.getElementById('deptDesc').value  = d.desc || '';
  document.getElementById('deptIcon').value  = d.icon || 'fas fa-stethoscope';
  document.getElementById('deptColor').value = d.color || '#e74c3c';
  document.getElementById('deptOrder').value = d.order || 0;
  document.getElementById('deptActive').checked = !!parseInt(d.active);
  document.getElementById('deptHead').value  = d.head || '';
  new bootstrap.Modal(document.getElementById('deptModal')).show();
}

// ── Consultation type modal ─────────────────────────────────────
function resetCtForm() {
  document.getElementById('ctId').value       = '0';
  document.getElementById('ctModalTitle').innerHTML = '<i class="fas fa-stethoscope me-2 text-danger"></i>Add Consultation Type';
  document.getElementById('ctName').value     = '';
  document.getElementById('ctDuration').value = '30';
  document.getElementById('ctFee').value      = '0';
  document.getElementById('ctDesc').value     = '';
  document.getElementById('ctOrder').value    = '0';
  document.getElementById('ctActive').checked = true;
}
function editCt(d) {
  document.getElementById('ctId').value       = d.id;
  document.getElementById('ctModalTitle').innerHTML = '<i class="fas fa-stethoscope me-2 text-danger"></i>Edit Consultation Type';
  document.getElementById('ctName').value     = d.name;
  document.getElementById('ctDuration').value = d.dur || 30;
  document.getElementById('ctFee').value      = d.fee || 0;
  document.getElementById('ctDesc').value     = d.desc || '';
  document.getElementById('ctOrder').value    = d.order || 0;
  document.getElementById('ctActive').checked = !!parseInt(d.active);
  new bootstrap.Modal(document.getElementById('ctModal')).show();
}

// ── Working hours toggle ────────────────────────────────────────
function toggleDay(day, isOpen) {
  document.querySelectorAll('.day-time-' + day).forEach(el => {
    el.disabled = !isOpen;
    el.style.opacity = isOpen ? '1' : '0.4';
  });
}

// ── Logo preview ────────────────────────────────────────────────
function previewLogo(input) {
  if (!input.files || !input.files[0]) return;
  const wrap = document.getElementById('logoPreviewWrap');
  const img  = document.getElementById('logoPreview');
  const reader = new FileReader();
  reader.onload = e => { img.src = e.target.result; wrap.classList.remove('d-none'); };
  reader.readAsDataURL(input.files[0]);
}

// ── Domain tab: live preview ────────────────────────────────────
function updatePreview() {
  const accent  = document.getElementById('portalAccent')?.value || '#e74c3c';
  const title   = document.querySelector('[name=portal_title]')?.value || 'Your Clinic';
  const tagline = document.querySelector('[name=portal_tagline]')?.value || '';
  document.getElementById('previewLeft')?.style && (document.getElementById('previewLeft').style.background = accent);
  if (document.getElementById('previewTitle'))   document.getElementById('previewTitle').textContent  = title;
  if (document.getElementById('previewTagline')) document.getElementById('previewTagline').textContent = tagline;
  if (document.getElementById('previewBtn'))     document.getElementById('previewBtn').style.background = accent;
  if (document.getElementById('previewLogo'))    { document.getElementById('previewLogo').textContent = title.charAt(0).toUpperCase(); }
  if (document.getElementById('accentHex'))      document.getElementById('accentHex').textContent = accent;
}

// ── Copy URL ────────────────────────────────────────────────────
function copyUrl(url) {
  navigator.clipboard.writeText(url).then(() => {
    const toast = document.createElement('div');
    toast.className = 'position-fixed bottom-0 end-0 p-3';
    toast.style.zIndex = 9999;
    toast.innerHTML = '<div class="toast show align-items-center text-white bg-success border-0 rounded-3" role="alert"><div class="d-flex"><div class="toast-body"><i class="fas fa-check me-2"></i>Link copied!</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest(\'.position-fixed\').remove()"></button></div></div>';
    document.body.appendChild(toast);
    setTimeout(() => toast.remove(), 2500);
  });
}
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
?>
