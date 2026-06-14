<?php
$pageTitle = 'My Students';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── My classes ───────────────────────────────────────────────────
$myClasses = [];
try {
    $s = $pdo->prepare(
        "SELECT DISTINCT cs.class_id, c.name AS class_name,
                GROUP_CONCAT(DISTINCT sub.name ORDER BY sub.name SEPARATOR ', ') AS subjects,
                COUNT(DISTINCT st.id) AS student_count
         FROM sch_class_subjects cs
         JOIN sch_classes c ON c.id = cs.class_id
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         LEFT JOIN sch_students st ON st.class_id = cs.class_id AND st.org_id=? AND st.status='active'
         WHERE cs.org_id=? AND cs.staff_id=?
         GROUP BY cs.class_id ORDER BY c.name"
    );
    $s->execute([$tchOrgId, $tchOrgId, $tchId]);
    $myClasses = $s->fetchAll();
} catch (Throwable $e) {}

$selectedClassId = (int)($_GET['class_id'] ?? ($myClasses[0]['class_id'] ?? 0));
$selectedClass   = null;
foreach ($myClasses as $c) { if ((int)$c['class_id'] === $selectedClassId) { $selectedClass = $c; break; } }

// ── Load students ────────────────────────────────────────────────
$students = [];
if ($selectedClassId) {
    try {
        $s = $pdo->prepare(
            "SELECT s.*,
                    (SELECT att_date FROM sch_attendance a WHERE a.student_id=s.id AND a.org_id=s.org_id
                     AND a.status='present' ORDER BY a.att_date DESC LIMIT 1) AS last_present,
                    (SELECT COUNT(*) FROM sch_attendance a WHERE a.student_id=s.id AND a.org_id=s.org_id
                     AND a.status='absent' AND a.att_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) AS absences_30d,
                    (SELECT SUM(r.marks) FROM sch_results r WHERE r.student_id=s.id AND r.org_id=s.org_id
                     AND r.class_id=? AND r.exam_id=(
                         SELECT e.id FROM sch_exams e WHERE e.org_id=s.org_id AND e.status IN ('completed','ongoing')
                         ORDER BY e.end_date DESC LIMIT 1
                     )) AS last_exam_total
             FROM sch_students s
             WHERE s.class_id=? AND s.org_id=? AND s.status='active'
             ORDER BY s.last_name, s.first_name"
        );
        $s->execute([$selectedClassId, $selectedClassId, $tchOrgId]);
        $students = $s->fetchAll();
    } catch (Throwable $e) {}
}

// View single student detail
$viewStudentId = (int)($_GET['view'] ?? 0);
$studentDetail = null;
$studentResults = [];
$studentAttSummary = [];
$studentHomework = [];
if ($viewStudentId && $selectedClassId) {
    try {
        $s = $pdo->prepare("SELECT s.*, c.name AS class_name FROM sch_students s LEFT JOIN sch_classes c ON c.id=s.class_id WHERE s.id=? AND s.org_id=? LIMIT 1");
        $s->execute([$viewStudentId, $tchOrgId]);
        $studentDetail = $s->fetch() ?: null;
    } catch (Throwable $e) {}

    if ($studentDetail) {
        // Recent results for my subjects
        try {
            $s = $pdo->prepare(
                "SELECT r.marks, r.max_marks, r.grade, r.teacher_comment,
                        sub.name AS subject_name, e.name AS exam_name, e.end_date
                 FROM sch_results r
                 JOIN sch_subjects sub ON sub.id = r.subject_id
                 JOIN sch_exams e ON e.id = r.exam_id
                 JOIN sch_class_subjects cs ON cs.subject_id = r.subject_id AND cs.class_id = r.class_id
                 WHERE r.student_id=? AND r.org_id=? AND cs.staff_id=?
                 ORDER BY e.end_date DESC LIMIT 15"
            );
            $s->execute([$viewStudentId, $tchOrgId, $tchId]);
            $studentResults = $s->fetchAll();
        } catch (Throwable $e) {}

        // Attendance last 30 days
        try {
            $s = $pdo->prepare(
                "SELECT status, COUNT(*) AS cnt FROM sch_attendance
                 WHERE student_id=? AND org_id=? AND att_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                 GROUP BY status"
            );
            $s->execute([$viewStudentId, $tchOrgId]);
            foreach ($s->fetchAll() as $r) $studentAttSummary[$r['status']] = (int)$r['cnt'];
        } catch (Throwable $e) {}

        // Homework for this class (last term)
        try {
            $s = $pdo->prepare(
                "SELECT h.title, h.due_date, h.status, sub.name AS subject_name
                 FROM sch_homework h
                 JOIN sch_subjects sub ON sub.id = h.subject_id
                 WHERE h.teacher_id=? AND h.class_id=? AND h.org_id=?
                 ORDER BY h.created_at DESC LIMIT 8"
            );
            $s->execute([$tchId, $selectedClassId, $tchOrgId]);
            $studentHomework = $s->fetchAll();
        } catch (Throwable $e) {}
    }
}

$genderIcon = ['male'=>'fa-mars','female'=>'fa-venus','other'=>'fa-genderless'];
$gradeColors = ['A'=>'#1A8A4E','B'=>'#3498db','C'=>'#f39c12','D'=>'#e67e22','E'=>'#e74c3c','F'=>'#c0392b'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-users me-2" style="color:var(--tch-green)"></i>My Students</h5>
  <span class="text-muted small"><?= count($students) ?> students</span>
</div>

<!-- Class selector -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <label class="fw-semibold small flex-shrink-0">Class:</label>
      <div class="d-flex flex-wrap gap-2">
        <?php foreach ($myClasses as $cls): ?>
        <a href="?class_id=<?= $cls['class_id'] ?>"
           class="btn btn-sm <?= (int)$cls['class_id']===$selectedClassId ? 'btn-success' : 'btn-outline-secondary' ?>">
          <?= e($cls['class_name']) ?>
          <span class="opacity-75 ms-1" style="font-size:.65rem">(<?= $cls['student_count'] ?>)</span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($selectedClass && !empty($selectedClass['subjects'])): ?>
    <div class="text-muted mt-2" style="font-size:.78rem">
      <i class="fas fa-book me-1"></i>Subjects you teach: <?= e($selectedClass['subjects']) ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($studentDetail): ?>
<!-- ── Single student detail ─────────────────────────────────── -->
<div class="mb-3">
  <a href="?class_id=<?= $selectedClassId ?>" class="btn btn-sm btn-outline-secondary">
    <i class="fas fa-arrow-left me-1"></i>Back to Class List
  </a>
</div>

<div class="row g-4">
  <!-- Profile card -->
  <div class="col-md-4">
    <div class="card border-0 shadow-sm">
      <div class="card-body text-center py-4">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3 text-white fw-bold"
             style="width:72px;height:72px;background:var(--tch-green);font-size:1.6rem">
          <?= strtoupper(substr($studentDetail['first_name'], 0, 1)) ?>
        </div>
        <h5 class="fw-bold mb-1"><?= e($studentDetail['first_name'] . ' ' . $studentDetail['last_name']) ?></h5>
        <div class="text-muted small mb-3"><?= e($studentDetail['class_name'] ?? '') ?></div>
        <table class="table table-sm text-start mb-0">
          <tr><td class="text-muted small">Adm No</td><td class="fw-semibold small"><?= e($studentDetail['admission_no'] ?? '—') ?></td></tr>
          <tr><td class="text-muted small">Gender</td><td class="fw-semibold small"><?= ucfirst($studentDetail['gender'] ?? '—') ?></td></tr>
          <?php if (!empty($studentDetail['dob'])): ?>
          <tr><td class="text-muted small">DOB</td><td class="fw-semibold small"><?= date('d M Y', strtotime($studentDetail['dob'])) ?></td></tr>
          <?php endif; ?>
          <?php if (!empty($studentDetail['phone'])): ?>
          <tr><td class="text-muted small">Phone</td><td class="fw-semibold small"><?= e($studentDetail['phone']) ?></td></tr>
          <?php endif; ?>
        </table>
      </div>
    </div>

    <!-- Attendance summary (30d) -->
    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header"><h6 class="mb-0 fw-bold small"><i class="fas fa-clipboard-check me-1 text-success"></i>Attendance (Last 30 Days)</h6></div>
      <div class="card-body">
        <?php
        $attTotal = array_sum($studentAttSummary);
        $attPrsnt = $studentAttSummary['present'] ?? 0;
        $attRate  = $attTotal > 0 ? round($attPrsnt / $attTotal * 100) : null;
        ?>
        <?php if ($attTotal): ?>
        <div class="text-center mb-2">
          <span class="fs-3 fw-bold <?= $attRate>=80?'text-success':($attRate>=60?'text-warning':'text-danger') ?>">
            <?= $attRate ?>%
          </span>
          <div class="text-muted small">Attendance Rate</div>
        </div>
        <div class="d-flex justify-content-around text-center">
          <?php foreach (['present'=>'success','absent'=>'danger','late'=>'warning','excused'=>'info'] as $st => $c): ?>
          <div>
            <div class="fw-bold small text-<?= $c ?>"><?= $studentAttSummary[$st] ?? 0 ?></div>
            <div class="text-muted" style="font-size:.65rem"><?= ucfirst($st) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted small text-center mb-0">No attendance records.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Results & homework -->
  <div class="col-md-8">
    <!-- Results -->
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-graduation-cap me-2" style="color:var(--tch-green)"></i>Recent Results (My Subjects)</h6></div>
      <div class="card-body p-0">
        <?php if (empty($studentResults)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-graduation-cap fa-2x d-block mb-2 opacity-25"></i>No results yet</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead class="table-light">
              <tr><th>Exam</th><th>Subject</th><th class="text-center">Marks</th><th class="text-center">Grade</th></tr>
            </thead>
            <tbody>
              <?php foreach ($studentResults as $r):
                $grade = strtoupper($r['grade'][0] ?? '');
                $gc = $gradeColors[$grade] ?? '#6c757d';
                $pct = $r['max_marks'] > 0 ? round($r['marks'] / $r['max_marks'] * 100) : 0;
              ?>
              <tr>
                <td class="small"><?= e($r['exam_name']) ?></td>
                <td class="small"><?= e($r['subject_name']) ?></td>
                <td class="text-center small"><?= $r['marks'] ?>/<?= $r['max_marks'] ?> <span class="text-muted">(<?= $pct ?>%)</span></td>
                <td class="text-center"><span class="badge" style="background:<?= $gc ?>"><?= e($r['grade']) ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Homework -->
    <div class="card border-0 shadow-sm">
      <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-book-open me-2" style="color:#f39c12"></i>Homework Assigned to This Class</h6></div>
      <div class="card-body p-0">
        <?php if (empty($studentHomework)): ?>
        <div class="text-center py-4 text-muted small">No homework yet.</div>
        <?php else: ?>
        <?php foreach ($studentHomework as $hw):
          $statusBadge = ['active'=>'success','closed'=>'secondary','draft'=>'warning'];
          $isOverdue = $hw['status']==='active' && !empty($hw['due_date']) && $hw['due_date'] < date('Y-m-d');
        ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="flex-grow-1">
            <div class="fw-semibold small <?= $isOverdue?'text-danger':'' ?>"><?= e($hw['title']) ?></div>
            <div class="text-muted" style="font-size:.72rem">
              <?= e($hw['subject_name']) ?>
              <?php if (!empty($hw['due_date'])): ?>&middot; Due <?= date('d M', strtotime($hw['due_date'])) ?><?php endif; ?>
            </div>
          </div>
          <span class="badge bg-<?= $statusBadge[$hw['status']] ?? 'secondary' ?>" style="font-size:.65rem"><?= ucfirst($hw['status']) ?></span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif (empty($students)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-users fa-3x mb-3 d-block opacity-25"></i>
    <h6><?= empty($myClasses) ? 'No class assignments yet' : 'No active students in this class' ?></h6>
  </div>
</div>
<?php else: ?>
<!-- ── Student list ───────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold">
      <i class="fas fa-users me-2" style="color:var(--tch-green)"></i><?= e($selectedClass['class_name'] ?? '') ?>
    </h6>
    <span class="small text-muted"><?= count($students) ?> students</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Student</th>
            <th class="d-none d-md-table-cell">Gender</th>
            <th class="text-center d-none d-md-table-cell">Absences (30d)</th>
            <th class="text-center d-none d-lg-table-cell">Last Present</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $i => $stu):
            $absences = (int)($stu['absences_30d'] ?? 0);
            $absColor = $absences >= 5 ? 'text-danger fw-bold' : ($absences >= 3 ? 'text-warning' : 'text-success');
          ?>
          <tr>
            <td class="text-muted small"><?= $i+1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0"
                     style="width:32px;height:32px;background:var(--tch-green);font-size:.72rem;font-weight:700">
                  <?= strtoupper(substr($stu['first_name'], 0, 1)) ?>
                </div>
                <div>
                  <div class="fw-semibold small"><?= e($stu['first_name'] . ' ' . $stu['last_name']) ?></div>
                  <div class="text-muted" style="font-size:.7rem"><?= e($stu['admission_no'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td class="d-none d-md-table-cell small text-muted"><?= ucfirst($stu['gender'] ?? '—') ?></td>
            <td class="text-center d-none d-md-table-cell">
              <span class="<?= $absColor ?> small"><?= $absences ?></span>
            </td>
            <td class="d-none d-lg-table-cell small text-muted">
              <?= !empty($stu['last_present']) ? date('d M Y', strtotime($stu['last_present'])) : '&mdash;' ?>
            </td>
            <td class="text-end">
              <a href="?class_id=<?= $selectedClassId ?>&view=<?= $stu['id'] ?>"
                 class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-eye me-1 d-none d-sm-inline"></i>View
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
