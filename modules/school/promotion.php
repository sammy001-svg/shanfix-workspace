<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'promote') {
        $academicYearId = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $decisions = $_POST['decision'] ?? [];
        $notes     = $_POST['notes'] ?? [];

        $pdo->beginTransaction();
        $count = 0;
        foreach ($decisions as $studentId => $decision) {
            $studentId = (int)$studentId;
            $type = in_array($decision['type'] ?? '', ['promoted','graduated','retained','transferred'])
                ? $decision['type'] : 'promoted';
            $toClassId = (int)($decision['to_class_id'] ?? 0) ?: null;
            $note = sanitize($notes[$studentId] ?? '');

            $sStmt = $pdo->prepare("SELECT class_id FROM sch_students WHERE id=? AND org_id=?");
            $sStmt->execute([$studentId, $orgId]);
            $student = $sStmt->fetch();
            if (!$student) continue;

            $fromClassId = $student['class_id'];

            $pdo->prepare("INSERT INTO sch_promotions
                (org_id,student_id,from_class_id,to_class_id,academic_year_id,promotion_type,promoted_by,notes)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE
                    promotion_type=VALUES(promotion_type),
                    to_class_id=VALUES(to_class_id),
                    notes=VALUES(notes),
                    promoted_by=VALUES(promoted_by)")
                ->execute([$orgId,$studentId,$fromClassId,$toClassId,$academicYearId,$type,$user['id'],$note]);

            if ($type === 'promoted' && $toClassId) {
                $pdo->prepare("UPDATE sch_students SET class_id=? WHERE id=? AND org_id=?")
                    ->execute([$toClassId,$studentId,$orgId]);
            } elseif ($type === 'graduated') {
                $pdo->prepare("UPDATE sch_students SET status='graduated' WHERE id=? AND org_id=?")
                    ->execute([$studentId,$orgId]);
            } elseif ($type === 'transferred') {
                $pdo->prepare("UPDATE sch_students SET status='transferred' WHERE id=? AND org_id=?")
                    ->execute([$studentId,$orgId]);
            }
            $count++;
        }
        $pdo->commit();
        logActivity('update', 'school', "Bulk promotion: $count student(s) processed");
        setFlash('success', "$count student(s) processed successfully.");
        redirect('promotion.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$classes = []; $academicYears = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Exception $e){}
try { $s=$pdo->prepare("SELECT id,name FROM sch_academic_years WHERE org_id=? ORDER BY start_date DESC"); $s->execute([$orgId]); $academicYears=$s->fetchAll(); } catch(Exception $e){}

$fClass = (int)($_GET['class_id'] ?? 0);
$fYear  = (int)($_GET['academic_year_id'] ?? 0);

$students = [];
if ($fClass) {
    try {
        $s=$pdo->prepare("SELECT s.id,s.first_name,s.last_name,s.admission_no,s.curriculum,c.name AS class_name
            FROM sch_students s LEFT JOIN sch_classes c ON s.class_id=c.id
            WHERE s.org_id=? AND s.class_id=? AND s.status='active' ORDER BY s.first_name");
        $s->execute([$orgId,$fClass]); $students=$s->fetchAll();
    } catch(Exception $e){}
}

$history = [];
try {
    $s=$pdo->prepare("SELECT pr.*,s.first_name,s.last_name,s.admission_no,
        c1.name AS from_class,c2.name AS to_class,ay.name AS academic_year,u.name AS promoted_by_name
        FROM sch_promotions pr
        JOIN sch_students s ON pr.student_id=s.id
        LEFT JOIN sch_classes c1 ON pr.from_class_id=c1.id
        LEFT JOIN sch_classes c2 ON pr.to_class_id=c2.id
        LEFT JOIN sch_academic_years ay ON pr.academic_year_id=ay.id
        LEFT JOIN users u ON pr.promoted_by=u.id
        WHERE pr.org_id=? ORDER BY pr.created_at DESC LIMIT 200");
    $s->execute([$orgId]); $history=$s->fetchAll();
} catch(Exception $e){}

$typeColors = ['promoted'=>'success','graduated'=>'primary','retained'=>'warning text-dark','transferred'=>'secondary'];
$typeIcons  = ['promoted'=>'fa-arrow-up','graduated'=>'fa-graduation-cap','retained'=>'fa-redo','transferred'=>'fa-exchange-alt'];
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-arrow-up me-2" style="color:<?= $moduleColor ?>"></i>Student Promotion</h4>
    <p class="text-muted mb-0">End-of-year class promotion, graduation, and retention management</p>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Class to Promote</label>
        <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Choose a class —</option>
          <?php foreach ($classes as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $fClass==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Academic Year</label>
        <select name="academic_year_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">— Select year —</option>
          <?php foreach ($academicYears as $ay): ?>
          <option value="<?= $ay['id'] ?>" <?= $fYear==$ay['id']?'selected':'' ?>><?= e($ay['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
  </div>
</div>

<?php if (!$fClass): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-arrow-up fa-3x mb-3 d-block opacity-50" style="color:<?= $moduleColor ?>"></i>
  <h6 class="fw-semibold">Select a class to begin promotion</h6>
  <p class="small">Promote students to the next class, mark them as graduated, retained, or transferred at year end.</p>
</div>

<?php elseif (empty($students)): ?>
<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No active students found in this class.</div>

<?php else: ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>
      <?= count($students) ?> Students — <?= e($students[0]['class_name'] ?? '') ?>
    </h6>
    <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-success" onclick="setAllDecision('promoted')">
        <i class="fas fa-arrow-up me-1"></i>All Promote
      </button>
      <button type="button" class="btn btn-sm btn-outline-primary" onclick="setAllDecision('graduated')">
        <i class="fas fa-graduation-cap me-1"></i>All Graduate
      </button>
      <button type="button" class="btn btn-sm btn-outline-warning" onclick="setAllDecision('retained')">
        <i class="fas fa-redo me-1"></i>All Retain
      </button>
    </div>
  </div>
  <form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="promote">
    <input type="hidden" name="academic_year_id" value="<?= $fYear ?>">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th class="ps-3" style="width:30px">#</th>
              <th>Student</th>
              <th>Adm No</th>
              <th>Curriculum</th>
              <th style="width:165px">Decision</th>
              <th style="width:195px">Promote To</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($students as $i => $st): ?>
          <tr>
            <td class="ps-3 text-muted small"><?= $i+1 ?></td>
            <td class="fw-semibold"><?= e($st['first_name'].' '.$st['last_name']) ?></td>
            <td class="text-muted small"><?= e($st['admission_no'] ?? '—') ?></td>
            <td><span class="badge bg-light text-dark border small"><?= e($st['curriculum'] ?: 'General') ?></span></td>
            <td>
              <select name="decision[<?= $st['id'] ?>][type]"
                      class="form-select form-select-sm decision-select"
                      data-student="<?= $st['id'] ?>"
                      onchange="toggleToClass(this)">
                <option value="promoted">Promoted</option>
                <option value="graduated">Graduated</option>
                <option value="retained">Retained</option>
                <option value="transferred">Transferred</option>
              </select>
            </td>
            <td>
              <select name="decision[<?= $st['id'] ?>][to_class_id]"
                      class="form-select form-select-sm"
                      id="toClass_<?= $st['id'] ?>">
                <option value="">— same class —</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $c['id']==$fClass?'':'selected' ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td>
              <input type="text" name="notes[<?= $st['id'] ?>]"
                     class="form-control form-control-sm" placeholder="Optional…">
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <div class="card-footer d-flex align-items-center justify-content-between">
      <span class="text-muted small">
        <i class="fas fa-exclamation-triangle text-warning me-1"></i>
        This action will update student records. Review carefully before applying.
      </span>
      <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"
              onclick="return confirm('Apply promotion decisions for <?= count($students) ?> students?')">
        <i class="fas fa-check-circle me-2"></i>Apply Promotion Decisions
      </button>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Promotion History -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-history me-2 text-muted"></i>Promotion History</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable small">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Student</th>
            <th>Adm No</th>
            <th>Academic Year</th>
            <th>From Class</th>
            <th>To Class</th>
            <th class="text-center">Decision</th>
            <th>Processed By</th>
            <th>Date</th>
            <th>Notes</th>
          </tr>
        </thead>
        <tbody>
        <?php if (empty($history)): ?>
        <tr><td colspan="9" class="text-center text-muted py-4">No promotion records found.</td></tr>
        <?php else: foreach ($history as $h): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= e($h['first_name'].' '.$h['last_name']) ?></td>
          <td class="text-muted"><?= e($h['admission_no']) ?></td>
          <td><?= e($h['academic_year'] ?? '—') ?></td>
          <td><?= e($h['from_class'] ?? '—') ?></td>
          <td><?= e($h['to_class'] ?? '—') ?></td>
          <td class="text-center">
            <span class="badge bg-<?= $typeColors[$h['promotion_type']] ?? 'secondary' ?>">
              <i class="fas <?= $typeIcons[$h['promotion_type']] ?? 'fa-circle' ?> me-1"></i>
              <?= ucfirst($h['promotion_type']) ?>
            </span>
          </td>
          <td><?= e($h['promoted_by_name'] ?? '—') ?></td>
          <td class="text-muted"><?= formatDate($h['created_at']) ?></td>
          <td class="text-muted"><?= e($h['notes'] ?? '—') ?></td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function toggleToClass(select) {
    const studentId = select.getAttribute('data-student');
    const toClass = document.getElementById('toClass_' + studentId);
    const type = select.value;
    toClass.disabled = (type === 'graduated' || type === 'retained' || type === 'transferred');
    if (toClass.disabled) toClass.value = '';
}
function setAllDecision(type) {
    document.querySelectorAll('.decision-select').forEach(sel => {
        sel.value = type;
        toggleToClass(sel);
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
