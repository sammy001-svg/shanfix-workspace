<?php
require_once __DIR__ . '/../../modules/school/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$userId = (int)$user['id'];
$pageTitle = 'HR Management';
$tab = preg_replace('/[^a-z_]/', '', $_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview','leave_types','requests','balances','attendance'])) $tab = 'overview';

// ── Auto-create tables if migration not yet run ───────────────────
foreach ([
    "CREATE TABLE IF NOT EXISTS sch_leave_types (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,name VARCHAR(100) NOT NULL,days_per_year INT NOT NULL DEFAULT 21,carry_forward TINYINT(1) NOT NULL DEFAULT 0,requires_approval TINYINT(1) NOT NULL DEFAULT 1,paid_leave TINYINT(1) NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_leave_requests (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',leave_type_id INT NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,days INT NOT NULL DEFAULT 1,reason TEXT,status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',approved_by INT DEFAULT NULL,approved_at DATETIME DEFAULT NULL,admin_notes TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_leave_balances (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',leave_type_id INT NOT NULL,year INT NOT NULL,allocated_days INT NOT NULL DEFAULT 0,used_days INT NOT NULL DEFAULT 0,PRIMARY KEY (id),UNIQUE KEY uq_lb (org_id,staff_id,staff_type,leave_type_id,year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_staff_attendance (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',att_date DATE NOT NULL,status ENUM('present','absent','late','on_leave','half_day') NOT NULL DEFAULT 'present',check_in TIME DEFAULT NULL,check_out TIME DEFAULT NULL,notes VARCHAR(255) DEFAULT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id),UNIQUE KEY uq_sa (org_id,staff_id,staff_type,att_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $sql) { try { $pdo->exec($sql); } catch (Throwable $ignored) {} }

// ── POST handlers ─────────────────────────────────────────────────
$saveMsg = null; $saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    // ── Leave Types ───────────────────────────────────────────────
    if ($action === 'save_leave_type') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $days   = max(1, (int)($_POST['days_per_year'] ?? 21));
        $carry  = (int)(!empty($_POST['carry_forward']));
        $reqApp = (int)(!empty($_POST['requires_approval']));
        $paid   = (int)(!empty($_POST['paid_leave']));
        $status = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';
        if (!$name) {
            $saveErr = 'Leave type name is required.';
        } elseif ($id) {
            $pdo->prepare("UPDATE sch_leave_types SET name=?,days_per_year=?,carry_forward=?,requires_approval=?,paid_leave=?,status=? WHERE id=? AND org_id=?")
                ->execute([$name,$days,$carry,$reqApp,$paid,$status,$id,$orgId]);
            $saveMsg = 'Leave type updated.';
        } else {
            $pdo->prepare("INSERT INTO sch_leave_types (org_id,name,days_per_year,carry_forward,requires_approval,paid_leave,status) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$days,$carry,$reqApp,$paid,$status]);
            $saveMsg = 'Leave type created.';
        }
        $tab = 'leave_types';
    }

    elseif ($action === 'delete_leave_type') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_leave_types WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        $saveMsg = 'Leave type deleted.';
        $tab = 'leave_types';
    }

    // ── Leave Request approval / rejection ────────────────────────
    elseif ($action === 'update_leave') {
        $id       = (int)($_POST['id'] ?? 0);
        $status   = in_array($_POST['new_status']??'', ['approved','rejected','pending']) ? $_POST['new_status'] : 'pending';
        $notes    = trim($_POST['admin_notes'] ?? '');
        $pdo->prepare(
            "UPDATE sch_leave_requests
             SET status=?,approved_by=?,approved_at=NOW(),admin_notes=?
             WHERE id=? AND org_id=?"
        )->execute([$status,$userId,$notes,$id,$orgId]);

        // Update used_days in balances if approved
        if ($status === 'approved') {
            $req = $pdo->prepare("SELECT * FROM sch_leave_requests WHERE id=?");
            $req->execute([$id]);
            $req = $req->fetch();
            if ($req) {
                $yr = (int)date('Y', strtotime($req['start_date']));
                $pdo->prepare(
                    "INSERT INTO sch_leave_balances (org_id,staff_id,staff_type,leave_type_id,year,allocated_days,used_days)
                     VALUES (?,?,?,?,?,(SELECT days_per_year FROM sch_leave_types WHERE id=? LIMIT 1),?)
                     ON DUPLICATE KEY UPDATE used_days = used_days + VALUES(used_days)"
                )->execute([$orgId,$req['staff_id'],$req['staff_type'],$req['leave_type_id'],$yr,$req['leave_type_id'],$req['days']]);
            }
        }
        $saveMsg = 'Leave request '.ucfirst($status).'.';
        $tab = 'requests';
    }

    // ── Staff Attendance (bulk mark) ──────────────────────────────
    elseif ($action === 'mark_attendance') {
        $attDate  = $_POST['att_date'] ?? date('Y-m-d');
        $records  = $_POST['attendance'] ?? [];
        $inserted = 0;
        foreach ($records as $staffKey => $status) {
            [$sType, $sId] = explode('_', $staffKey, 2);
            $sId    = (int)$sId;
            $status = in_array($status, ['present','absent','late','on_leave','half_day']) ? $status : 'present';
            try {
                $pdo->prepare(
                    "INSERT INTO sch_staff_attendance (org_id,staff_id,staff_type,att_date,status)
                     VALUES (?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE status=VALUES(status)"
                )->execute([$orgId,$sId,$sType,$attDate,$status]);
                $inserted++;
            } catch (Throwable $e) {}
        }
        $saveMsg = "Attendance saved for $inserted staff member(s).";
        $tab = 'attendance';
    }
}

// ── Data loading ──────────────────────────────────────────────────
$thisYear = (int)date('Y');

// Overview stats
$totalTeachers = $totalPending = $totalOnLeave = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM sch_teachers WHERE org_id=? AND status='active'");
    $s->execute([$orgId]); $totalTeachers = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM sch_leave_requests WHERE org_id=? AND status='pending'");
    $s->execute([$orgId]); $totalPending  = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM sch_leave_requests WHERE org_id=? AND status='approved' AND CURDATE() BETWEEN start_date AND end_date");
    $s->execute([$orgId]); $totalOnLeave  = (int)$s->fetchColumn();
} catch (Throwable $e) {}

// Leave types
$leaveTypes = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_leave_types WHERE org_id=? ORDER BY name");
    $s->execute([$orgId]); $leaveTypes = $s->fetchAll();
} catch (Throwable $e) {}

// Leave requests
$leaveFilter = in_array($_GET['lf']??'', ['pending','approved','rejected','all']) ? ($_GET['lf']??'all') : 'pending';
$leaveRequests = [];
try {
    $where  = "lr.org_id=?";
    $params = [$orgId];
    if ($leaveFilter !== 'all') { $where .= " AND lr.status=?"; $params[] = $leaveFilter; }
    $s = $pdo->prepare(
        "SELECT lr.*, lt.name AS leave_type_name,
                CASE lr.staff_type
                    WHEN 'teacher' THEN CONCAT(t.first_name,' ',t.last_name)
                    ELSE 'Staff'
                END AS staff_name,
                CASE lr.staff_type
                    WHEN 'teacher' THEN t.employee_id
                    ELSE NULL
                END AS employee_id
         FROM sch_leave_requests lr
         LEFT JOIN sch_leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN sch_teachers t     ON t.id  = lr.staff_id AND lr.staff_type='teacher'
         WHERE $where
         ORDER BY FIELD(lr.status,'pending','approved','rejected'), lr.start_date DESC
         LIMIT 100"
    );
    $s->execute($params); $leaveRequests = $s->fetchAll();
} catch (Throwable $e) {}

// Staff for attendance
$allStaff = [];
try {
    $s = $pdo->prepare(
        "SELECT 'teacher' AS staff_type, id, CONCAT(first_name,' ',last_name) AS full_name,
                employee_id, status
         FROM sch_teachers WHERE org_id=? AND status='active'
         ORDER BY first_name"
    );
    $s->execute([$orgId]);
    $allStaff = $s->fetchAll();
} catch (Throwable $e) {}

// Existing attendance for selected date
$attDate = $_GET['att_date'] ?? date('Y-m-d');
$existingAtt = [];
try {
    $s = $pdo->prepare("SELECT staff_id, staff_type, status FROM sch_staff_attendance WHERE org_id=? AND att_date=?");
    $s->execute([$orgId, $attDate]);
    foreach ($s->fetchAll() as $row) $existingAtt[$row['staff_type'].'_'.$row['staff_id']] = $row['status'];
} catch (Throwable $e) {}

// Edit leave type
$editLt = null;
if (!empty($_GET['edit_lt'])) {
    $eid = (int)$_GET['edit_lt'];
    foreach ($leaveTypes as $lt) { if ((int)$lt['id']===$eid) { $editLt = $lt; break; } }
}

require_once __DIR__ . '/../../includes/header-module.php';

$statusColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
?>

<!-- Header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-users-cog me-2" style="color:#1A8A4E"></i>HR Management</h5>
    <div class="text-muted small mt-1">Leave management, staff attendance &amp; HR overview</div>
  </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['val'=>$totalTeachers, 'lbl'=>'Active Staff', 'icon'=>'fas fa-users', 'bg'=>'#f0fdf4','ic'=>'#1A8A4E'],
    ['val'=>$totalPending,  'lbl'=>'Pending Leave', 'icon'=>'fas fa-clock','bg'=>'#fffbeb','ic'=>'#f59e0b'],
    ['val'=>$totalOnLeave,  'lbl'=>'On Leave Today','icon'=>'fas fa-plane-departure','bg'=>'#eff6ff','ic'=>'#1d4ed8'],
    ['val'=>count($leaveTypes),'lbl'=>'Leave Types', 'icon'=>'fas fa-list','bg'=>'#fdf4ff','ic'=>'#9333ea'],
  ] as $k): ?>
  <div class="col-6 col-md-3">
    <div class="card border-0 shadow-sm h-100" style="background:<?= $k['bg'] ?>">
      <div class="card-body d-flex align-items-center gap-3 py-3">
        <div class="rounded d-flex align-items-center justify-content-center"
             style="width:44px;height:44px;background:<?= $k['ic'] ?>22">
          <i class="<?= $k['icon'] ?>" style="color:<?= $k['ic'] ?>;font-size:1.1rem"></i>
        </div>
        <div>
          <div class="fw-bold" style="font-size:1.5rem;line-height:1"><?= $k['val'] ?></div>
          <div class="text-muted small"><?= $k['lbl'] ?></div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <?php foreach (['overview'=>'Overview','leave_types'=>'Leave Types','requests'=>'Leave Requests','attendance'=>'Attendance'] as $t=>$lbl): ?>
  <li class="nav-item">
    <a href="?tab=<?= $t ?>" class="nav-link <?= $tab===$t?'active':'' ?>">
      <?php if($t==='requests' && $totalPending>0): ?>
      <span class="badge bg-danger me-1" style="font-size:.6rem"><?= $totalPending ?></span>
      <?php endif; ?>
      <?= $lbl ?>
    </a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<!-- ═══════════════ OVERVIEW ═══════════════ -->
<?php if ($tab === 'overview'): ?>
<div class="row g-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-bold small">Pending Leave Requests</span>
        <a href="?tab=requests&lf=pending" class="btn btn-sm btn-outline-secondary">View All</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Staff</th><th>Type</th><th>Dates</th><th>Days</th><th></th></tr></thead>
          <tbody>
            <?php
              $pending5 = array_filter($leaveRequests, fn($r)=>$r['status']==='pending');
              $pending5 = array_slice(array_values($pending5), 0, 8);
              if (empty($pending5)):
            ?><tr><td colspan="5" class="text-center text-muted py-4 small">No pending requests</td></tr>
            <?php else: foreach ($pending5 as $req): ?>
            <tr>
              <td class="small fw-semibold"><?= e($req['staff_name']) ?></td>
              <td class="small"><?= e($req['leave_type_name']) ?></td>
              <td class="small text-muted">
                <?= date('d M', strtotime($req['start_date'])) ?>
                <?= $req['start_date']!==$req['end_date'] ? ' – '.date('d M', strtotime($req['end_date'])) : '' ?>
              </td>
              <td class="text-center small"><?= $req['days'] ?></td>
              <td>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_leave">
                  <input type="hidden" name="id" value="<?= $req['id'] ?>">
                  <input type="hidden" name="new_status" value="approved">
                  <button class="btn btn-xs btn-success" style="font-size:.7rem;padding:2px 8px">✓</button>
                </form>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="update_leave">
                  <input type="hidden" name="id" value="<?= $req['id'] ?>">
                  <input type="hidden" name="new_status" value="rejected">
                  <button class="btn btn-xs btn-danger" style="font-size:.7rem;padding:2px 8px">✗</button>
                </form>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small">Staff Currently on Leave</div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light"><tr><th>Staff</th><th>Leave Type</th><th>Returns</th></tr></thead>
          <tbody>
            <?php
              $onLeave = array_filter($leaveRequests, fn($r)=>$r['status']==='approved' &&
                date('Y-m-d') >= $r['start_date'] && date('Y-m-d') <= $r['end_date']);
              if (empty($onLeave)):
            ?><tr><td colspan="3" class="text-center text-muted py-4 small">No staff on leave today</td></tr>
            <?php else: foreach ($onLeave as $r): ?>
            <tr>
              <td class="small fw-semibold"><?= e($r['staff_name']) ?></td>
              <td class="small"><?= e($r['leave_type_name']) ?></td>
              <td class="small text-muted"><?= date('d M Y', strtotime($r['end_date'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════ LEAVE TYPES ═══════════════ -->
<?php elseif ($tab === 'leave_types'): ?>
<div class="row g-4">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-bold small">
        <?= $editLt ? 'Edit Leave Type' : 'New Leave Type' ?>
      </div>
      <div class="card-body">
        <form method="POST">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="save_leave_type">
          <?php if ($editLt): ?><input type="hidden" name="id" value="<?= $editLt['id'] ?>"><?php endif; ?>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Type Name <span class="text-danger">*</span></label>
            <input class="form-control form-control-sm" name="name" required
                   value="<?= e($editLt['name'] ?? '') ?>" placeholder="e.g. Annual Leave">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Days Per Year</label>
            <input type="number" class="form-control form-control-sm" name="days_per_year"
                   min="1" value="<?= (int)($editLt['days_per_year'] ?? 21) ?>">
          </div>
          <div class="mb-3 d-flex flex-column gap-2">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="paid_leave" id="paidLeave"
                     value="1" <?= ($editLt['paid_leave']??1) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="paidLeave">Paid leave</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="carry_forward" id="carryFwd"
                     value="1" <?= ($editLt['carry_forward']??0) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="carryFwd">Allow carry-forward to next year</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="requires_approval" id="reqApp"
                     value="1" <?= ($editLt['requires_approval']??1) ? 'checked' : '' ?>>
              <label class="form-check-label small" for="reqApp">Requires admin approval</label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="active"   <?= ($editLt['status']??'active')==='active'  ?'selected':'' ?>>Active</option>
              <option value="inactive" <?= ($editLt['status']??'active')==='inactive'?'selected':'' ?>>Inactive</option>
            </select>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-success btn-sm px-3">
              <i class="fas fa-save me-1"></i><?= $editLt ? 'Update' : 'Save Type' ?>
            </button>
            <?php if ($editLt): ?>
            <a href="?tab=leave_types" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <thead class="table-light">
            <tr><th>Leave Type</th><th class="text-center">Days/Yr</th><th>Options</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <?php if (empty($leaveTypes)): ?>
            <tr><td colspan="5" class="text-center text-muted py-4 small">
              No leave types yet. Add common types like Annual Leave, Sick Leave, Maternity Leave.
            </td></tr>
            <?php else: foreach ($leaveTypes as $lt): ?>
            <tr>
              <td class="fw-semibold small"><?= e($lt['name']) ?></td>
              <td class="text-center small"><?= $lt['days_per_year'] ?></td>
              <td class="small text-muted">
                <?= $lt['paid_leave'] ? '<span class="badge bg-success bg-opacity-25 text-success me-1">Paid</span>' : '' ?>
                <?= $lt['carry_forward'] ? '<span class="badge bg-primary bg-opacity-25 text-primary me-1">Carry-Fwd</span>' : '' ?>
                <?= !$lt['requires_approval'] ? '<span class="badge bg-secondary bg-opacity-25 text-secondary">Auto</span>' : '' ?>
              </td>
              <td><span class="badge bg-<?= $lt['status']==='active'?'success':'secondary' ?>"><?= ucfirst($lt['status']) ?></span></td>
              <td class="text-end">
                <a href="?tab=leave_types&edit_lt=<?= $lt['id'] ?>" class="btn btn-sm btn-outline-secondary">
                  <i class="fas fa-edit"></i>
                </a>
                <form method="POST" class="d-inline" onsubmit="return confirm('Delete this leave type?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="delete_leave_type">
                  <input type="hidden" name="id" value="<?= $lt['id'] ?>">
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
</div>

<!-- ═══════════════ LEAVE REQUESTS ═══════════════ -->
<?php elseif ($tab === 'requests'): ?>
<!-- Filter -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','all'=>'All'] as $f=>$lbl): ?>
  <a href="?tab=requests&lf=<?= $f ?>"
     class="btn btn-sm <?= $leaveFilter===$f?'btn-success':'btn-outline-secondary' ?>">
    <?= $lbl ?>
    <?php if ($f==='pending' && $totalPending>0): ?>
    <span class="badge bg-danger ms-1"><?= $totalPending ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Staff</th><th>Leave Type</th><th>Period</th><th class="text-center">Days</th><th>Status</th><th>Reason</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($leaveRequests)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5 small">
          No leave requests<?= $leaveFilter!=='all' ? ' with status &ldquo;'.e($leaveFilter).'&rdquo;' : '' ?>.
        </td></tr>
        <?php else: foreach ($leaveRequests as $req): ?>
        <tr>
          <td>
            <div class="fw-semibold small"><?= e($req['staff_name']) ?></div>
            <div class="text-muted" style="font-size:.68rem"><?= e($req['employee_id']??'') ?></div>
          </td>
          <td class="small"><?= e($req['leave_type_name']) ?></td>
          <td class="small text-muted">
            <?= date('d M Y', strtotime($req['start_date'])) ?>
            <?php if ($req['start_date']!==$req['end_date']): ?>
            <br><span style="font-size:.7rem">to <?= date('d M Y', strtotime($req['end_date'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center small fw-semibold"><?= $req['days'] ?></td>
          <td><span class="badge bg-<?= $statusColors[$req['status']] ?? 'secondary' ?>"><?= ucfirst($req['status']) ?></span></td>
          <td class="small text-muted" style="max-width:180px">
            <?= $req['reason'] ? e(mb_strimwidth($req['reason'],0,80,'…')) : '—' ?>
          </td>
          <td>
            <?php if ($req['status'] === 'pending'): ?>
            <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal"
                    data-bs-target="#approveModal"
                    onclick="setApproval(<?= $req['id'] ?>,'approved',<?= json_encode($req['staff_name']) ?>)">
              ✓ Approve
            </button>
            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal"
                    data-bs-target="#approveModal"
                    onclick="setApproval(<?= $req['id'] ?>,'rejected',<?= json_encode($req['staff_name']) ?>)">
              ✗ Reject
            </button>
            <?php else: ?>
            <span class="text-muted small"><?= $req['approved_at'] ? date('d M Y', strtotime($req['approved_at'])) : '' ?></span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Approval modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h6 class="modal-title fw-bold" id="approveModalTitle">Review Leave Request</h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <div class="modal-body">
          <input type="hidden" name="action" value="update_leave">
          <input type="hidden" name="id" id="leaveReqId">
          <input type="hidden" name="new_status" id="leaveNewStatus">
          <p id="leaveModalDesc" class="mb-3 text-muted small"></p>
          <div>
            <label class="form-label fw-semibold small">Notes / Reason for decision (optional)</label>
            <textarea class="form-control form-control-sm" name="admin_notes" rows="3"
                      placeholder="Add a comment visible to the staff member…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-sm" id="approveSubmitBtn">Confirm</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ═══════════════ ATTENDANCE ═══════════════ -->
<?php elseif ($tab === 'attendance'): ?>
<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <form method="GET" class="d-flex gap-2 align-items-center">
    <input type="hidden" name="tab" value="attendance">
    <label class="form-label fw-semibold small mb-0">Date:</label>
    <input type="date" name="att_date" class="form-control form-control-sm" style="width:auto"
           value="<?= e($attDate) ?>" onchange="this.form.submit()">
  </form>
</div>

<?php if (empty($allStaff)): ?>
<div class="alert alert-info border-0">No active staff found.</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <div class="card-header fw-bold small">
    <i class="fas fa-calendar-check me-1"></i>
    Staff Attendance — <?= date('l, d F Y', strtotime($attDate)) ?>
  </div>
  <div class="card-body">
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="mark_attendance">
      <input type="hidden" name="att_date" value="<?= e($attDate) ?>">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead class="table-light">
            <tr><th>Staff Member</th><th>Employee ID</th>
              <?php foreach(['present'=>'Present','late'=>'Late','absent'=>'Absent','on_leave'=>'On Leave','half_day'=>'Half Day'] as $v=>$l): ?>
              <th class="text-center"><?= $l ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allStaff as $sf):
              $key    = $sf['staff_type'].'_'.$sf['id'];
              $curSt  = $existingAtt[$key] ?? 'present';
            ?>
            <tr>
              <td class="fw-semibold small"><?= e($sf['full_name']) ?></td>
              <td class="text-muted small"><?= e($sf['employee_id']??'—') ?></td>
              <?php foreach(['present','late','absent','on_leave','half_day'] as $v): ?>
              <td class="text-center">
                <input type="radio" name="attendance[<?= $key ?>]" value="<?= $v ?>"
                       <?= $curSt===$v?'checked':'' ?> class="form-check-input">
              </td>
              <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn btn-success btn-sm px-4">
        <i class="fas fa-save me-1"></i>Save Attendance
      </button>
    </form>
  </div>
</div>
<?php endif; ?>
<?php endif; // end tab switch ?>

<script>
function setApproval(id, status, name) {
    document.getElementById('leaveReqId').value    = id;
    document.getElementById('leaveNewStatus').value = status;
    var btn = document.getElementById('approveSubmitBtn');
    var desc = document.getElementById('leaveModalDesc');
    var title = document.getElementById('approveModalTitle');
    if (status === 'approved') {
        btn.className = 'btn btn-sm btn-success';
        btn.textContent = 'Approve Request';
        title.textContent = 'Approve Leave';
        desc.textContent = 'You are approving the leave request for ' + name + '. This will update their leave balance.';
    } else {
        btn.className = 'btn btn-sm btn-danger';
        btn.textContent = 'Reject Request';
        title.textContent = 'Reject Leave';
        desc.textContent = 'You are rejecting the leave request for ' + name + '.';
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
