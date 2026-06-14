<?php
$pageTitle = 'Homework';
require_once __DIR__ . '/../includes/header-teacher.php';

// ── My classes and subjects ──────────────────────────────────────
$myClassSubjects = [];
try {
    $s = $pdo->prepare(
        "SELECT cs.class_id, cs.subject_id, c.name AS class_name, sub.name AS subject_name
         FROM sch_class_subjects cs
         JOIN sch_classes c ON c.id = cs.class_id
         JOIN sch_subjects sub ON sub.id = cs.subject_id
         WHERE cs.org_id=? AND cs.staff_id=?
         ORDER BY c.name, sub.name"
    );
    $s->execute([$tchOrgId, $tchId]);
    $myClassSubjects = $s->fetchAll();
} catch (Throwable $e) {}

// ── Current terms ────────────────────────────────────────────────
$terms = [];
try {
    $s = $pdo->prepare("SELECT id, name, status FROM sch_terms WHERE org_id=? ORDER BY start_date DESC LIMIT 6");
    $s->execute([$tchOrgId]);
    $terms = $s->fetchAll();
} catch (Throwable $e) {}
$currentTermId = 0;
foreach ($terms as $t) { if ($t['status'] === 'active') { $currentTermId = $t['id']; break; } }
if (!$currentTermId && !empty($terms)) $currentTermId = $terms[0]['id'];

// ── POST: create / update / change status ────────────────────────
$saveMsg = null; $saveErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'status_change') {
        $hwId   = (int)($_POST['hw_id'] ?? 0);
        $status = in_array($_POST['new_status'] ?? '', ['active','closed','draft']) ? $_POST['new_status'] : 'active';
        try {
            $pdo->prepare("UPDATE sch_homework SET status=? WHERE id=? AND teacher_id=? AND org_id=?")
                ->execute([$status, $hwId, $tchId, $tchOrgId]);
            $saveMsg = 'Homework status updated.';
        } catch (Throwable $e) { $saveErr = 'Could not update status.'; }
    } elseif ($action === 'delete') {
        $hwId = (int)($_POST['hw_id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=?")
                ->execute([$hwId, $tchId, $tchOrgId]);
            $saveMsg = 'Homework deleted.';
        } catch (Throwable $e) { $saveErr = 'Could not delete homework.'; }
    } else {
        // create or edit
        $hwId      = (int)($_POST['hw_id'] ?? 0);
        $classId   = (int)($_POST['class_id'] ?? 0);
        $subjectId = (int)($_POST['subject_id'] ?? 0);
        $termId    = (int)($_POST['term_id'] ?? $currentTermId);
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $instr     = trim($_POST['instructions'] ?? '');
        $dueDate   = $_POST['due_date'] ?? null;
        $maxMarks  = (int)($_POST['max_marks'] ?? 0);
        $status    = in_array($_POST['status'] ?? '', ['active','closed','draft']) ? $_POST['status'] : 'active';

        if (!$classId || !$subjectId || !$title) {
            $saveErr = 'Class, subject, and title are required.';
        } else {
            if ($hwId) {
                try {
                    $pdo->prepare(
                        "UPDATE sch_homework SET class_id=?,subject_id=?,term_id=?,title=?,description=?,instructions=?,due_date=?,max_marks=?,status=?
                         WHERE id=? AND teacher_id=? AND org_id=?"
                    )->execute([$classId,$subjectId,$termId,$title,$desc,$instr,$dueDate?:null,$maxMarks,$status,$hwId,$tchId,$tchOrgId]);
                    $saveMsg = 'Homework updated.';
                } catch (Throwable $e) { $saveErr = 'Could not update homework.'; }
            } else {
                try {
                    $pdo->prepare(
                        "INSERT INTO sch_homework (org_id,class_id,subject_id,teacher_id,term_id,title,description,instructions,due_date,max_marks,status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$tchOrgId,$classId,$subjectId,$tchId,$termId,$title,$desc,$instr,$dueDate?:null,$maxMarks,$status]);
                    $saveMsg = 'Homework assigned successfully.';
                } catch (Throwable $e) { $saveErr = 'Could not create homework.'; }
            }
        }
    }
}

// ── Load my homework ─────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$hwList = [];
try {
    $where = "h.org_id=? AND h.teacher_id=?";
    $params = [$tchOrgId, $tchId];
    if ($filterStatus !== 'all') { $where .= " AND h.status=?"; $params[] = $filterStatus; }
    $s = $pdo->prepare(
        "SELECT h.*, c.name AS class_name, sub.name AS subject_name
         FROM sch_homework h
         JOIN sch_classes c ON c.id = h.class_id
         JOIN sch_subjects sub ON sub.id = h.subject_id
         WHERE $where ORDER BY h.created_at DESC"
    );
    $s->execute($params);
    $hwList = $s->fetchAll();
} catch (Throwable $e) {}

// Edit mode
$editHw = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    foreach ($hwList as $hw) { if ((int)$hw['id'] === $eid) { $editHw = $hw; break; } }
    if (!$editHw) {
        try {
            $s = $pdo->prepare("SELECT * FROM sch_homework WHERE id=? AND teacher_id=? AND org_id=? LIMIT 1");
            $s->execute([$eid, $tchId, $tchOrgId]);
            $editHw = $s->fetch() ?: null;
        } catch (Throwable $e) {}
    }
}

$statusBadges = ['active'=>'success','closed'=>'secondary','draft'=>'warning'];
?>

<?php if ($saveMsg): ?>
<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?= e($saveMsg) ?></div>
<?php endif; ?>
<?php if ($saveErr): ?>
<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?= e($saveErr) ?></div>
<?php endif; ?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <h5 class="fw-bold mb-0"><i class="fas fa-book-open me-2" style="color:var(--tch-green)"></i>Homework</h5>
  <button class="btn btn-sm text-white" style="background:var(--tch-green)"
          data-bs-toggle="collapse" data-bs-target="#hwForm">
    <i class="fas fa-plus me-1"></i><?= $editHw ? 'Edit Assignment' : 'New Assignment' ?>
  </button>
</div>

<!-- Create / Edit form -->
<div class="collapse <?= ($editHw || !empty($saveErr)) ? 'show' : '' ?> mb-4" id="hwForm">
  <div class="card border-0 shadow-sm">
    <div class="card-header">
      <h6 class="mb-0 fw-bold"><?= $editHw ? 'Edit Homework: ' . e($editHw['title']) : 'New Homework Assignment' ?></h6>
    </div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="action" value="<?= $editHw ? 'edit' : 'create' ?>">
        <?php if ($editHw): ?>
        <input type="hidden" name="hw_id" value="<?= $editHw['id'] ?>">
        <?php endif; ?>

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Class <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" name="class_id" id="classSelect" required>
              <option value="">Select class</option>
              <?php
              $classList = [];
              foreach ($myClassSubjects as $cs) {
                  if (!isset($classList[$cs['class_id']])) $classList[$cs['class_id']] = $cs['class_name'];
              }
              foreach ($classList as $cid => $cname):
              ?>
              <option value="<?= $cid ?>" <?= ($editHw && (int)$editHw['class_id']===$cid) ? 'selected' : '' ?>>
                <?= e($cname) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Subject <span class="text-danger">*</span></label>
            <select class="form-select form-select-sm" name="subject_id" id="subjectSelect" required>
              <option value="">Select subject</option>
              <?php foreach ($myClassSubjects as $cs): ?>
              <option value="<?= $cs['subject_id'] ?>"
                      data-class="<?= $cs['class_id'] ?>"
                      <?= ($editHw && (int)$editHw['subject_id']===(int)$cs['subject_id'] && (int)$editHw['class_id']===(int)$cs['class_id']) ? 'selected' : '' ?>>
                <?= e($cs['subject_name']) ?> (<?= e($cs['class_name']) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Term</label>
            <select class="form-select form-select-sm" name="term_id">
              <?php foreach ($terms as $t): ?>
              <option value="<?= $t['id'] ?>" <?= ($editHw ? (int)$editHw['term_id']===(int)$t['id'] : (int)$t['id']===$currentTermId) ? 'selected' : '' ?>>
                <?= e($t['name']) ?><?= $t['status']==='active' ? ' (Current)' : '' ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Title <span class="text-danger">*</span></label>
            <input type="text" class="form-control form-control-sm" name="title" required
                   value="<?= e($editHw['title'] ?? '') ?>" placeholder="e.g. Chapter 5 Practice Questions">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Description</label>
            <textarea class="form-control form-control-sm" name="description" rows="2"
                      placeholder="Brief overview of the assignment"><?= e($editHw['description'] ?? '') ?></textarea>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold small">Instructions</label>
            <textarea class="form-control form-control-sm" name="instructions" rows="3"
                      placeholder="Detailed instructions for students"><?= e($editHw['instructions'] ?? '') ?></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Due Date</label>
            <input type="date" class="form-control form-control-sm" name="due_date"
                   value="<?= e($editHw['due_date'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Max Marks</label>
            <input type="number" class="form-control form-control-sm" name="max_marks" min="0"
                   value="<?= (int)($editHw['max_marks'] ?? 0) ?>" placeholder="0 = ungraded">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold small">Status</label>
            <select class="form-select form-select-sm" name="status">
              <option value="draft"  <?= ($editHw['status']??'active')==='draft'  ? 'selected' : '' ?>>Draft (not visible)</option>
              <option value="active" <?= ($editHw['status']??'active')==='active' ? 'selected' : '' ?>>Active (visible to parents)</option>
              <option value="closed" <?= ($editHw['status']??'active')==='closed' ? 'selected' : '' ?>>Closed</option>
            </select>
          </div>
        </div>

        <div class="d-flex gap-2 mt-3">
          <button type="submit" class="btn btn-success btn-sm px-3">
            <i class="fas fa-save me-1"></i><?= $editHw ? 'Update' : 'Assign Homework' ?>
          </button>
          <a href="homework.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
  <?php foreach (['all'=>'All','active'=>'Active','draft'=>'Drafts','closed'=>'Closed'] as $val => $lbl): ?>
  <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus===$val ? 'btn-success' : 'btn-outline-secondary' ?>">
    <?= $lbl ?>
    <?php if ($val==='all'): ?>
    <span class="badge bg-white text-dark ms-1" style="font-size:.65rem"><?= count($hwList) ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Homework list -->
<?php if (empty($hwList)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-book-open fa-3x mb-3 d-block opacity-25"></i>
    <h6>No homework assignments yet</h6>
    <p class="small">Create your first assignment using the button above.</p>
  </div>
</div>
<?php else: ?>
<div class="row g-3">
  <?php foreach ($hwList as $hw):
    $isOverdue = $hw['status']==='active' && !empty($hw['due_date']) && $hw['due_date'] < date('Y-m-d');
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?= $isOverdue?'#e74c3c':($hw['status']==='active'?'#1A8A4E':($hw['status']==='draft'?'#f39c12':'#adb5bd')) ?>!important">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <h6 class="fw-bold mb-0 <?= $isOverdue?'text-danger':'' ?>"><?= e($hw['title']) ?></h6>
          <span class="badge bg-<?= $statusBadges[$hw['status']] ?? 'secondary' ?> flex-shrink-0"><?= ucfirst($hw['status']) ?></span>
        </div>
        <div class="text-muted small mb-2">
          <i class="fas fa-chalkboard me-1"></i><?= e($hw['class_name']) ?>
          &nbsp;&middot;&nbsp;
          <i class="fas fa-book me-1"></i><?= e($hw['subject_name']) ?>
          <?php if (!empty($hw['due_date'])): ?>
          &nbsp;&middot;&nbsp;
          <i class="fas fa-calendar-times me-1 <?= $isOverdue?'text-danger':'' ?>"></i>
          Due <?= date('d M Y', strtotime($hw['due_date'])) ?>
          <?php if ($isOverdue): ?><span class="text-danger">(Overdue)</span><?php endif; ?>
          <?php endif; ?>
          <?php if ($hw['max_marks'] > 0): ?>
          &nbsp;&middot;&nbsp; <?= $hw['max_marks'] ?> marks
          <?php endif; ?>
        </div>
        <?php if (!empty($hw['description'])): ?>
        <p class="text-muted mb-2" style="font-size:.83rem;line-height:1.55"><?= nl2br(e($hw['description'])) ?></p>
        <?php endif; ?>
        <div class="d-flex gap-2 mt-3 flex-wrap">
          <a href="?edit=<?= $hw['id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-edit me-1"></i>Edit
          </a>
          <?php if ($hw['status'] === 'active'): ?>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="status_change">
            <input type="hidden" name="hw_id" value="<?= $hw['id'] ?>">
            <input type="hidden" name="new_status" value="closed">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
              <i class="fas fa-lock me-1"></i>Close
            </button>
          </form>
          <?php elseif ($hw['status'] === 'closed' || $hw['status'] === 'draft'): ?>
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="status_change">
            <input type="hidden" name="hw_id" value="<?= $hw['id'] ?>">
            <input type="hidden" name="new_status" value="active">
            <button type="submit" class="btn btn-sm btn-outline-success">
              <i class="fas fa-unlock me-1"></i>Re-open
            </button>
          </form>
          <?php endif; ?>
          <form method="POST" class="d-inline"
                onsubmit="return confirm('Delete this assignment? This cannot be undone.')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="hw_id" value="<?= $hw['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">
              <i class="fas fa-trash"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
$extraJs = '<script>
// Filter subjects by selected class
document.getElementById("classSelect")?.addEventListener("change", function(){
    const cid = this.value;
    const sel = document.getElementById("subjectSelect");
    Array.from(sel.options).forEach(o => {
        if(!o.value) return;
        o.hidden = cid && o.dataset.class !== cid;
    });
    sel.value = "";
});
</script>';
require_once __DIR__ . '/../includes/footer-teacher.php';
?>
