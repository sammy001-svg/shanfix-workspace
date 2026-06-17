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
    ['url'=>'schedule.php',      'icon'=>'fas fa-calendar-alt',        'label'=>'Doctor Schedule'],
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
    ['url'=>'telemedicine.php',  'icon'=>'fas fa-video',               'label'=>'Telemedicine'],
    ['url'=>'patient_crm.php',   'icon'=>'fas fa-users',               'label'=>'Patient CRM'],
    ['url'=>'analytics.php',     'icon'=>'fas fa-brain',               'label'=>'AI Analytics'],
    ['url'=>'reports.php',       'icon'=>'fas fa-chart-bar',           'label'=>'Reports'],
    ['url'=>'settings.php',      'icon'=>'fas fa-cog',                 'label'=>'Settings'],
];

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Self-provisioning ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS health_shifts (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        org_id        INT NOT NULL,
        name          VARCHAR(100) NOT NULL,
        start_time    TIME NOT NULL,
        end_time      TIME NOT NULL,
        color         VARCHAR(10) NOT NULL DEFAULT '#3498db',
        is_active     TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        INDEX idx_org (org_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS health_doctor_schedules (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        org_id        INT NOT NULL,
        doctor_id     INT NOT NULL,
        shift_id      INT DEFAULT NULL,
        schedule_date DATE NOT NULL,
        dept_id       INT DEFAULT NULL,
        is_oncall     TINYINT(1) NOT NULL DEFAULT 0,
        notes         VARCHAR(255) DEFAULT NULL,
        created_by    INT DEFAULT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_slot (org_id, doctor_id, schedule_date, shift_id),
        INDEX idx_org  (org_id),
        INDEX idx_date (schedule_date),
        INDEX idx_doc  (doctor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Seed default shifts if this org has none yet
try {
    $__sc = $pdo->prepare("SELECT COUNT(*) FROM health_shifts WHERE org_id=?");
    $__sc->execute([$orgId]);
    $shiftCount = (int)$__sc->fetchColumn();
    if ($shiftCount == 0) {
        $defaults = [
            ['Morning',   '07:00:00', '15:00:00', '#2980b9', 0],
            ['Afternoon', '15:00:00', '23:00:00', '#e67e22', 1],
            ['Night',     '23:00:00', '07:00:00', '#2c3e50', 2],
            ['On-Call',   '00:00:00', '23:59:59', '#8e44ad', 3],
        ];
        $ins = $pdo->prepare("INSERT IGNORE INTO health_shifts (org_id,name,start_time,end_time,color,display_order) VALUES (?,?,?,?,?,?)");
        foreach ($defaults as $d) {
            try { $ins->execute(array_merge([$orgId], $d)); } catch (Throwable $e2) {}
        }
    }
} catch (Throwable $e) {}

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_schedule') {
        $doctorId  = (int)($_POST['doctor_id']  ?? 0);
        $shiftId   = (int)($_POST['shift_id']   ?? 0) ?: null;
        $dateFrom  = $_POST['date_from'] ?? date('Y-m-d');
        $dateTo    = $_POST['date_to']   ?? $dateFrom;
        $deptId    = (int)($_POST['dept_id']    ?? 0) ?: null;
        $isOncall  = !empty($_POST['is_oncall']) ? 1 : 0;
        $notes     = sanitize($_POST['notes'] ?? '');
        $uid       = (int)$user['id'];

        if (!$doctorId || !$dateFrom) { setFlash('error', 'Doctor and date are required.'); redirect('schedule.php'); }

        // Allow range of dates (up to 60 days)
        $start = new DateTime($dateFrom);
        $end   = new DateTime($dateTo ?: $dateFrom);
        if ($end < $start) $end = clone $start;
        $diff = min(60, (int)$start->diff($end)->days + 1);

        $ins = $pdo->prepare("INSERT INTO health_doctor_schedules (org_id,doctor_id,shift_id,schedule_date,dept_id,is_oncall,notes,created_by)
                               VALUES (?,?,?,?,?,?,?,?)
                               ON DUPLICATE KEY UPDATE shift_id=VALUES(shift_id),dept_id=VALUES(dept_id),is_oncall=VALUES(is_oncall),notes=VALUES(notes)");
        $saved = 0;
        for ($i = 0; $i < $diff; $i++) {
            $d = (clone $start)->modify("+$i days")->format('Y-m-d');
            try { $ins->execute([$orgId,$doctorId,$shiftId,$d,$deptId,$isOncall,$notes,$uid]); $saved++; } catch (Throwable $e) {}
        }
        setFlash('success', "Schedule saved for $saved day" . ($saved!==1?'s':'') . '.');
        redirect('schedule.php?week=' . ($_POST['week_offset'] ?? 0));
    }

    if ($action === 'delete_schedule') {
        $id = (int)($_POST['schedule_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM health_doctor_schedules WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Schedule entry removed.');
        } catch (Throwable $e) { setFlash('error', 'Could not remove entry.'); }
        redirect('schedule.php?week=' . ($_POST['week_offset'] ?? 0));
    }

    if ($action === 'save_shift') {
        $id    = (int)($_POST['shift_id_edit'] ?? 0);
        $name  = sanitize($_POST['shift_name'] ?? '');
        $start = $_POST['shift_start'] ?? '08:00';
        $end   = $_POST['shift_end']   ?? '16:00';
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['shift_color'] ?? '') ? $_POST['shift_color'] : '#3498db';
        $order = (int)($_POST['shift_order'] ?? 0);
        if (!$name) { setFlash('error', 'Shift name required.'); redirect('schedule.php?tab=shifts'); }
        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE health_shifts SET name=?,start_time=?,end_time=?,color=?,display_order=? WHERE id=? AND org_id=?")
                    ->execute([$name,$start,$end,$color,$order,$id,$orgId]);
                setFlash('success', "Shift '$name' updated.");
            } else {
                $pdo->prepare("INSERT INTO health_shifts (org_id,name,start_time,end_time,color,display_order) VALUES (?,?,?,?,?,?)")
                    ->execute([$orgId,$name,$start,$end,$color,$order]);
                setFlash('success', "Shift '$name' created.");
            }
        } catch (Throwable $e) { setFlash('error', 'Could not save shift.'); }
        redirect('schedule.php?tab=shifts');
    }

    if ($action === 'delete_shift') {
        $id = (int)($_POST['shift_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM health_shifts WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Shift removed.');
        } catch (Throwable $e) { setFlash('error', 'Could not remove shift.'); }
        redirect('schedule.php?tab=shifts');
    }

    redirect('schedule.php');
}

// ── Week computation ──────────────────────────────────────────────
$weekOffset = max(-52, min(52, (int)($_GET['week'] ?? 0)));
$today      = date('Y-m-d');

$monday = new DateTime();
$dow    = (int)$monday->format('N'); // 1=Mon … 7=Sun
$monday->modify('-' . ($dow - 1) . ' days'); // parens kept for clarity — PHP 8 makes them redundant but they aid readability
if ($weekOffset !== 0) $monday->modify(($weekOffset > 0 ? "+$weekOffset" : $weekOffset) . ' weeks');

$weekDays = [];
for ($i = 0; $i < 7; $i++) {
    $d = (clone $monday)->modify("+$i days");
    $weekDays[] = ['date' => $d->format('Y-m-d'), 'label' => $d->format('D'), 'num' => $d->format('j'), 'month' => $d->format('M')];
}
$weekStart = $weekDays[0]['date'];
$weekEnd   = $weekDays[6]['date'];
$weekLabel = $monday->format('d M') . ' — ' . (clone $monday)->modify('+6 days')->format('d M Y');

// ── Data ──────────────────────────────────────────────────────────
$doctors = [];
try {
    $st = $pdo->prepare("SELECT id,first_name,last_name,specialization,status FROM health_doctors WHERE org_id=? AND status IN ('active','on_leave') ORDER BY first_name,last_name");
    $st->execute([$orgId]);
    $doctors = $st->fetchAll();
} catch (Throwable $e) {}

$shifts = [];
try {
    $st = $pdo->prepare("SELECT * FROM health_shifts WHERE org_id=? AND is_active=1 ORDER BY display_order,start_time");
    $st->execute([$orgId]);
    $shifts = $st->fetchAll();
} catch (Throwable $e) {}

$shiftsAll = [];
try {
    $st = $pdo->prepare("SELECT * FROM health_shifts WHERE org_id=? ORDER BY display_order,start_time");
    $st->execute([$orgId]);
    $shiftsAll = $st->fetchAll();
} catch (Throwable $e) {}
$shiftsById = array_column($shiftsAll, null, 'id');

$departments = [];
try {
    $st = $pdo->prepare("SELECT id,name FROM health_departments WHERE org_id=? AND is_active=1 ORDER BY name");
    $st->execute([$orgId]);
    $departments = $st->fetchAll();
} catch (Throwable $e) {}

// Load schedule entries for the week
$schedEntries = [];
try {
    $st = $pdo->prepare("
        SELECT s.*, sh.name AS shift_name, sh.color AS shift_color, sh.start_time AS shift_start, sh.end_time AS shift_end,
               d.name AS dept_name
        FROM health_doctor_schedules s
        LEFT JOIN health_shifts sh ON sh.id = s.shift_id
        LEFT JOIN health_departments d ON d.id = s.dept_id
        WHERE s.org_id=? AND s.schedule_date BETWEEN ? AND ?
        ORDER BY sh.display_order ASC, sh.start_time ASC
    ");
    $st->execute([$orgId, $weekStart, $weekEnd]);
    $schedEntries = $st->fetchAll();
} catch (Throwable $e) {}

// Build grid: $grid[doctorId][date] = [entries]
$grid = [];
foreach ($doctors as $d) $grid[$d['id']] = array_fill_keys(array_column($weekDays, 'date'), []);
foreach ($schedEntries as $e) {
    if (isset($grid[$e['doctor_id']][$e['schedule_date']])) {
        $grid[$e['doctor_id']][$e['schedule_date']][] = $e;
    }
}

// Today's on-call
$onCallToday = null;
try {
    $st = $pdo->prepare("
        SELECT d.first_name, d.last_name, d.specialization, d.phone, sh.name AS shift_name
        FROM health_doctor_schedules s
        JOIN health_doctors d ON d.id=s.doctor_id AND d.org_id=s.org_id
        LEFT JOIN health_shifts sh ON sh.id=s.shift_id
        WHERE s.org_id=? AND s.schedule_date=? AND s.is_oncall=1
        LIMIT 1
    ");
    $st->execute([$orgId, $today]);
    $onCallToday = $st->fetch() ?: null;
} catch (Throwable $e) {}

// Coverage: how many doctors have at least one shift today
$coveredToday = 0;
foreach ($doctors as $d) {
    if (!empty($grid[$d['id']][$today])) $coveredToday++;
}

$activeTab = $_GET['tab'] ?? 'roster';

require_once __DIR__ . '/../../includes/header-module.php';
?>

<style>
.roster-table th, .roster-table td { vertical-align: middle; font-size: .83rem; }
.roster-table thead th { background: #f8f9fa; font-weight: 700; font-size: .78rem; text-align: center; }
.roster-table td.day-cell { text-align: center; min-width: 110px; padding: .4rem .3rem; }
.roster-table td.doctor-col { min-width: 160px; }
.today-col { background: #fffbf0 !important; border-left: 3px solid #e67e22 !important; border-right: 3px solid #e67e22 !important; }
.today-th  { color: #e67e22; }
.shift-badge {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .2rem .5rem; border-radius: 20px; font-size: .72rem;
    font-weight: 600; color: #fff; margin: 2px auto;
    max-width: 100%;
}
.shift-badge .del-btn {
    background: rgba(255,255,255,.3); border: none; border-radius: 50%;
    width: 14px; height: 14px; padding: 0; font-size: .65rem; cursor: pointer;
    color: #fff; line-height: 1; flex-shrink: 0;
}
.shift-badge .del-btn:hover { background: rgba(255,255,255,.5); }
.add-shift-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 24px; height: 24px; border-radius: 50%; border: 1.5px dashed #ced4da;
    background: transparent; color: #adb5bd; cursor: pointer; font-size: 1rem;
    transition: all .15s;
}
.add-shift-btn:hover { border-color: #e74c3c; color: #e74c3c; background: #fff5f5; }
.oncall-badge { font-size: .65rem; background: rgba(255,255,255,.25); border-radius: 10px; padding: .1rem .35rem; }
.doc-leave-row td { opacity: .55; }
.doc-leave-row .doctor-col { opacity: 1; }
.week-nav-btn { padding: .3rem .8rem; font-size: .82rem; }

@media print {
    .no-print { display: none !important; }
    .card { border: 1px solid #ccc !important; }
    .roster-table { font-size: .7rem !important; }
    .shift-badge .del-btn { display: none; }
    .add-shift-btn { display: none; }
}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-1"><i class="fas fa-calendar-alt me-2" style="color:<?= $moduleColor ?>"></i>Doctor Schedule & Roster</h4>
        <p class="text-muted mb-0">Manage weekly duty assignments and on-call rosters</p>
    </div>
    <div class="d-flex gap-2 no-print">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary"><i class="fas fa-print me-1"></i>Print Roster</button>
        <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#schedModal" onclick="openAdd(null,null)">
            <i class="fas fa-plus me-1"></i>Add Schedule
        </button>
    </div>
</div>

<?php flash(); ?>

<?php if ($onCallToday): ?>
<div class="alert border-0 mb-3" style="background:linear-gradient(135deg,#8e44ad,#6c3483);color:#fff">
    <div class="d-flex align-items-center gap-3">
        <i class="fas fa-phone-volume fa-lg"></i>
        <div>
            <div class="fw-bold">On-Call Today: Dr. <?= e($onCallToday['first_name'].' '.$onCallToday['last_name']) ?></div>
            <div style="font-size:.85rem;opacity:.85"><?= e($onCallToday['specialization']) ?>
                <?php if ($onCallToday['phone']): ?>&bull; <?= e($onCallToday['phone']) ?><?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3 no-print">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='roster'?'active':'' ?>" href="?tab=roster&week=<?= $weekOffset ?>"><i class="fas fa-table me-1"></i>Weekly Roster</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='shifts'?'active':'' ?>" href="?tab=shifts"><i class="fas fa-clock me-1"></i>Shift Definitions</a>
    </li>
</ul>

<?php if ($activeTab === 'roster'): ?>

<!-- Week navigation -->
<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2 no-print">
        <a href="?tab=roster&week=<?= $weekOffset - 1 ?>" class="btn btn-outline-secondary week-nav-btn"><i class="fas fa-chevron-left"></i></a>
        <a href="?tab=roster&week=0" class="btn btn-outline-secondary week-nav-btn <?= $weekOffset===0?'active':'' ?>">Today</a>
        <a href="?tab=roster&week=<?= $weekOffset + 1 ?>" class="btn btn-outline-secondary week-nav-btn"><i class="fas fa-chevron-right"></i></a>
    </div>
    <div class="fw-bold" style="font-size:1rem"><i class="fas fa-calendar-week me-2 text-muted"></i><?= $weekLabel ?></div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge bg-success"><?= $coveredToday ?> covered today</span>
        <span class="text-muted small"><?= count($doctors) ?> total doctors</span>
    </div>
</div>

<?php if (empty($doctors)): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-user-md fa-3x mb-3 d-block opacity-25"></i>
        <p>No doctors registered yet.</p>
        <a href="doctors.php" class="btn btn-outline-danger">Register Doctors</a>
    </div>
</div>
<?php elseif (empty($shifts)): ?>
<div class="alert alert-warning">
    No shift definitions found. <a href="?tab=shifts">Define shifts first</a> before creating a roster.
</div>
<?php else: ?>

<!-- Roster grid -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered roster-table mb-0">
                <thead>
                    <tr>
                        <th class="doctor-col ps-3">Practitioner</th>
                        <?php foreach ($weekDays as $wd):
                            $isToday = $wd['date'] === $today;
                        ?>
                        <th class="<?= $isToday ? 'today-th' : '' ?>" style="min-width:112px">
                            <div><?= $wd['label'] ?></div>
                            <div style="font-size:1rem;font-weight:800"><?= $wd['num'] ?></div>
                            <div style="font-size:.7rem;opacity:.7"><?= $wd['month'] ?></div>
                        </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($doctors as $doc):
                        $isLeave = $doc['status'] === 'on_leave';
                    ?>
                    <tr class="<?= $isLeave ? 'doc-leave-row' : '' ?>">
                        <td class="doctor-col ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:<?= $isLeave ? '#bdc3c7' : $moduleColor ?>;display:flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;color:#fff;flex-shrink:0">
                                    <?= strtoupper(substr($doc['first_name'],0,1).substr($doc['last_name'],0,1)) ?>
                                </div>
                                <div>
                                    <div class="fw-semibold" style="font-size:.83rem">Dr. <?= e($doc['first_name'].' '.$doc['last_name']) ?></div>
                                    <div class="text-muted" style="font-size:.72rem"><?= e($doc['specialization']) ?></div>
                                    <?php if ($isLeave): ?>
                                    <span class="badge bg-warning text-dark" style="font-size:.62rem">On Leave</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <?php foreach ($weekDays as $wd):
                            $isToday = $wd['date'] === $today;
                            $entries = $grid[$doc['id']][$wd['date']] ?? [];
                        ?>
                        <td class="day-cell <?= $isToday ? 'today-col' : '' ?>">
                            <?php if ($isLeave): ?>
                                <span class="text-muted" style="font-size:.7rem"><i class="fas fa-user-clock"></i> Leave</span>
                            <?php else: ?>
                                <?php foreach ($entries as $entry): ?>
                                <div class="shift-badge" style="background:<?= htmlspecialchars($entry['shift_color'] ?? '#888') ?>">
                                    <span class="text-truncate" style="max-width:70px"><?= e($entry['shift_name'] ?? 'Shift') ?></span>
                                    <?php if ($entry['is_oncall']): ?>
                                    <span class="oncall-badge"><i class="fas fa-phone" style="font-size:.6rem"></i></span>
                                    <?php endif; ?>
                                    <button class="del-btn" onclick="removeEntry(<?= $entry['id'] ?>, <?= $weekOffset ?>)" title="Remove">&times;</button>
                                </div>
                                <?php endforeach; ?>
                                <button class="add-shift-btn no-print" onclick="openAdd(<?= $doc['id'] ?>, '<?= $wd['date'] ?>')" title="Add shift">+</button>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Legend -->
<div class="d-flex flex-wrap gap-2 mt-3 no-print">
    <?php foreach ($shifts as $sh): ?>
    <span class="badge" style="background:<?= htmlspecialchars($sh['color']) ?>;font-size:.78rem">
        <?= e($sh['name']) ?> (<?= date('g:ia', strtotime($sh['start_time'])) ?>–<?= date('g:ia', strtotime($sh['end_time'])) ?>)
    </span>
    <?php endforeach; ?>
    <span class="badge" style="background:#8e44ad;font-size:.78rem"><i class="fas fa-phone me-1"></i>On-Call</span>
</div>
<?php endif; ?>

<?php elseif ($activeTab === 'shifts'): ?>

<!-- Shift Definitions -->
<div class="row g-4">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header d-flex align-items-center justify-content-between">
                <h6 class="fw-bold mb-0"><i class="fas fa-clock me-2 text-danger"></i>Shift Definitions</h6>
                <button class="btn btn-sm text-white" style="background:<?= $moduleColor ?>" onclick="openShift(0)" data-bs-toggle="modal" data-bs-target="#shiftModal">
                    <i class="fas fa-plus me-1"></i>New Shift
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($shiftsAll)): ?>
                <div class="text-center py-4 text-muted"><i class="fas fa-clock fa-2x mb-2 d-block opacity-25"></i>No shifts defined.</div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead class="table-light"><tr><th>Shift</th><th>Hours</th><th>Color</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($shiftsAll as $sh): ?>
                    <tr>
                        <td>
                            <span class="badge me-2" style="background:<?= htmlspecialchars($sh['color']) ?>"><?= e($sh['name']) ?></span>
                        </td>
                        <td class="small text-muted"><?= date('g:i a', strtotime($sh['start_time'])) ?> – <?= date('g:i a', strtotime($sh['end_time'])) ?></td>
                        <td><span style="display:inline-block;width:20px;height:20px;border-radius:4px;background:<?= htmlspecialchars($sh['color']) ?>"></span></td>
                        <td><?php if ($sh['is_active']): ?><span class="badge bg-success">Active</span><?php else: ?><span class="badge bg-secondary">Inactive</span><?php endif; ?></td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-primary" onclick="openShift(<?= $sh['id'] ?>, <?= htmlspecialchars(json_encode($sh), ENT_QUOTES) ?>)" data-bs-toggle="modal" data-bs-target="#shiftModal" title="Edit"><i class="fas fa-edit"></i></button>
                                <button class="btn btn-outline-danger" onclick="delShift(<?= $sh['id'] ?>, '<?= e($sh['name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm" style="border-left:4px solid #3498db!important">
            <div class="card-body">
                <h6 class="fw-bold mb-3"><i class="fas fa-info-circle me-2 text-primary"></i>About Shifts</h6>
                <p class="small text-muted mb-2">Shifts define the working time slots available in your institution. Common examples:</p>
                <ul class="small text-muted mb-3">
                    <li><strong>Morning</strong> — 7:00 AM to 3:00 PM</li>
                    <li><strong>Afternoon</strong> — 3:00 PM to 11:00 PM</li>
                    <li><strong>Night</strong> — 11:00 PM to 7:00 AM</li>
                    <li><strong>On-Call</strong> — Available at all times for emergencies</li>
                </ul>
                <p class="small text-muted mb-0">Once shifts are defined, assign them to doctors on the <a href="?tab=roster">Weekly Roster</a>.</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- ── Add Schedule Modal ───────────────────────────────────────── -->
<div class="modal fade" id="schedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_schedule">
                <input type="hidden" name="week_offset" value="<?= $weekOffset ?>">
                <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
                    <h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i>Assign Schedule</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Doctor <span class="text-danger">*</span></label>
                        <select name="doctor_id" id="schedDoctor" class="form-select" required>
                            <option value="">— Select Doctor —</option>
                            <?php foreach ($doctors as $d): ?>
                            <?php if ($d['status'] !== 'on_leave'): ?>
                            <option value="<?= $d['id'] ?>"><?= e('Dr. '.$d['first_name'].' '.$d['last_name'].' ('.$d['specialization'].')') ?></option>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">From Date <span class="text-danger">*</span></label>
                            <input type="date" name="date_from" id="schedDateFrom" class="form-control" required value="<?= $today ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">To Date</label>
                            <input type="date" name="date_to" id="schedDateTo" class="form-control" value="<?= $today ?>">
                            <div class="form-text">Leave same as From for a single day</div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Shift</label>
                        <select name="shift_id" class="form-select">
                            <option value="">— No specific shift —</option>
                            <?php foreach ($shifts as $sh): ?>
                            <option value="<?= $sh['id'] ?>" data-color="<?= htmlspecialchars($sh['color']) ?>"><?= e($sh['name']) ?> (<?= date('g:ia', strtotime($sh['start_time'])) ?>–<?= date('g:ia', strtotime($sh['end_time'])) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Department <span class="text-muted fw-normal">(optional)</span></label>
                        <select name="dept_id" class="form-select">
                            <option value="">— All Departments —</option>
                            <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>"><?= e($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Covering for Dr. Smith">
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_oncall" id="schedOncall" value="1">
                        <label class="form-check-label fw-semibold" for="schedOncall">
                            <i class="fas fa-phone me-1 text-purple" style="color:#8e44ad"></i>Mark as On-Call
                        </label>
                        <div class="form-text">On-call doctor is highlighted in the dashboard and roster.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Schedule</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Shift Definition Modal ─────────────────────────────────── -->
<div class="modal fade" id="shiftModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_shift">
                <input type="hidden" name="shift_id_edit" id="shiftIdEdit" value="0">
                <div class="modal-header text-white" style="background:#2c3e50">
                    <h5 class="modal-title" id="shiftModalTitle"><i class="fas fa-clock me-2"></i>Define Shift</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Shift Name <span class="text-danger">*</span></label>
                        <input type="text" name="shift_name" id="shiftName" class="form-control" required placeholder="e.g. Morning, Night, On-Call">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Start Time</label>
                            <input type="time" name="shift_start" id="shiftStart" class="form-control" value="08:00">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">End Time</label>
                            <input type="time" name="shift_end" id="shiftEnd" class="form-control" value="16:00">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Badge Color</label>
                            <input type="color" name="shift_color" id="shiftColor" class="form-control form-control-color w-100" value="#3498db">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Display Order</label>
                            <input type="number" name="shift_order" id="shiftOrder" class="form-control" value="0" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn text-white" style="background:#2c3e50"><i class="fas fa-save me-1"></i>Save Shift</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete forms -->
<form method="POST" id="delSchedForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete_schedule">
    <input type="hidden" name="schedule_id" id="delSchedId">
    <input type="hidden" name="week_offset" id="delSchedWeek" value="0">
</form>
<form method="POST" id="delShiftForm" style="display:none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete_shift">
    <input type="hidden" name="shift_id" id="delShiftId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd(doctorId, date) {
    if (doctorId) document.getElementById('schedDoctor').value = doctorId;
    if (date) {
        document.getElementById('schedDateFrom').value = date;
        document.getElementById('schedDateTo').value   = date;
    }
    document.getElementById('schedOncall').checked = false;
    var m = new bootstrap.Modal(document.getElementById('schedModal'));
    m.show();
}

function removeEntry(id, weekOffset) {
    Swal.fire({
        title: 'Remove Schedule Entry?',
        text: 'This shift assignment will be removed from the roster.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Remove'
    }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('delSchedId').value   = id;
            document.getElementById('delSchedWeek').value = weekOffset;
            document.getElementById('delSchedForm').submit();
        }
    });
}

function openShift(id, data) {
    document.getElementById('shiftIdEdit').value = id || 0;
    document.getElementById('shiftName').value   = data ? data.name       : '';
    document.getElementById('shiftStart').value  = data ? data.start_time : '08:00';
    document.getElementById('shiftEnd').value    = data ? data.end_time   : '16:00';
    document.getElementById('shiftColor').value  = data ? data.color      : '#3498db';
    document.getElementById('shiftOrder').value  = data ? data.display_order : 0;
    document.getElementById('shiftModalTitle').innerHTML =
        '<i class="fas fa-clock me-2"></i>' + (id ? 'Edit Shift' : 'New Shift');
}

function delShift(id, name) {
    Swal.fire({
        title: 'Remove Shift?',
        text: 'Remove the "' + name + '" shift? Existing schedule entries using this shift will also be affected.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#e74c3c',
        confirmButtonText: 'Yes, Remove'
    }).then(r => {
        if (r.isConfirmed) {
            document.getElementById('delShiftId').value = id;
            document.getElementById('delShiftForm').submit();
        }
    });
}

// Sync date_to to always be >= date_from
document.getElementById('schedDateFrom')?.addEventListener('change', function() {
    const to = document.getElementById('schedDateTo');
    if (to.value && to.value < this.value) to.value = this.value;
});
</script>
JS;

require_once __DIR__ . '/../../includes/footer.php';
?>
