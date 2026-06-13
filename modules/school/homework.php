<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $classId     = (int)($_POST['class_id'] ?? 0);
        $subjectId   = (int)($_POST['subject_id'] ?? 0) ?: null;
        $teacherId   = (int)($_POST['teacher_id'] ?? 0) ?: null;
        $termId      = (int)($_POST['term_id'] ?? 0) ?: null;
        $title       = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $dueDate     = $_POST['due_date'] ?: null;
        $maxMarks    = max(1, (float)($_POST['max_marks'] ?? 10));
        if (!$classId || !$title) { setFlash('error', 'Class and title are required.'); redirect('homework.php'); }
        $pdo->prepare("INSERT INTO sch_homework
            (org_id,class_id,subject_id,teacher_id,term_id,title,description,due_date,max_marks,status)
            VALUES (?,?,?,?,?,?,?,?,?,'active')")
            ->execute([$orgId,$classId,$subjectId,$teacherId,$termId,$title,$description,$dueDate,$maxMarks]);
        logActivity('create','school',"Homework created: $title");
        setFlash('success', "Homework \"$title\" assigned.");
        redirect('homework.php');
    }

    if ($action === 'bulk_submissions') {
        $hwId     = (int)($_POST['hw_id'] ?? 0);
        $statuses = $_POST['statuses'] ?? [];
        $marks    = $_POST['marks'] ?? [];
        foreach ($statuses as $studentId => $status) {
            $studentId = (int)$studentId;
            $st = in_array($status, ['pending','submitted','late','missing']) ? $status : 'pending';
            $mk = strlen($marks[$studentId] ?? '') ? (float)$marks[$studentId] : null;
            $submittedAt = ($st==='submitted'||$st==='late') ? date('Y-m-d H:i:s') : null;
            $markedAt    = $mk !== null ? date('Y-m-d H:i:s') : null;
            $pdo->prepare("INSERT INTO sch_homework_submissions
                (homework_id,student_id,org_id,status,marks_obtained,submitted_at,marked_at)
                VALUES (?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    marks_obtained=VALUES(marks_obtained),
                    submitted_at=COALESCE(submitted_at,VALUES(submitted_at)),
                    marked_at=VALUES(marked_at)")
                ->execute([$hwId,$studentId,$orgId,$st,$mk,$submittedAt,$markedAt]);
        }
        setFlash('success','Submissions updated.');
        redirect("homework.php?view=$hwId");
    }

    if ($action === 'close' || $action === 'reopen') {
        $hwId = (int)($_POST['id'] ?? 0);
        $newStatus = $action === 'close' ? 'closed' : 'active';
        $pdo->prepare("UPDATE sch_homework SET status=? WHERE id=? AND org_id=?")->execute([$newStatus,$hwId,$orgId]);
        setFlash('success','Homework '.($action==='close'?'closed.':'reopened.'));
        redirect('homework.php');
    }

    if ($action === 'delete') {
        $hwId = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_homework_submissions WHERE homework_id=?")->execute([$hwId]);
        $pdo->prepare("DELETE FROM sch_homework WHERE id=? AND org_id=?")->execute([$hwId,$orgId]);
        setFlash('success','Homework deleted.');
        redirect('homework.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$viewHwId = (int)($_GET['view'] ?? 0);
$fClass   = (int)($_GET['class_id'] ?? 0);

$classes = $subjects = $teachers = $termsList = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,name FROM sch_subjects WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $subjects=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_teachers WHERE org_id=? AND status='active' ORDER BY first_name"); $s->execute([$orgId]); $teachers=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,name FROM sch_terms WHERE org_id=? ORDER BY start_date DESC"); $s->execute([$orgId]); $termsList=$s->fetchAll(); } catch(Exception $e){}

// Homework list
$hwList = [];
try {
    $sql = "SELECT h.*,c.name AS class_name,sb.name AS subject_name,
        t.first_name,t.last_name,
        (SELECT COUNT(*) FROM sch_students WHERE class_id=h.class_id AND org_id=h.org_id AND status='active') AS total_students,
        (SELECT COUNT(*) FROM sch_homework_submissions WHERE homework_id=h.id AND status IN('submitted','late')) AS submitted_count
        FROM sch_homework h
        LEFT JOIN sch_classes c ON h.class_id=c.id
        LEFT JOIN sch_subjects sb ON h.subject_id=sb.id
        LEFT JOIN sch_teachers t ON h.teacher_id=t.id
        WHERE h.org_id=?";
    $params = [$orgId];
    if ($fClass) { $sql .= " AND h.class_id=?"; $params[] = $fClass; }
    $sql .= " ORDER BY h.created_at DESC";
    $s=$pdo->prepare($sql); $s->execute($params); $hwList=$s->fetchAll();
} catch(Exception $e){}

// View single homework
$viewHw = null; $hwStudents = []; $hwSubmissions = [];
if ($viewHwId) {
    try {
        $s=$pdo->prepare("SELECT h.*,c.name AS class_name,sb.name AS subject_name
            FROM sch_homework h
            LEFT JOIN sch_classes c ON h.class_id=c.id
            LEFT JOIN sch_subjects sb ON h.subject_id=sb.id
            WHERE h.id=? AND h.org_id=?");
        $s->execute([$viewHwId,$orgId]); $viewHw=$s->fetch();
    } catch(Exception $e){}
    if ($viewHw) {
        try {
            $s=$pdo->prepare("SELECT id,first_name,last_name,admission_no FROM sch_students WHERE org_id=? AND class_id=? AND status='active' ORDER BY first_name");
            $s->execute([$orgId,$viewHw['class_id']]); $hwStudents=$s->fetchAll();
        } catch(Exception $e){}
        try {
            $s=$pdo->prepare("SELECT * FROM sch_homework_submissions WHERE homework_id=? AND org_id=?");
            $s->execute([$viewHwId,$orgId]);
            foreach ($s->fetchAll() as $sub) $hwSubmissions[$sub['student_id']] = $sub;
        } catch(Exception $e){}
    }
}

$statusColors = ['pending'=>'secondary','submitted'=>'success','late'=>'warning text-dark','missing'=>'danger'];
?>
<?= flashAlert() ?>

<?php if ($viewHw): ?>
<!-- ── DETAIL VIEW ─────────────────────────────────────────── -->
<div class="d-flex align-items-start gap-3 mb-4">
  <a href="homework.php" class="btn btn-sm btn-outline-secondary mt-1"><i class="fas fa-arrow-left me-1"></i>Back</a>
  <div>
    <h4 class="mb-1"><?= e($viewHw['title']) ?></h4>
    <div class="text-muted small">
      <span class="badge bg-light text-dark border me-1"><?= e($viewHw['class_name'] ?? '—') ?></span>
      <span class="badge bg-light text-dark border me-1"><?= e($viewHw['subject_name'] ?? 'General') ?></span>
      <?php if ($viewHw['due_date']): ?>
      <span class="me-2"><i class="fas fa-calendar-check me-1"></i>Due: <?= formatDate($viewHw['due_date']) ?></span>
      <?php endif; ?>
      <span><i class="fas fa-star me-1"></i>Max: <?= $viewHw['max_marks'] ?> marks</span>
    </div>
  </div>
  <div class="ms-auto">
    <span class="badge bg-<?= $viewHw['status']==='active'?'success':'secondary' ?> fs-6"><?= ucfirst($viewHw['status']) ?></span>
  </div>
</div>

<?php if ($viewHw['description']): ?>
<div class="alert alert-light border mb-3">
  <strong><i class="fas fa-info-circle me-1"></i>Description:</strong> <?= e($viewHw['description']) ?>
</div>
<?php endif; ?>

<!-- Submission stats -->
<?php
$submitted = count(array_filter($hwSubmissions, fn($s)=>in_array($s['status'],['submitted','late'])));
$missing   = count(array_filter($hwSubmissions, fn($s)=>$s['status']==='missing'));
$total     = count($hwStudents);
$pending   = $total - $submitted - $missing;
$rate      = $total > 0 ? round(100*$submitted/$total) : 0;
?>
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$total?></div><div class="stat-label">Total Students</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check"></i></div><div class="stat-body"><div class="stat-value"><?=$submitted?></div><div class="stat-label">Submitted</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$pending?></div><div class="stat-label">Pending</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-times"></i></div><div class="stat-body"><div class="stat-value"><?=$missing?></div><div class="stat-label">Missing</div></div></div></div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-clipboard-list me-2" style="color:<?=$moduleColor?>"></i>Submission Tracker</h6>
    <div class="d-flex align-items-center gap-3">
      <div class="progress" style="width:120px;height:8px">
        <div class="progress-bar bg-success" style="width:<?=$rate?>%"></div>
      </div>
      <small class="text-muted"><?=$rate?>% submitted</small>
    </div>
  </div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="bulk_submissions">
    <input type="hidden" name="hw_id" value="<?= $viewHwId ?>">
    <div class="card-body p-0">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3" style="width:35px">#</th>
            <th>Student</th>
            <th>Adm No</th>
            <th style="width:170px">Status</th>
            <th style="width:130px">Marks / <?= $viewHw['max_marks'] ?></th>
            <th>Last Updated</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($hwStudents as $i => $st):
            $sub = $hwSubmissions[$st['id']] ?? null;
            $curStatus = $sub ? $sub['status'] : 'pending';
        ?>
        <tr>
          <td class="ps-3 text-muted small"><?= $i+1 ?></td>
          <td class="fw-semibold"><?= e($st['first_name'].' '.$st['last_name']) ?></td>
          <td class="text-muted small"><?= e($st['admission_no'] ?? '—') ?></td>
          <td>
            <select name="statuses[<?= $st['id'] ?>]" class="form-select form-select-sm">
              <?php foreach (['pending','submitted','late','missing'] as $sOpt): ?>
              <option value="<?=$sOpt?>" <?=$curStatus===$sOpt?'selected':''?>><?=ucfirst($sOpt)?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td>
            <input type="number" name="marks[<?= $st['id'] ?>]"
                   class="form-control form-control-sm"
                   step="0.5" min="0" max="<?= $viewHw['max_marks'] ?>"
                   value="<?= $sub && $sub['marks_obtained'] !== null ? $sub['marks_obtained'] : '' ?>"
                   placeholder="—">
          </td>
          <td class="text-muted small"><?= $sub ? formatDate($sub['marked_at'] ?? $sub['created_at']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-footer">
      <button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">
        <i class="fas fa-save me-2"></i>Save All Submissions
      </button>
    </div>
  </form>
</div>

<?php else: ?>
<!-- ── LIST VIEW ──────────────────────────────────────────── -->
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-book-open me-2" style="color:<?=$moduleColor?>"></i>Homework & Assignments</h4>
    <p class="text-muted mb-0">Assign homework to classes and track student submissions</p>
  </div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#hwModal">
    <i class="fas fa-plus me-2"></i>Assign Homework
  </button>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-sm-3">
        <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Classes</option>
          <?php foreach ($classes as $c): ?>
          <option value="<?=$c['id']?>" <?=$fClass==$c['id']?'selected':''?>><?=e($c['name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($fClass): ?>
      <div class="col-auto"><a href="homework.php" class="btn btn-sm btn-link text-muted">Clear</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- KPIs -->
<?php
$totalHw   = count($hwList);
$activeHw  = count(array_filter($hwList, fn($h)=>$h['status']==='active'));
$overdueHw = count(array_filter($hwList, fn($h)=>$h['status']==='active'&&$h['due_date']&&$h['due_date']<date('Y-m-d')));
?>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-tasks"></i></div><div class="stat-body"><div class="stat-value"><?=$totalHw?></div><div class="stat-label">Total Assignments</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$activeHw?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$overdueHw?></div><div class="stat-label">Overdue</div></div></div></div>
</div>

<!-- Homework Table -->
<div class="card">
  <div class="card-body p-0">
    <?php if (empty($hwList)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-book-open fa-3x mb-3 d-block opacity-25"></i>
      <h6>No homework assigned yet</h6>
      <p class="small mb-0">Click "Assign Homework" to create the first assignment.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Title</th>
            <th>Class</th>
            <th>Subject</th>
            <th>Teacher</th>
            <th>Due Date</th>
            <th class="text-center">Submissions</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($hwList as $hw):
          $isOverdue = $hw['due_date'] && $hw['due_date']<date('Y-m-d') && $hw['status']==='active';
          $subRate = $hw['total_students']>0 ? round(100*$hw['submitted_count']/$hw['total_students']) : 0;
        ?>
        <tr>
          <td class="ps-3">
            <div class="fw-semibold"><?= e($hw['title']) ?></div>
            <?php if ($hw['description']): ?>
            <div class="text-muted small text-truncate" style="max-width:220px"><?= e($hw['description']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= e($hw['class_name'] ?? '—') ?></td>
          <td><?= e($hw['subject_name'] ?? '—') ?></td>
          <td><?= e(trim(($hw['first_name']??'').' '.($hw['last_name']??'')) ?: '—') ?></td>
          <td>
            <span class="<?= $isOverdue?'text-danger fw-semibold':'' ?>">
              <?= $hw['due_date'] ? formatDate($hw['due_date']) : '—' ?>
              <?php if ($isOverdue): ?><span class="badge bg-danger ms-1">Overdue</span><?php endif; ?>
            </span>
          </td>
          <td class="text-center">
            <div class="d-flex align-items-center gap-2 justify-content-center">
              <div class="progress" style="width:50px;height:6px">
                <div class="progress-bar bg-success" style="width:<?=$subRate?>%"></div>
              </div>
              <small class="text-muted"><?=$hw['submitted_count']?>/<?=$hw['total_students']?></small>
            </div>
          </td>
          <td class="text-center">
            <span class="badge bg-<?= $hw['status']==='active'?'success':'secondary' ?>">
              <?= ucfirst($hw['status']) ?>
            </span>
          </td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <a href="homework.php?view=<?=$hw['id']?>" class="btn btn-outline-primary" title="View Submissions">
                <i class="fas fa-eye"></i>
              </a>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="<?=$hw['status']==='active'?'close':'reopen'?>">
                <input type="hidden" name="id" value="<?=$hw['id']?>">
                <button class="btn btn-outline-<?=$hw['status']==='active'?'warning':'success'?>"
                        title="<?=$hw['status']==='active'?'Close':'Reopen'?>">
                  <i class="fas fa-<?=$hw['status']==='active'?'lock':'lock-open'?>"></i>
                </button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this homework and all submissions?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?=$hw['id']?>">
                <button class="btn btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Assign Homework Modal -->
<div class="modal fade" id="hwModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?=$moduleColor?>">
        <h5 class="modal-title"><i class="fas fa-book-open me-2"></i>Assign Homework</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Assignment Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required
                     placeholder="e.g. Chapter 5 Exercises — Algebra">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Class <span class="text-danger">*</span></label>
              <select name="class_id" class="form-select" required>
                <option value="">— select class —</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?=$c['id']?>"><?=e($c['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Subject</label>
              <select name="subject_id" class="form-select">
                <option value="">— General Assignment —</option>
                <?php foreach ($subjects as $s): ?>
                <option value="<?=$s['id']?>"><?=e($s['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned By</label>
              <select name="teacher_id" class="form-select">
                <option value="">— select teacher —</option>
                <?php foreach ($teachers as $t): ?>
                <option value="<?=$t['id']?>"><?=e($t['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Term</label>
              <select name="term_id" class="form-select">
                <option value="">— select term —</option>
                <?php foreach ($termsList as $t): ?>
                <option value="<?=$t['id']?>"><?=e($t['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Due Date</label>
              <input type="date" name="due_date" class="form-control"
                     value="<?= date('Y-m-d', strtotime('+3 days')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Maximum Marks</label>
              <input type="number" name="max_marks" class="form-control" step="0.5" min="1" value="10">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Description / Instructions</label>
              <textarea name="description" class="form-control" rows="3"
                        placeholder="Describe the assignment clearly for students and parents…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">
            <i class="fas fa-paper-plane me-1"></i>Assign Homework
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
