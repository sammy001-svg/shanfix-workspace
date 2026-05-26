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
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $studentId    = (int)($_POST['student_id'] ?? 0);
        $incidentDate = $_POST['incident_date'] ?? date('Y-m-d');
        $category     = sanitize($_POST['category'] ?? 'behaviour');
        $severity     = in_array($_POST['severity']??'',['minor','moderate','serious','critical'])?$_POST['severity']:'minor';
        $description  = sanitize($_POST['description'] ?? '');
        $witnesses    = sanitize($_POST['witnesses'] ?? '');
        $action_taken = sanitize($_POST['action_taken'] ?? '');
        $suspension   = max(0,(int)($_POST['suspension_days']??0));
        $parentNotif  = (int)($_POST['parent_notified']??0);
        $followUp     = $_POST['follow_up_date'] ?: null;
        $followUpNotes= sanitize($_POST['follow_up_notes'] ?? '');
        $status       = in_array($_POST['status']??'',['open','under-review','resolved','appealed','dismissed'])?$_POST['status']:'open';
        $termId       = (int)($_POST['term_id']??0)?:null;

        if (!$studentId || !$description) { setFlash('danger','Student and incident description are required.'); redirect('discipline.php'); }
        assertOrgOwnership('sch_students', $studentId, $orgId);

        $resolvedAt = ($status === 'resolved' || $status === 'dismissed') ? date('Y-m-d H:i:s') : null;

        if ($id > 0) {
            requireOrgOwnership('sch_disciplinary', $id, $orgId);
            $pdo->prepare("UPDATE sch_disciplinary SET student_id=?,term_id=?,incident_date=?,category=?,severity=?,description=?,witnesses=?,action_taken=?,suspension_days=?,parent_notified=?,follow_up_date=?,follow_up_notes=?,status=?,resolved_at=?,reported_by=? WHERE id=? AND org_id=?")
                ->execute([$studentId,$termId,$incidentDate,$category,$severity,$description,$witnesses,$action_taken,$suspension,$parentNotif,$followUp,$followUpNotes,$status,$resolvedAt,$user['id'],$id,$orgId]);
            setFlash('success','Incident record updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_disciplinary (org_id,student_id,term_id,reported_by,incident_date,category,severity,description,witnesses,action_taken,suspension_days,parent_notified,follow_up_date,follow_up_notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$studentId,$termId,$user['id'],$incidentDate,$category,$severity,$description,$witnesses,$action_taken,$suspension,$parentNotif,$followUp,$followUpNotes,$status]);
            setFlash('success','Incident recorded.');
        }
        logActivity('create','school',"Disciplinary: student #$studentId, $severity $category");
        redirect('discipline.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id']??0);
        requireOrgOwnership('sch_disciplinary', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_disciplinary WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Record deleted.'); redirect('discipline.php');
    }

    if ($action === 'mark_notified') {
        $id = (int)($_POST['id']??0);
        requireOrgOwnership('sch_disciplinary', $id, $orgId);
        $pdo->prepare("UPDATE sch_disciplinary SET parent_notified=1, parent_notified_at=NOW() WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Marked as parent notified.'); redirect('discipline.php');
    }
}

// AJAX
if (isset($_GET['fetch'])) {
    $r=$pdo->prepare("SELECT * FROM sch_disciplinary WHERE id=? AND org_id=?");$r->execute([(int)$_GET['fetch'],$orgId]);
    header('Content-Type: application/json');echo json_encode($r->fetch()?:[]);exit;
}

// ── Load Data ─────────────────────────────────────────────────────
$fStatus   = sanitize($_GET['status'] ?? '');
$fSeverity = sanitize($_GET['severity'] ?? '');
$fSearch   = sanitize($_GET['q'] ?? '');
$where     = 'd.org_id=?'; $params = [$orgId];
if ($fStatus)   { $where .= ' AND d.status=?'; $params[] = $fStatus; }
if ($fSeverity) { $where .= ' AND d.severity=?'; $params[] = $fSeverity; }
if ($fSearch)   { $where .= ' AND (st.first_name LIKE ? OR st.last_name LIKE ? OR d.description LIKE ?)'; $q="%$fSearch%"; array_push($params,$q,$q,$q); }

$records = [];
try {
    $stmt=$pdo->prepare("SELECT d.*,CONCAT(st.first_name,' ',st.last_name) AS student_name,st.admission_no,c.name AS class_name,u.name AS reported_by_name FROM sch_disciplinary d JOIN sch_students st ON d.student_id=st.id LEFT JOIN sch_classes c ON st.class_id=c.id LEFT JOIN users u ON d.reported_by=u.id WHERE $where ORDER BY d.incident_date DESC");
    $stmt->execute($params);$records=$stmt->fetchAll();
} catch(Exception $e){}

$studentsList=[];
try{$s=$pdo->prepare("SELECT id,admission_no,first_name,last_name FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$studentsList=$s->fetchAll();}catch(Exception $e){}

$terms=[];
try{$s=$pdo->prepare("SELECT id,name FROM sch_terms WHERE org_id=? ORDER BY start_date DESC");$s->execute([$orgId]);$terms=$s->fetchAll();}catch(Exception $e){}

// Stats
$statOpen    = count(array_filter($records, fn($r)=>$r['status']==='open'));
$statReview  = count(array_filter($records, fn($r)=>$r['status']==='under-review'));
$statResolved= count(array_filter($records, fn($r)=>$r['status']==='resolved'));
$statCritical= count(array_filter($records, fn($r)=>$r['severity']==='critical' && $r['status']!=='resolved'));

$categories = ['behaviour','attendance','uniform','bullying','academic-dishonesty','substance','property-damage','cyberbullying','other'];
$severityColors = ['minor'=>'info','moderate'=>'warning','serious'=>'danger','critical'=>'dark'];
$statusColors   = ['open'=>'warning','under-review'=>'primary','resolved'=>'success','appealed'=>'danger','dismissed'=>'secondary'];

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-gavel me-2" style="color:<?=$moduleColor?>"></i>Disciplinary Records</h4>
    <p class="text-muted mb-0">Log, track and resolve student disciplinary incidents</p>
  </div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#discModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Log Incident</button>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-folder-open"></i></div><div class="stat-body"><div class="stat-value"><?=$statOpen?></div><div class="stat-label">Open Cases</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-search"></i></div><div class="stat-body"><div class="stat-value"><?=$statReview?></div><div class="stat-label">Under Review</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$statResolved?></div><div class="stat-label">Resolved</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div><div class="stat-body"><div class="stat-value"><?=$statCritical?></div><div class="stat-label">Critical (Active)</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search Student / Description</label><input type="text" name="q" class="form-control form-control-sm" value="<?=e($fSearch)?>" placeholder="Student name or keywords…"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach(['open','under-review','resolved','appealed','dismissed'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst(str_replace('-',' ',$s))?></option><?php endforeach;?></select></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Severity</label><select name="severity" class="form-select form-select-sm"><option value="">All</option><?php foreach(['minor','moderate','serious','critical'] as $s):?><option value="<?=$s?>" <?=$fSeverity===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button> <a href="discipline.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<!-- Records Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2 text-success"></i>Incident Records (<?=count($records)?>)</h6></div>
  <div class="card-body p-0">
  <div class="table-responsive">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Date</th><th>Student</th><th>Category</th><th>Severity</th><th>Action Taken</th><th>Parent Notified</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($records)): ?>
    <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-balance-scale fa-3x mb-2 opacity-25 d-block"></i>No incident records found.</td></tr>
    <?php else: foreach($records as $rec): ?>
    <tr>
      <td><?=formatDate($rec['incident_date'])?></td>
      <td>
        <div class="fw-semibold"><?=e($rec['student_name'])?></div>
        <small class="text-muted"><?=e($rec['admission_no'])?> · <?=e($rec['class_name']??'—')?></small>
      </td>
      <td><span class="badge bg-light text-dark border"><?=ucfirst(str_replace('-',' ',$rec['category']))?></span></td>
      <td><span class="badge bg-<?=$severityColors[$rec['severity']]??'secondary'?>"><?=ucfirst($rec['severity'])?></span>
        <?php if($rec['suspension_days']>0):?><br><small class="text-danger"><?=$rec['suspension_days']?> day(s) suspended</small><?php endif;?>
      </td>
      <td class="small"><?=e(mb_strimwidth($rec['action_taken']??'—',0,60,'…'))?></td>
      <td class="text-center">
        <?php if($rec['parent_notified']): ?>
        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Yes</span>
        <div class="small text-muted"><?=$rec['parent_notified_at']?formatDate($rec['parent_notified_at']):''?></div>
        <?php else: ?>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="mark_notified"><input type="hidden" name="id" value="<?=$rec['id']?>"><button class="btn btn-sm btn-outline-warning" type="submit"><i class="fas fa-bell me-1"></i>Mark Notified</button></form>
        <?php endif; ?>
      </td>
      <td><span class="badge bg-<?=$statusColors[$rec['status']]??'secondary'?>"><?=ucfirst(str_replace('-',' ',$rec['status']))?></span></td>
      <td class="text-center">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" onclick="openEdit(<?=$rec['id']?>)" data-bs-toggle="modal" data-bs-target="#discModal" title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-outline-danger" onclick="delRecord(<?=$rec['id']?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<!-- Incident Modal -->
<div class="modal fade" id="discModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="discId" value="0">
  <div class="modal-header text-white" style="background:<?=$moduleColor?>"><h5 class="modal-title" id="discTitle"><i class="fas fa-gavel me-2"></i>Log Incident</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6">
      <label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
      <select name="student_id" id="discStudentId" class="form-select select2-enable" required>
        <option value="">— select student —</option>
        <?php foreach($studentsList as $s):?><option value="<?=$s['id']?>"><?=e($s['first_name'].' '.$s['last_name'])?> (<?=e($s['admission_no'])?>)</option><?php endforeach;?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label fw-semibold">Incident Date</label><input type="date" name="incident_date" id="discDate" class="form-control" value="<?=date('Y-m-d')?>"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Term</label>
      <select name="term_id" id="discTermId" class="form-select"><option value="">—</option><?php foreach($terms as $t):?><option value="<?=$t['id']?>"><?=e($t['name'])?></option><?php endforeach;?></select>
    </div>
    <div class="col-md-6"><label class="form-label fw-semibold">Category</label>
      <select name="category" id="discCat" class="form-select"><?php foreach($categories as $c):?><option value="<?=$c?>"><?=ucwords(str_replace('-',' ',$c))?></option><?php endforeach;?></select>
    </div>
    <div class="col-md-3"><label class="form-label fw-semibold">Severity</label>
      <select name="severity" id="discSev" class="form-select"><?php foreach(['minor','moderate','serious','critical'] as $s):?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach;?></select>
    </div>
    <div class="col-md-3"><label class="form-label fw-semibold">Suspension Days</label><input type="number" name="suspension_days" id="discSuspDays" class="form-control" min="0" value="0"></div>
    <div class="col-12"><label class="form-label fw-semibold">Description <span class="text-danger">*</span></label><textarea name="description" id="discDesc" class="form-control" rows="3" required placeholder="Detailed account of the incident…"></textarea></div>
    <div class="col-12"><label class="form-label fw-semibold">Witnesses</label><input type="text" name="witnesses" id="discWitnesses" class="form-control" placeholder="Names of witnesses if any…"></div>
    <div class="col-12"><label class="form-label fw-semibold">Action Taken</label><textarea name="action_taken" id="discAction" class="form-control" rows="2" placeholder="Actions taken: verbal warning, detention, suspension…"></textarea></div>
    <div class="col-12"><label class="form-label fw-semibold">Follow-Up Notes</label><textarea name="follow_up_notes" id="discFollowNotes" class="form-control" rows="2" placeholder="Follow-up observations or progress…"></textarea></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Follow-Up Date</label><input type="date" name="follow_up_date" id="discFollowDate" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="discStatus" class="form-select"><?php foreach(['open','under-review','resolved','appealed','dismissed'] as $s):?><option value="<?=$s?>"><?=ucfirst(str_replace('-',' ',$s))?></option><?php endforeach;?></select>
    </div>
    <div class="col-md-4 d-flex align-items-end"><div class="form-check mb-2"><input type="checkbox" name="parent_notified" id="discParentNotif" value="1" class="form-check-input"><label class="form-check-label fw-semibold" for="discParentNotif">Parent Notified</label></div></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-1"></i>Save Record</button></div>
  </form>
</div></div></div>

<form method="POST" id="delDiscForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delDiscId"></form>
<?php ob_start(); ?>
<script>
function openAdd(){document.getElementById('discTitle').innerHTML='<i class="fas fa-gavel me-2"></i>Log Incident';document.getElementById('discId').value='0';['discDate','discDesc','discWitnesses','discAction','discFollowNotes','discFollowDate'].forEach(f=>{const el=document.getElementById(f);if(el)el.value='';});document.getElementById('discDate').value=new Date().toISOString().split('T')[0];document.getElementById('discStudentId').value='';document.getElementById('discCat').value='behaviour';document.getElementById('discSev').value='minor';document.getElementById('discStatus').value='open';document.getElementById('discSuspDays').value='0';document.getElementById('discParentNotif').checked=false;}
function openEdit(id){fetch('discipline.php?fetch='+id).then(r=>r.json()).then(d=>{document.getElementById('discTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Incident Record';document.getElementById('discId').value=d.id;document.getElementById('discStudentId').value=d.student_id;document.getElementById('discDate').value=d.incident_date;document.getElementById('discTermId').value=d.term_id||'';document.getElementById('discCat').value=d.category;document.getElementById('discSev').value=d.severity;document.getElementById('discSuspDays').value=d.suspension_days||0;document.getElementById('discDesc').value=d.description;document.getElementById('discWitnesses').value=d.witnesses||'';document.getElementById('discAction').value=d.action_taken||'';document.getElementById('discFollowNotes').value=d.follow_up_notes||'';document.getElementById('discFollowDate').value=d.follow_up_date||'';document.getElementById('discStatus').value=d.status;document.getElementById('discParentNotif').checked=d.parent_notified=='1';new bootstrap.Modal(document.getElementById('discModal')).show();});}
function delRecord(id){if(confirm('Permanently delete this disciplinary record?')){document.getElementById('delDiscId').value=id;document.getElementById('delDiscForm').submit();}}
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
