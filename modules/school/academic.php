<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
require_once __DIR__ . '/_nav.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_year') {
        $id       = (int)($_POST['id'] ?? 0);
        $yearName = sanitize($_POST['year_name'] ?? '');
        $start    = $_POST['start_date'] ?? '';
        $end      = $_POST['end_date'] ?? '';
        $isCurrent= (int)($_POST['is_current'] ?? 0);
        if (!$yearName || !$start || !$end) { setFlash('danger','Year name and dates required.'); redirect('academic.php'); }
        if ($isCurrent) $pdo->prepare("UPDATE sch_academic_years SET is_current=0 WHERE org_id=?")->execute([$orgId]);
        if ($id > 0) {
            requireOrgOwnership('sch_academic_years', $id, $orgId);
            $pdo->prepare("UPDATE sch_academic_years SET name=?,start_date=?,end_date=?,is_current=? WHERE id=? AND org_id=?")->execute([$yearName,$start,$end,$isCurrent,$id,$orgId]);
            setFlash('success','Academic year updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_academic_years (org_id,name,start_date,end_date,is_current) VALUES (?,?,?,?,?)")->execute([$orgId,$yearName,$start,$end,$isCurrent]);
            setFlash('success',"Academic year '$yearName' created.");
        }
        redirect('academic.php');
    }

    if ($action === 'save_term') {
        $id       = (int)($_POST['id'] ?? 0);
        $yearId   = (int)($_POST['academic_year_id'] ?? 0) ?: null;
        $name     = sanitize($_POST['term_name'] ?? '');
        $type     = in_array($_POST['term_type']??'',['term','semester','quarter','trimester'])?$_POST['term_type']:'term';
        $start    = $_POST['start_date'] ?? '';
        $end      = $_POST['end_date'] ?? '';
        $isCurrent= (int)($_POST['is_current'] ?? 0);
        $status   = in_array($_POST['status']??'',['upcoming','active','completed'])?$_POST['status']:'upcoming';
        $notes    = sanitize($_POST['notes'] ?? '');
        if (!$name || !$start || !$end) { setFlash('danger','Term name and dates required.'); redirect('academic.php'); }
        if ($isCurrent) { $pdo->prepare("UPDATE sch_terms SET is_current=0 WHERE org_id=?")->execute([$orgId]); $status='active'; }
        if ($id > 0) {
            requireOrgOwnership('sch_terms', $id, $orgId);
            $pdo->prepare("UPDATE sch_terms SET academic_year_id=?,name=?,term_type=?,start_date=?,end_date=?,is_current=?,status=?,notes=? WHERE id=? AND org_id=?")->execute([$yearId,$name,$type,$start,$end,$isCurrent,$status,$notes,$id,$orgId]);
            setFlash('success','Term updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_terms (org_id,academic_year_id,name,term_type,start_date,end_date,is_current,status,notes) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$orgId,$yearId,$name,$type,$start,$end,$isCurrent,$status,$notes]);
            setFlash('success',"Term '$name' created.");
        }
        redirect('academic.php');
    }

    if ($action === 'delete_year') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_academic_years', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_terms WHERE academic_year_id=? AND org_id=?")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_academic_years WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Academic year and its terms deleted.'); redirect('academic.php');
    }

    if ($action === 'delete_term') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_terms', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_terms WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Term deleted.'); redirect('academic.php');
    }

    if ($action === 'set_current_term') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_terms', $id, $orgId);
        $pdo->prepare("UPDATE sch_terms SET is_current=0 WHERE org_id=?")->execute([$orgId]);
        $pdo->prepare("UPDATE sch_terms SET is_current=1, status='active' WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Current term updated.'); redirect('academic.php');
    }
}

// ── AJAX fetch ────────────────────────────────────────────────────
if (isset($_GET['fetch_year'])) {
    $r=$pdo->prepare("SELECT * FROM sch_academic_years WHERE id=? AND org_id=?");$r->execute([(int)$_GET['fetch_year'],$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch()?:[]); exit;
}
if (isset($_GET['fetch_term'])) {
    $r=$pdo->prepare("SELECT * FROM sch_terms WHERE id=? AND org_id=?");$r->execute([(int)$_GET['fetch_term'],$orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch()?:[]); exit;
}

// ── Load data ─────────────────────────────────────────────────────
$years = []; $terms = [];
try {
    $stmt=$pdo->prepare("SELECT * FROM sch_academic_years WHERE org_id=? ORDER BY start_date DESC"); $stmt->execute([$orgId]); $years=$stmt->fetchAll();
} catch(Exception $e){}
try {
    $stmt=$pdo->prepare("SELECT t.*, y.name as year_name FROM sch_terms t LEFT JOIN sch_academic_years y ON t.academic_year_id=y.id WHERE t.org_id=? ORDER BY t.start_date DESC"); $stmt->execute([$orgId]); $terms=$stmt->fetchAll();
} catch(Exception $e){}

$currentTerm = null;
foreach ($terms as $t) { if ($t['is_current']) { $currentTerm = $t; break; } }

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?=$moduleColor?>"></i>Academic Year & Term Management</h4>
    <p class="text-muted mb-0">Manage academic years, terms/semesters and set the current active term</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#termModal" onclick="openAddTerm()"><i class="fas fa-plus me-2"></i>Add Term</button>
    <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#yearModal" onclick="openAddYear()"><i class="fas fa-plus me-2"></i>Add Year</button>
  </div>
</div>

<!-- Current Term Banner -->
<?php if ($currentTerm): ?>
<div class="alert border-start border-success border-4 bg-light mb-4 d-flex align-items-center gap-3">
  <i class="fas fa-calendar-check fa-2x text-success"></i>
  <div>
    <div class="fw-bold fs-5">📍 Current Term: <?=e($currentTerm['name'])?></div>
    <div class="text-muted small"><?=formatDate($currentTerm['start_date'])?> → <?=formatDate($currentTerm['end_date'])?> · <?=ucfirst($currentTerm['term_type'])?> · <span class="badge bg-success">ACTIVE</span></div>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning mb-4"><i class="fas fa-exclamation-triangle me-2"></i><strong>No active term set.</strong> Click the <em>Set as Current</em> button on a term to activate it.</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Academic Years -->
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-graduation-cap me-2 text-success"></i>Academic Years</h6>
        <span class="badge bg-secondary"><?=count($years)?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
          <thead class="table-light"><tr><th>Year</th><th>Dates</th><th>Current</th><th class="text-center">Actions</th></tr></thead>
          <tbody>
          <?php if (empty($years)): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">No academic years added.</td></tr>
          <?php else: foreach ($years as $y): ?>
          <tr>
            <td class="fw-semibold"><?=e($y['name'])?></td>
            <td class="small text-muted"><?=formatDate($y['start_date'])?><br><?=formatDate($y['end_date'])?></td>
            <td><?=$y['is_current']?'<span class="badge bg-success">Current</span>':'<span class="badge bg-secondary">—</span>'?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="editYear(<?=$y['id']?>)" data-bs-toggle="modal" data-bs-target="#yearModal" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delYear(<?=$y['id']?>,'<?=e($y['name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Terms -->
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-list-ol me-2 text-success"></i>Terms / Semesters</h6>
        <span class="badge bg-secondary"><?=count($terms)?></span>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover data-table mb-0">
          <thead class="table-light"><tr><th>Term Name</th><th>Year</th><th>Dates</th><th>Type</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
          <tbody>
          <?php if (empty($terms)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No terms added.</td></tr>
          <?php else: foreach ($terms as $t):
            $stColors=['upcoming'=>'info','active'=>'success','completed'=>'secondary'];
          ?>
          <tr>
            <td><div class="fw-semibold"><?=e($t['name'])?></div><?=$t['is_current']?'<span class="badge bg-success">📍 Current</span>':''?></td>
            <td class="small text-muted"><?=e($t['year_name']??'—')?></td>
            <td class="small"><?=formatDate($t['start_date'])?><br><span class="text-muted"><?=formatDate($t['end_date'])?></span></td>
            <td><span class="badge bg-light text-dark border"><?=ucfirst($t['term_type'])?></span></td>
            <td><span class="badge bg-<?=$stColors[$t['status']]??'secondary'?>"><?=ucfirst($t['status'])?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <?php if (!$t['is_current']): ?><form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="set_current_term"><input type="hidden" name="id" value="<?=$t['id']?>"><button class="btn btn-outline-success" type="submit" title="Set as Current"><i class="fas fa-check-circle"></i></button></form><?php endif; ?>
                <button class="btn btn-outline-primary" onclick="editTerm(<?=$t['id']?>)" data-bs-toggle="modal" data-bs-target="#termModal" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delTerm(<?=$t['id']?>,'<?=e($t['name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Academic Year Modal -->
<div class="modal fade" id="yearModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save_year"><input type="hidden" name="id" id="yearId" value="0">
  <div class="modal-header text-white" style="background:<?=$moduleColor?>"><h5 class="modal-title" id="yearTitle"><i class="fas fa-graduation-cap me-2"></i>Add Academic Year</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Year Name <span class="text-danger">*</span></label><input type="text" name="year_name" id="yearName" class="form-control" required placeholder="e.g. 2025/2026, 2026"></div>
    <div class="col-6"><label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" id="yearStart" class="form-control" required></div>
    <div class="col-6"><label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" id="yearEnd" class="form-control" required></div>
    <div class="col-12"><div class="form-check"><input type="checkbox" name="is_current" id="yearCurrent" value="1" class="form-check-input"><label class="form-check-label" for="yearCurrent">Set as current academic year</label></div></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-1"></i>Save Year</button></div>
  </form>
</div></div></div>

<!-- Term Modal -->
<div class="modal fade" id="termModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save_term"><input type="hidden" name="id" id="termId" value="0">
  <div class="modal-header text-white" style="background:<?=$moduleColor?>"><h5 class="modal-title" id="termTitle"><i class="fas fa-calendar me-2"></i>Add Term</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Academic Year</label>
      <select name="academic_year_id" id="termYearId" class="form-select"><option value="">— Not linked —</option><?php foreach($years as $y):?><option value="<?=$y['id']?>"><?=e($y['name'])?></option><?php endforeach;?></select>
    </div>
    <div class="col-8"><label class="form-label fw-semibold">Term Name <span class="text-danger">*</span></label><input type="text" name="term_name" id="termName" class="form-control" required placeholder="e.g. Term 1 2025/26"></div>
    <div class="col-4"><label class="form-label fw-semibold">Type</label>
      <select name="term_type" id="termType" class="form-select"><?php foreach(['term','semester','quarter','trimester'] as $t):?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach;?></select>
    </div>
    <div class="col-6"><label class="form-label fw-semibold">Start Date</label><input type="date" name="start_date" id="termStart" class="form-control" required></div>
    <div class="col-6"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="termEnd" class="form-control" required></div>
    <div class="col-12"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="termStatus" class="form-select"><?php foreach(['upcoming','active','completed'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?></select>
    </div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label><input type="text" name="notes" id="termNotes" class="form-control" placeholder="Optional notes…"></div>
    <div class="col-12"><div class="form-check"><input type="checkbox" name="is_current" id="termCurrent" value="1" class="form-check-input"><label class="form-check-label" for="termCurrent">Set as current term (will deactivate other terms)</label></div></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-1"></i>Save Term</button></div>
  </form>
</div></div></div>

<form method="POST" id="delYearForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete_year"><input type="hidden" name="id" id="delYearId"></form>
<form method="POST" id="delTermForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete_term"><input type="hidden" name="id" id="delTermId"></form>

<?php ob_start(); ?>
<script>
function openAddYear(){document.getElementById('yearTitle').innerHTML='<i class="fas fa-graduation-cap me-2"></i>Add Academic Year';['yearId','yearName','yearStart','yearEnd'].forEach(f=>document.getElementById(f).value='');document.getElementById('yearCurrent').checked=false;}
function editYear(id){fetch('academic.php?fetch_year='+id).then(r=>r.json()).then(d=>{document.getElementById('yearTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Academic Year';document.getElementById('yearId').value=d.id;document.getElementById('yearName').value=d.name;document.getElementById('yearStart').value=d.start_date;document.getElementById('yearEnd').value=d.end_date;document.getElementById('yearCurrent').checked=d.is_current=='1';});}
function openAddTerm(){document.getElementById('termTitle').innerHTML='<i class="fas fa-calendar me-2"></i>Add Term';['termId','termName','termStart','termEnd','termNotes'].forEach(f=>document.getElementById(f).value='');document.getElementById('termYearId').value='';document.getElementById('termType').value='term';document.getElementById('termStatus').value='upcoming';document.getElementById('termCurrent').checked=false;}
function editTerm(id){fetch('academic.php?fetch_term='+id).then(r=>r.json()).then(d=>{document.getElementById('termTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Term';document.getElementById('termId').value=d.id;document.getElementById('termYearId').value=d.academic_year_id||'';document.getElementById('termName').value=d.name;document.getElementById('termType').value=d.term_type;document.getElementById('termStart').value=d.start_date;document.getElementById('termEnd').value=d.end_date;document.getElementById('termStatus').value=d.status;document.getElementById('termNotes').value=d.notes||'';document.getElementById('termCurrent').checked=d.is_current=='1';});}
function delYear(id,name){if(confirm('Delete academic year "'+name+'" and ALL its terms?')){document.getElementById('delYearId').value=id;document.getElementById('delYearForm').submit();}}
function delTerm(id,name){if(confirm('Delete term "'+name+'"?')){document.getElementById('delTermId').value=id;document.getElementById('delTermForm').submit();}}
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
