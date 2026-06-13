<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $classId   = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0) ?: null;
        $yearId    = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        if (!$classId || !$subjectId) { setFlash('error','Class and subject are required.'); redirect('subject-teachers.php'); }
        $pdo->prepare("INSERT INTO sch_subject_teachers (org_id,class_id,subject_id,teacher_id,academic_year_id)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id),academic_year_id=VALUES(academic_year_id)")
            ->execute([$orgId,$classId,$subjectId,$teacherId,$yearId]);
        logActivity('update','school',"Subject-teacher assigned: class #$classId, subject #$subjectId → teacher #$teacherId");
        setFlash('success','Assignment saved.');
        redirect("subject-teachers.php?class_id=$classId");
    }

    if ($action === 'bulk_assign') {
        $classId    = (int)($_POST['class_id'] ?? 0);
        $yearId     = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $assignments = $_POST['teacher'] ?? []; // [subject_id => teacher_id]
        $count = 0;
        foreach ($assignments as $subjectId => $teacherId) {
            $subjectId = (int)$subjectId;
            $teacherId = (int)$teacherId ?: null;
            if (!$subjectId) continue;
            $pdo->prepare("INSERT INTO sch_subject_teachers (org_id,class_id,subject_id,teacher_id,academic_year_id)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE teacher_id=VALUES(teacher_id),academic_year_id=VALUES(academic_year_id)")
                ->execute([$orgId,$classId,$subjectId,$teacherId,$yearId]);
            $count++;
        }
        logActivity('update','school',"Bulk subject-teacher assignment: $count subjects for class #$classId");
        setFlash('success',"$count subject assignment(s) saved for this class.");
        redirect("subject-teachers.php?class_id=$classId");
    }

    if ($action === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_subject_teachers WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Assignment removed.');
        redirect('subject-teachers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$classes = $teachers = $subjects = $academicYears = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,specialization FROM sch_teachers WHERE org_id=? AND status='active' ORDER BY first_name"); $s->execute([$orgId]); $teachers=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,name,code FROM sch_subjects WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $subjects=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,name FROM sch_academic_years WHERE org_id=? ORDER BY start_date DESC"); $s->execute([$orgId]); $academicYears=$s->fetchAll(); } catch(Exception $e){}

$fClass = (int)($_GET['class_id'] ?? 0);
$fYear  = (int)($_GET['academic_year_id'] ?? 0);

// Current assignments for selected class
$assignments = []; // [subject_id => assignment row]
if ($fClass) {
    try {
        $s=$pdo->prepare("SELECT st.*,sb.name AS subject_name,sb.code AS subject_code,
            CONCAT(t.first_name,' ',t.last_name) AS teacher_name, t.specialization
            FROM sch_subject_teachers st
            JOIN sch_subjects sb ON st.subject_id=sb.id
            LEFT JOIN sch_teachers t ON st.teacher_id=t.id
            WHERE st.org_id=? AND st.class_id=?");
        $s->execute([$orgId,$fClass]);
        foreach ($s->fetchAll() as $a) $assignments[$a['subject_id']] = $a;
    } catch(Exception $e){}
}

// Summary: all classes with assignment coverage
$classSummary = [];
try {
    $s=$pdo->prepare("SELECT c.id,c.name,
        COUNT(DISTINCT sb.id) AS total_subjects,
        COUNT(DISTINCT st.id) AS assigned_count
        FROM sch_classes c
        LEFT JOIN sch_subjects sb ON sb.org_id=c.org_id
        LEFT JOIN sch_subject_teachers st ON st.class_id=c.id AND st.subject_id=sb.id AND st.org_id=c.org_id
        WHERE c.org_id=?
        GROUP BY c.id,c.name ORDER BY c.name");
    $s->execute([$orgId]); $classSummary=$s->fetchAll();
} catch(Exception $e){}

$teacherMap = [];
foreach ($teachers as $t) $teacherMap[$t['id']] = $t['name'];
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chalkboard-teacher me-2" style="color:<?= $moduleColor ?>"></i>Subject-Teacher Assignments</h4>
    <p class="text-muted mb-0">Assign which teacher teaches each subject in each class</p>
  </div>
</div>

<div class="row g-4">
  <!-- Left: Class selector panel -->
  <div class="col-lg-3">
    <div class="card">
      <div class="card-header"><h6 class="mb-0 small fw-semibold text-muted text-uppercase">Classes</h6></div>
      <div class="list-group list-group-flush">
        <?php if (empty($classSummary)): ?>
        <div class="list-group-item text-muted small">No classes found.</div>
        <?php else: foreach ($classSummary as $cs):
          $pct = $cs['total_subjects'] > 0 ? round(100 * $cs['assigned_count'] / $cs['total_subjects']) : 0;
          $active = $cs['id'] == $fClass ? 'active' : '';
        ?>
        <a href="subject-teachers.php?class_id=<?= $cs['id'] ?>"
           class="list-group-item list-group-item-action py-2 px-3 <?= $active ?>">
          <div class="d-flex justify-content-between align-items-center">
            <span class="fw-semibold small"><?= e($cs['name']) ?></span>
            <span class="badge bg-<?= $pct>=100?'success':($pct>=50?'warning text-dark':'danger') ?> small"><?= $pct ?>%</span>
          </div>
          <div class="progress mt-1" style="height:3px">
            <div class="progress-bar bg-<?= $pct>=100?'success':($pct>=50?'warning':'danger') ?>" style="width:<?= $pct ?>%"></div>
          </div>
          <small class="text-muted"><?= $cs['assigned_count'] ?>/<?= $cs['total_subjects'] ?> subjects assigned</small>
        </a>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Assignment matrix -->
  <div class="col-lg-9">
    <?php if (!$fClass): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-chalkboard-teacher fa-3x mb-3 d-block opacity-50" style="color:<?= $moduleColor ?>"></i>
      <h6>Select a class on the left</h6>
      <p class="small">You can assign a teacher to each subject for the selected class.</p>
    </div>

    <?php else:
      $selClass = null;
      foreach ($classes as $c) { if ($c['id'] == $fClass) { $selClass = $c; break; } }
    ?>
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0">
          <i class="fas fa-chalkboard me-2" style="color:<?= $moduleColor ?>"></i>
          <?= e($selClass['name'] ?? "Class #$fClass") ?> — Subject Assignments
        </h6>
        <div class="d-flex gap-2 align-items-center">
          <select class="form-select form-select-sm" style="width:auto" id="yearFilter"
                  onchange="window.location='subject-teachers.php?class_id=<?= $fClass ?>&academic_year_id='+this.value">
            <option value="">All Years</option>
            <?php foreach ($academicYears as $ay): ?>
            <option value="<?= $ay['id'] ?>" <?= $fYear==$ay['id']?'selected':'' ?>><?= e($ay['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if (empty($subjects)): ?>
      <div class="card-body text-muted text-center py-4">No subjects found. Add subjects first.</div>
      <?php else: ?>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="bulk_assign">
        <input type="hidden" name="class_id" value="<?= $fClass ?>">
        <input type="hidden" name="academic_year_id" value="<?= $fYear ?>">
        <div class="card-body p-0">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Subject</th>
                <th>Code</th>
                <th style="width:260px">Assigned Teacher</th>
                <th class="text-center" style="width:100px">Status</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($subjects as $sb):
              $assigned = $assignments[$sb['id']] ?? null;
            ?>
            <tr>
              <td class="ps-3 fw-semibold"><?= e($sb['name']) ?></td>
              <td>
                <?php if ($sb['code']): ?>
                <span class="badge bg-light text-dark border small"><?= e($sb['code']) ?></span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <select name="teacher[<?= $sb['id'] ?>]" class="form-select form-select-sm">
                  <option value="">— Unassigned —</option>
                  <?php foreach ($teachers as $t): ?>
                  <option value="<?= $t['id'] ?>"
                    <?= $assigned && $assigned['teacher_id']==$t['id']?'selected':'' ?>>
                    <?= e($t['name']) ?><?= $t['specialization']?' ('.$t['specialization'].')':'' ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="text-center">
                <?php if ($assigned && $assigned['teacher_id']): ?>
                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Assigned</span>
                <?php else: ?>
                <span class="badge bg-light text-muted border">Unassigned</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="card-footer d-flex align-items-center justify-content-between">
          <span class="text-muted small">
            <i class="fas fa-info-circle me-1"></i>
            Changes are saved for <strong><?= e($selClass['name'] ?? '') ?></strong>. Select dropdowns and click Save.
          </span>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-2"></i>Save All Assignments
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>

    <!-- Quick summary card for this class -->
    <?php if (!empty($assignments)): ?>
    <div class="card mt-3">
      <div class="card-header"><h6 class="mb-0 small text-muted"><i class="fas fa-list me-2"></i>Current Assignment Summary</h6></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 small">
          <thead class="table-light">
            <tr><th class="ps-3">Subject</th><th>Teacher</th><th>Specialization</th></tr>
          </thead>
          <tbody>
          <?php foreach ($assignments as $a): if (!$a['teacher_id']) continue; ?>
          <tr>
            <td class="ps-3 fw-semibold"><?= e($a['subject_name']) ?></td>
            <td><?= e($a['teacher_name'] ?? '—') ?></td>
            <td class="text-muted"><?= e($a['specialization'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
