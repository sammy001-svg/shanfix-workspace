<?php
$pageTitle = 'Mark Attendance';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── Load my classes ──────────────────────────────────────────────
$myClasses = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT cs.class_id, c.name AS class_name, COUNT(st.id) AS student_count
         FROM sch_class_subjects cs
         JOIN sch_classes c ON c.id = cs.class_id
         LEFT JOIN sch_students st ON st.class_id = cs.class_id AND st.org_id=? AND st.status='active'
         WHERE cs.org_id=? AND cs.staff_id=?
         GROUP BY cs.class_id
         ORDER BY c.name"
    );
    $s->execute([$tchOrgId, $tchOrgId, $tchId]);
    $myClasses = $s->fetchAll();
} catch (Throwable $e) {}

$selectedClassId = (int)($_GET['class_id'] ?? ($myClasses[0]['class_id'] ?? 0));
$attendanceDate  = $_GET['att_date'] ?? date('Y-m-d');
// Clamp to a valid date; cannot be future
if ($attendanceDate > date('Y-m-d')) $attendanceDate = date('Y-m-d');

$selectedClass = null;
foreach ($myClasses as $c) {
    if ((int)$c['class_id'] === $selectedClassId) { $selectedClass = $c; break; }
}

// ── POST: save attendance ────────────────────────────────────────
$saveMsg = null; $saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $selectedClassId) {
    $postDate    = $_POST['att_date'] ?? date('Y-m-d');
    $postClassId = (int)($_POST['class_id'] ?? $selectedClassId);
    $records     = $_POST['records'] ?? [];

    if ($postDate > date('Y-m-d')) {
        $saveErr = 'Cannot mark attendance for a future date.';
    } elseif (empty($records)) {
        $saveErr = 'No student records submitted.';
    } else {
        $saved = 0;
        foreach ($records as $studentId => $status) {
            $studentId = (int)$studentId;
            $status    = in_array($status, ['present','absent','late','excused']) ? $status : 'absent';
            $remarks   = trim($_POST['remarks'][$studentId] ?? '');
            try {
                // Upsert: update if exists, else insert
                $pdo->prepare(
                    "INSERT INTO sch_attendance (org_id, student_id, class_id, att_date, status, remarks, marked_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE status=VALUES(status), remarks=VALUES(remarks), marked_by=VALUES(marked_by)"
                )->execute([$tchOrgId, $studentId, $postClassId, $postDate, $status, $remarks, $tchId]);
                $saved++;
            } catch (Throwable $e) {}
        }
        if ($saved > 0) {
            $saveMsg = "Attendance saved for $saved student(s) on " . date('d M Y', strtotime($postDate)) . '.';
        } else {
            $saveErr = 'Could not save attendance. Please try again.';
        }
    }
}

// ── Load students for selected class ────────────────────────────
$students = [];
if ($selectedClassId) {
    try {
        $s = $pdo->prepare(
            "SELECT id, first_name, last_name, admission_no, gender, photo
             FROM sch_students
             WHERE class_id=? AND org_id=? AND status='active'
             ORDER BY last_name, first_name"
        );
        $s->execute([$selectedClassId, $tchOrgId]);
        $students = $s->fetchAll();
    } catch (Throwable $e) {}
}

// Load existing attendance for selected date + class
$existingAtt = [];
if ($selectedClassId && !empty($students)) {
    try {
        $s = $pdo->prepare(
            "SELECT student_id, status, remarks
             FROM sch_attendance
             WHERE org_id=? AND class_id=? AND att_date=?"
        );
        $s->execute([$tchOrgId, $selectedClassId, $attendanceDate]);
        foreach ($s->fetchAll() as $r) {
            $existingAtt[$r['student_id']] = $r;
        }
    } catch (Throwable $e) {}
}

// Attendance summary for selected class (last 7 days)
$attSummary = [];
try {
    $s = $pdo->prepare(
        "SELECT att_date, status, COUNT(*) AS cnt
         FROM sch_attendance
         WHERE org_id=? AND class_id=? AND att_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
         GROUP BY att_date, status ORDER BY att_date DESC"
    );
    $s->execute([$tchOrgId, $selectedClassId]);
    foreach ($s->fetchAll() as $r) {
        $attSummary[$r['att_date']][$r['status']] = (int)$r['cnt'];
    }
} catch (Throwable $e) {}

$statusConfig = [
    'present' => ['label'=>'Present', 'badge'=>'success',   'icon'=>'fa-check'],
    'absent'  => ['label'=>'Absent',  'badge'=>'danger',    'icon'=>'fa-times'],
    'late'    => ['label'=>'Late',    'badge'=>'warning',   'icon'=>'fa-clock'],
    'excused' => ['label'=>'Excused', 'badge'=>'info',      'icon'=>'fa-file-alt'],
];
$alreadyMarked = !empty($existingAtt);
?>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-clipboard-check me-2" style="color:var(--tch-green)"></i>Mark Attendance</h5>
  <div class="text-muted small"><?= count($students) ?> active students</div>
</div>

<!-- Class + date selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="row g-3 align-items-end">
      <div class="col-md-5">
        <label class="form-label fw-semibold small mb-1">Class</label>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($myClasses as $cls): ?>
          <a href="?class_id=<?= $cls['class_id'] ?>&att_date=<?= urlencode($attendanceDate) ?>"
             class="btn btn-sm <?= (int)$cls['class_id'] === $selectedClassId ? 'btn-success' : 'btn-outline-secondary' ?>">
            <?= e($cls['class_name']) ?>
            <span class="opacity-75 ms-1" style="font-size:.68rem">(<?= $cls['student_count'] ?>)</span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold small mb-1">Date</label>
        <input type="date" class="form-control form-control-sm" id="attDatePicker"
               value="<?= e($attendanceDate) ?>" max="<?= date('Y-m-d') ?>"
               onchange="location.href='?class_id=<?= $selectedClassId ?>&att_date='+this.value">
      </div>
      <div class="col-md-3">
        <?php if ($alreadyMarked): ?>
        <div class="alert alert-info alert-sm border-0 py-2 px-3 mb-0" style="font-size:.8rem">
          <i class="fas fa-info-circle me-1"></i>Attendance already recorded &mdash; you can update it below.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if (empty($students)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-users fa-3x mb-3 d-block opacity-25"></i>
    <h6>No students in this class</h6>
  </div>
</div>
<?php else: ?>

<!-- Quick mark all buttons -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
    <span class="fw-semibold small">Quick Mark:</span>
    <?php foreach ($statusConfig as $st => $cfg): ?>
    <button type="button" class="btn btn-sm btn-outline-<?= $cfg['badge'] ?>"
            onclick="markAll('<?= $st ?>')">
      <i class="fas <?= $cfg['icon'] ?> me-1"></i><?= $cfg['label'] ?> All
    </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- Attendance form -->
<form method="POST">
  <input type="hidden" name="att_date" value="<?= e($attendanceDate) ?>">
  <input type="hidden" name="class_id" value="<?= $selectedClassId ?>">

  <div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center justify-content-between">
      <h6 class="mb-0 fw-bold">
        <i class="fas fa-users me-2" style="color:var(--tch-green)"></i>
        <?= e($selectedClass['class_name'] ?? '') ?> &mdash; <?= date('d M Y', strtotime($attendanceDate)) ?>
      </h6>
      <span class="small text-muted"><?= count($students) ?> students</span>
    </div>

    <!-- Desktop table -->
    <div class="d-none d-md-block">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th style="width:40px">#</th>
            <th>Student</th>
            <th class="text-center" style="min-width:300px">Status</th>
            <th>Remarks</th>
          </tr>
        </thead>
        <tbody id="attTable">
          <?php foreach ($students as $i => $stu):
            $existing = $existingAtt[$stu['id']] ?? null;
            $curStatus = $existing['status'] ?? 'present';
            $curRemarks = $existing['remarks'] ?? '';
          ?>
          <tr data-student-id="<?= $stu['id'] ?>">
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold small"><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
              <div class="text-muted" style="font-size:.7rem"><?= e($stu['admission_no'] ?? '') ?></div>
            </td>
            <td class="text-center">
              <div class="d-flex justify-content-center gap-2">
                <?php foreach ($statusConfig as $st => $cfg): ?>
                <div class="form-check form-check-inline m-0">
                  <input class="form-check-input status-radio" type="radio"
                         id="s<?= $stu['id'] ?>_<?= $st ?>"
                         name="records[<?= $stu['id'] ?>]"
                         value="<?= $st ?>"
                         <?= $curStatus === $st ? 'checked' : '' ?>>
                  <label class="form-check-label badge bg-<?= $cfg['badge'] ?> bg-opacity-15 border border-<?= $cfg['badge'] ?> text-<?= $cfg['badge'] ?>"
                         for="s<?= $stu['id'] ?>_<?= $st ?>"
                         style="cursor:pointer;font-size:.72rem">
                    <?= $cfg['label'] ?>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td>
              <input type="text" class="form-control form-control-sm"
                     name="remarks[<?= $stu['id'] ?>]"
                     value="<?= e($curRemarks) ?>"
                     placeholder="Optional note">
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Mobile card list -->
    <div class="d-md-none" id="attMobile">
      <?php foreach ($students as $i => $stu):
        $existing = $existingAtt[$stu['id']] ?? null;
        $curStatus = $existing['status'] ?? 'present';
        $curRemarks = $existing['remarks'] ?? '';
      ?>
      <div class="px-3 py-3 border-bottom" data-student-id="<?= $stu['id'] ?>">
        <div class="d-flex align-items-center justify-content-between mb-2">
          <div>
            <div class="fw-semibold small"><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($stu['admission_no'] ?? '') ?></div>
          </div>
          <span class="text-muted small">#<?= $i + 1 ?></span>
        </div>
        <div class="d-flex gap-2 flex-wrap">
          <?php foreach ($statusConfig as $st => $cfg): ?>
          <div class="form-check form-check-inline m-0">
            <input class="form-check-input status-radio" type="radio"
                   id="ms<?= $stu['id'] ?>_<?= $st ?>"
                   name="records[<?= $stu['id'] ?>]"
                   value="<?= $st ?>"
                   <?= $curStatus === $st ? 'checked' : '' ?>>
            <label class="form-check-label badge bg-<?= $cfg['badge'] ?> bg-opacity-15 border border-<?= $cfg['badge'] ?> text-<?= $cfg['badge'] ?>"
                   for="ms<?= $stu['id'] ?>_<?= $st ?>" style="cursor:pointer;font-size:.7rem">
              <?= $cfg['label'] ?>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="card-footer d-flex justify-content-between align-items-center">
      <span class="small text-muted" id="summaryLine">Mark status above and save.</span>
      <button type="submit" class="btn btn-success px-4">
        <i class="fas fa-save me-1"></i>Save Attendance
      </button>
    </div>
  </div>
</form>

<!-- Recent attendance log -->
<?php if (!empty($attSummary)): ?>
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-secondary"></i>Recent Attendance (Last 7 Days)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th class="text-center text-success">Present</th>
            <th class="text-center text-danger">Absent</th>
            <th class="text-center text-warning">Late</th>
            <th class="text-center text-info">Excused</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($attSummary as $d => $row): ?>
          <tr>
            <td><?= date('d M Y', strtotime($d)) ?> <?= $d===date('Y-m-d') ? '<span class="badge bg-success" style="font-size:.65rem">Today</span>' : '' ?></td>
            <td class="text-center text-success"><?= $row['present'] ?? 0 ?></td>
            <td class="text-center text-danger"><?= $row['absent'] ?? 0 ?></td>
            <td class="text-center text-warning"><?= $row['late'] ?? 0 ?></td>
            <td class="text-center text-info"><?= $row['excused'] ?? 0 ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; // students not empty ?>

<?php
$extraJs = '<script>
function markAll(status) {
    document.querySelectorAll(".status-radio[value=\""+status+"\"]").forEach(r => r.checked = true);
    updateSummary();
}
function updateSummary() {
    const counts = {present:0,absent:0,late:0,excused:0};
    document.querySelectorAll("[id^=\"s\"][type=\"radio\"]:checked").forEach(r => { if(counts[r.value]!==undefined) counts[r.value]++; });
    const line = document.getElementById("summaryLine");
    if(line) line.textContent = counts.present+" present, "+counts.absent+" absent, "+counts.late+" late, "+counts.excused+" excused";
}
document.querySelectorAll(".status-radio").forEach(r => r.addEventListener("change", updateSummary));
updateSummary();
</script>';
require_once __DIR__ . '/../includes/footer-teacher.php';
?>
