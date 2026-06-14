<?php
$pageTitle = 'My Leave';
require_once __DIR__ . '/../includes/header-teacher.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure tables exist
foreach ([
    "CREATE TABLE IF NOT EXISTS sch_leave_types (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,name VARCHAR(100) NOT NULL,days_per_year INT NOT NULL DEFAULT 21,carry_forward TINYINT(1) NOT NULL DEFAULT 0,requires_approval TINYINT(1) NOT NULL DEFAULT 1,paid_leave TINYINT(1) NOT NULL DEFAULT 1,status VARCHAR(20) NOT NULL DEFAULT 'active',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_leave_requests (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',leave_type_id INT NOT NULL,start_date DATE NOT NULL,end_date DATE NOT NULL,days INT NOT NULL DEFAULT 1,reason TEXT,status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',approved_by INT DEFAULT NULL,approved_at DATETIME DEFAULT NULL,admin_notes TEXT,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "CREATE TABLE IF NOT EXISTS sch_leave_balances (id INT NOT NULL AUTO_INCREMENT,org_id INT NOT NULL,staff_id INT NOT NULL,staff_type ENUM('teacher','staff') NOT NULL DEFAULT 'teacher',leave_type_id INT NOT NULL,year INT NOT NULL,allocated_days INT NOT NULL DEFAULT 0,used_days INT NOT NULL DEFAULT 0,PRIMARY KEY (id),UNIQUE KEY uq_lb (org_id,staff_id,staff_type,leave_type_id,year)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
] as $sql) { try { $pdo->exec($sql); } catch (Throwable $e) {} }

$saveMsg = null; $saveErr = null;
$thisYear = (int)date('Y');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'apply_leave') {
        $ltId   = (int)($_POST['leave_type_id'] ?? 0);
        $start  = $_POST['start_date'] ?? '';
        $end    = $_POST['end_date']   ?? '';
        $reason = trim($_POST['reason'] ?? '');
        if (!$ltId || !$start || !$end) {
            $saveErr = 'Please fill all required fields.';
        } elseif (strtotime($end) < strtotime($start)) {
            $saveErr = 'End date cannot be before start date.';
        } else {
            $days = 0;
            $cur  = strtotime($start);
            $fin  = strtotime($end);
            while ($cur <= $fin) {
                if ((int)date('N', $cur) < 6) $days++;
                $cur = strtotime('+1 day', $cur);
            }
            $days = max(1, $days);
            $bal = $pdo->prepare("SELECT allocated_days, used_days FROM sch_leave_balances WHERE org_id=? AND staff_id=? AND staff_type='teacher' AND leave_type_id=? AND year=?");
            $bal->execute([$tchOrgId,$tchId,$ltId,$thisYear]);
            $balRow = $bal->fetch();
            $remaining = $balRow ? ($balRow['allocated_days'] - $balRow['used_days']) : 0;
            $lt = $pdo->prepare("SELECT * FROM sch_leave_types WHERE id=? AND org_id=?");
            $lt->execute([$ltId,$tchOrgId]); $ltRow = $lt->fetch();
            if (!$ltRow) {
                $saveErr = 'Invalid leave type.';
            } elseif ($days > $remaining) {
                $saveErr = "Insufficient leave balance. You have $remaining day(s) remaining for this type.";
            } else {
                $pdo->prepare("INSERT INTO sch_leave_requests (org_id,staff_id,staff_type,leave_type_id,start_date,end_date,days,reason) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$tchOrgId,$tchId,'teacher',$ltId,$start,$end,$days,$reason]);
                $saveMsg = "Leave request submitted for $days day(s). Awaiting approval.";
            }
        }
    }

    elseif ($action === 'cancel_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        $pdo->prepare("UPDATE sch_leave_requests SET status='cancelled' WHERE id=? AND staff_id=? AND org_id=? AND status='pending'")
            ->execute([$lid,$tchId,$tchOrgId]);
        $saveMsg = 'Leave request cancelled.';
    }
}

// Load leave types
$leaveTypes = [];
try {
    $s = $pdo->prepare("SELECT * FROM sch_leave_types WHERE org_id=? AND status='active' ORDER BY name");
    $s->execute([$tchOrgId]); $leaveTypes = $s->fetchAll();
} catch (Throwable $e) {}

// Auto-provision balances for current year
foreach ($leaveTypes as $lt) {
    try {
        $pdo->prepare(
            "INSERT INTO sch_leave_balances (org_id,staff_id,staff_type,leave_type_id,year,allocated_days,used_days)
             VALUES (?,?,?,?,?,?,0)
             ON DUPLICATE KEY UPDATE allocated_days=IF(allocated_days=0,VALUES(allocated_days),allocated_days)"
        )->execute([$tchOrgId,$tchId,'teacher',$lt['id'],$thisYear,$lt['days_per_year']]);
    } catch (Throwable $e) {}
}

// Load balances
$balances = [];
try {
    $s = $pdo->prepare(
        "SELECT lb.*, lt.name AS type_name, lt.paid_leave, lt.carry_forward
         FROM sch_leave_balances lb
         JOIN sch_leave_types lt ON lt.id=lb.leave_type_id
         WHERE lb.org_id=? AND lb.staff_id=? AND lb.staff_type='teacher' AND lb.year=?
         ORDER BY lt.name"
    );
    $s->execute([$tchOrgId,$tchId,$thisYear]); $balances = $s->fetchAll();
} catch (Throwable $e) {}

// Load leave history
$myRequests = [];
try {
    $s = $pdo->prepare(
        "SELECT lr.*, lt.name AS type_name
         FROM sch_leave_requests lr
         JOIN sch_leave_types lt ON lt.id=lr.leave_type_id
         WHERE lr.org_id=? AND lr.staff_id=? AND lr.staff_type='teacher'
         ORDER BY lr.created_at DESC LIMIT 50"
    );
    $s->execute([$tchOrgId,$tchId]); $myRequests = $s->fetchAll();
} catch (Throwable $e) {}

$statusColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];
?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-calendar-check me-2" style="color:var(--tch-green)"></i>My Leave</h5>
    <div class="text-muted small">Apply for leave and track your requests — <?= $thisYear ?></div>
  </div>
  <button class="btn btn-sm px-3 text-white" style="background:var(--tch-green)"
          data-bs-toggle="modal" data-bs-target="#applyModal">
    <i class="fas fa-plus me-1"></i>Apply for Leave
  </button>
</div>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<!-- Balance Cards -->
<div class="row g-3 mb-4">
  <?php if (empty($balances)): ?>
  <div class="col-12">
    <div class="alert alert-info border-0">
      <i class="fas fa-info-circle me-2"></i>No leave types configured. Contact your administrator.
    </div>
  </div>
  <?php else: foreach ($balances as $bal):
    $remaining = $bal['allocated_days'] - $bal['used_days'];
    $pct = $bal['allocated_days'] > 0 ? round(($bal['used_days'] / $bal['allocated_days']) * 100) : 0;
  ?>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
      <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div class="fw-bold small"><?= e($bal['type_name']) ?></div>
          <?= $bal['paid_leave'] ? '<span class="badge bg-success bg-opacity-25 text-success" style="font-size:.6rem">Paid</span>' : '<span class="badge bg-secondary bg-opacity-25 text-secondary" style="font-size:.6rem">Unpaid</span>' ?>
        </div>
        <div class="d-flex justify-content-between align-items-end mb-2">
          <div>
            <span class="fw-bold" style="font-size:1.6rem;color:var(--tch-green)"><?= $remaining ?></span>
            <span class="text-muted small"> / <?= $bal['allocated_days'] ?> days</span>
          </div>
          <div class="text-muted small text-end"><div><?= $bal['used_days'] ?> used</div></div>
        </div>
        <div class="progress" style="height:6px;border-radius:3px">
          <div class="progress-bar" role="progressbar"
               style="width:<?= $pct ?>%;background:<?= $pct>=80?'#dc2626':($pct>=50?'#f59e0b':'var(--tch-green)') ?>"
               aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"></div>
        </div>
        <div class="text-muted mt-1" style="font-size:.68rem"><?= $pct ?>% used</div>
      </div>
    </div>
  </div>
  <?php endforeach; endif; ?>
</div>

<!-- Leave History -->
<div class="card border-0 shadow-sm">
  <div class="card-header fw-bold small">Leave History</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Leave Type</th><th>Period</th><th class="text-center">Days</th><th>Status</th><th>Applied</th><th>Admin Notes</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (empty($myRequests)): ?>
        <tr><td colspan="7" class="text-center text-muted py-5 small">No leave requests yet.</td></tr>
        <?php else: foreach ($myRequests as $req): ?>
        <tr>
          <td class="small fw-semibold"><?= e($req['type_name']) ?></td>
          <td class="small">
            <?= date('d M Y', strtotime($req['start_date'])) ?>
            <?php if ($req['start_date'] !== $req['end_date']): ?>
            <span class="text-muted"> – <?= date('d M Y', strtotime($req['end_date'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center small fw-semibold"><?= $req['days'] ?></td>
          <td><span class="badge bg-<?= $statusColors[$req['status']] ?>"><?= ucfirst($req['status']) ?></span></td>
          <td class="small text-muted"><?= date('d M Y', strtotime($req['created_at'])) ?></td>
          <td class="small text-muted" style="max-width:180px">
            <?= $req['admin_notes'] ? e(mb_strimwidth($req['admin_notes'],0,80,'…')) : ($req['reason'] ? e(mb_strimwidth($req['reason'],0,60,'…')) : '—') ?>
          </td>
          <td>
            <?php if ($req['status'] === 'pending'): ?>
            <form method="POST" onsubmit="return confirm('Cancel this leave request?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="cancel_leave">
              <input type="hidden" name="leave_id" value="<?= $req['id'] ?>">
              <button class="btn btn-xs btn-outline-danger" style="font-size:.7rem;padding:2px 8px">Cancel</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Apply Modal -->
<div class="modal fade" id="applyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <div class="modal-header" style="background:var(--tch-green);color:#fff">
        <h6 class="modal-title fw-bold"><i class="fas fa-calendar-plus me-1"></i>Apply for Leave</h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="apply_leave">
        <div class="modal-body">
          <?php if (empty($leaveTypes)): ?>
          <div class="alert alert-warning border-0 small">
            <i class="fas fa-exclamation-triangle me-2"></i>No leave types configured. Please contact your admin.
          </div>
          <?php else: ?>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Leave Type <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" name="leave_type_id" required>
              <option value="">— Select Leave Type —</option>
              <?php foreach ($leaveTypes as $lt):
                $myBal = null;
                foreach ($balances as $b) { if ((int)$b['leave_type_id']===(int)$lt['id']) { $myBal=$b; break; } }
                $rem = $myBal ? ($myBal['allocated_days'] - $myBal['used_days']) : 0;
              ?>
              <option value="<?= $lt['id'] ?>">
                <?= e($lt['name']) ?> (<?= $rem ?> day<?= $rem!==1?'s':'' ?> remaining)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label fw-semibold small">Start Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-sm" name="start_date" required
                     min="<?= date('Y-m-d') ?>" id="leaveStart" onchange="calcDays()">
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold small">End Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control form-control-sm" name="end_date" required
                     min="<?= date('Y-m-d') ?>" id="leaveEnd" onchange="calcDays()">
            </div>
          </div>
          <div class="mb-3">
            <div class="alert alert-info border-0 py-2 small" id="daysCalc" style="display:none">
              <i class="fas fa-info-circle me-1"></i><span id="daysCalcText"></span>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold small">Reason / Details</label>
            <textarea class="form-control form-control-sm" name="reason" rows="3"
                      placeholder="Brief description of your leave reason…"></textarea>
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <?php if (!empty($leaveTypes)): ?>
          <button type="submit" class="btn btn-sm text-white px-4" style="background:var(--tch-green)">
            <i class="fas fa-paper-plane me-1"></i>Submit Request
          </button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function calcDays() {
    var s = document.getElementById('leaveStart').value;
    var e = document.getElementById('leaveEnd').value;
    if (!s || !e) return;
    var start = new Date(s), end = new Date(e);
    if (end < start) { document.getElementById('daysCalc').style.display='none'; return; }
    var days = 0, cur = new Date(start);
    while (cur <= end) {
        var d = cur.getDay();
        if (d !== 0 && d !== 6) days++;
        cur.setDate(cur.getDate()+1);
    }
    document.getElementById('daysCalcText').textContent = 'Estimated working days: ' + days;
    document.getElementById('daysCalc').style.display = '';
}
</script>

  </div><!-- #tchContent -->
</div><!-- #tchMain -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
