<?php
$moduleSlug  = 'meetings';
$moduleName  = 'Meetings & Minutes';
$moduleIcon  = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'agenda.php',      'icon' => 'fas fa-list-ul',         'label' => 'Agenda'],
    ['url' => 'recordings.php',  'icon' => 'fas fa-microphone',      'label' => 'Recordings'],
    ['url' => 'documents.php',   'icon' => 'fas fa-folder-open',     'label' => 'Documents'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $meetingId   = (int)($_POST['meeting_id'] ?? 0) ?: null;
        $title       = sanitize($_POST['title'] ?? '');
        $docType     = in_array($_POST['doc_type'] ?? '', ['agenda','minutes','report','presentation','spreadsheet','contract','other']) ? $_POST['doc_type'] : 'other';
        $url         = sanitize($_POST['document_url'] ?? '');
        $uploadedBy  = sanitize($_POST['uploaded_by'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $access      = in_array($_POST['access_level'] ?? '', ['public','internal','restricted']) ? $_POST['access_level'] : 'internal';
        if (!$title) { setFlash('error', 'Document title is required.'); redirect('documents.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE meeting_documents SET meeting_id=?,title=?,doc_type=?,document_url=?,uploaded_by=?,description=?,access_level=? WHERE id=? AND org_id=?")
                ->execute([$meetingId,$title,$docType,$url,$uploadedBy,$description,$access,$id,$orgId]);
            setFlash('success', 'Document updated.');
        } else {
            $pdo->prepare("INSERT INTO meeting_documents(org_id,meeting_id,title,doc_type,document_url,uploaded_by,description,access_level)VALUES(?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$meetingId,$title,$docType,$url,$uploadedBy,$description,$access]);
            setFlash('success', "Document '$title' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'meetings', "Document: $title");
        redirect('documents.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_documents WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Document removed.'); redirect('documents.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fMeeting = (int)($_GET['meeting_id'] ?? 0);
$fType    = $_GET['type'] ?? '';

$where = 'd.org_id=?'; $params = [$orgId];
if ($fMeeting) { $where .= ' AND d.meeting_id=?'; $params[] = $fMeeting; }
if ($fType)    { $where .= ' AND d.doc_type=?'; $params[] = $fType; }

$docs = [];
try {
    $s = $pdo->prepare("SELECT d.*,m.title AS meeting_title FROM meeting_documents d LEFT JOIN meetings m ON d.meeting_id=m.id WHERE $where ORDER BY d.created_at DESC");
    $s->execute($params); $docs = $s->fetchAll();
} catch (Exception $e) {}

$meetings = [];
try { $s = $pdo->prepare("SELECT id,title,meeting_date FROM meetings WHERE org_id=? ORDER BY meeting_date DESC LIMIT 50"); $s->execute([$orgId]); $meetings = $s->fetchAll(); } catch (Exception $e) {}

$totalDocs = 0;
try { $s = $pdo->prepare("SELECT COUNT(*) FROM meeting_documents WHERE org_id=?"); $s->execute([$orgId]); $totalDocs=(int)$s->fetchColumn(); } catch (Exception $e) {}

$typeIcons  = ['agenda'=>'fa-list-ol','minutes'=>'fa-file-alt','report'=>'fa-chart-bar','presentation'=>'fa-file-powerpoint','spreadsheet'=>'fa-file-excel','contract'=>'fa-file-contract','other'=>'fa-file'];
$typeColors = ['agenda'=>'primary','minutes'=>'success','report'=>'warning','presentation'=>'danger','spreadsheet'=>'info','contract'=>'dark','other'=>'secondary'];
$accessColors = ['public'=>'success','internal'=>'warning','restricted'=>'danger'];
$docTypes   = ['agenda'=>'Agenda','minutes'=>'Minutes','report'=>'Report','presentation'=>'Presentation','spreadsheet'=>'Spreadsheet','contract'=>'Contract','other'=>'Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-folder-open me-2" style="color:<?=$moduleColor?>"></i>Documents</h4>
    <p class="text-muted mb-0">Manage meeting reference documents and attachments</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#docModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Document
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e8eaf6;color:<?=$moduleColor?>"><i class="fas fa-folder-open"></i></div><div class="stat-body"><div class="stat-value"><?=$totalDocs?></div><div class="stat-label">Total Documents</div></div></div></div>
  <?php
  $typeCounts = array_count_values(array_column($docs,'doc_type'));
  $topTypes = array_slice(arsort($typeCounts) ? $typeCounts : $typeCounts, 0, 3, true);
  foreach (array_slice(array_keys($typeColors), 0, 3) as $tc):
    $cnt = $typeCounts[$tc] ?? 0;
  ?>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas <?=$typeIcons[$tc]??'fa-file'?>"></i></div><div class="stat-body"><div class="stat-value"><?=$cnt?></div><div class="stat-label"><?=$docTypes[$tc]?> Docs</div></div></div></div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Meeting</label>
      <select name="meeting_id" class="form-select form-select-sm">
        <option value="">All Meetings</option>
        <?php foreach ($meetings as $m): ?><option value="<?=$m['id']?>" <?=$fMeeting==$m['id']?'selected':''?>><?=e($m['title'])?> (<?=formatDate($m['meeting_date'])?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach ($docTypes as $k=>$v): ?><option value="<?=$k?>" <?=$fType===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="documents.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-folder-open me-2" style="color:<?=$moduleColor?>"></i>Document Library</h6>
    <span class="badge bg-secondary"><?=count($docs)?> documents</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Type</th><th>Title</th><th>Meeting</th><th>Uploaded By</th><th>Date</th><th>Access</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($docs)): ?>
      <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-folder-open fa-2x mb-2 d-block"></i>No documents added yet.</td></tr>
    <?php else: foreach ($docs as $doc): ?>
      <tr>
        <td><span class="badge bg-<?=$typeColors[$doc['doc_type']]??'secondary'?>"><i class="fas <?=$typeIcons[$doc['doc_type']]??'fa-file'?> me-1"></i><?=$docTypes[$doc['doc_type']]??e($doc['doc_type'])?></span></td>
        <td>
          <?php if ($doc['document_url']): ?>
            <a href="<?=e($doc['document_url'])?>" target="_blank" class="fw-semibold text-decoration-none"><?=e($doc['title'])?> <i class="fas fa-external-link-alt fa-xs ms-1 text-muted"></i></a>
          <?php else: ?>
            <span class="fw-semibold"><?=e($doc['title'])?></span>
          <?php endif; ?>
          <?=$doc['description']?'<br><small class="text-muted">'.e(mb_substr($doc['description'],0,60)).'</small>':''?>
        </td>
        <td class="small"><?=e($doc['meeting_title']??'General')?></td>
        <td class="small"><?=e($doc['uploaded_by']??'—')?></td>
        <td class="small"><?=$doc['created_at']?formatDate(substr($doc['created_at'],0,10)):'—'?></td>
        <td><span class="badge bg-<?=$accessColors[$doc['access_level']]??'secondary'?>"><?=ucfirst($doc['access_level']??'')?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($doc),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delDoc(<?=$doc['id']?>,<?=json_encode($doc['title'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="docModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="dId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="dTitle"><i class="fas fa-folder-open me-2"></i>Add Document</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Document Title <span class="text-danger">*</span></label>
      <input type="text" name="title" id="dDocTitle" class="form-control" required maxlength="255"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Document Type</label>
      <select name="doc_type" id="dType" class="form-select">
        <?php foreach ($docTypes as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Meeting</label>
      <select name="meeting_id" id="dMeeting" class="form-select">
        <option value="">— General —</option>
        <?php foreach ($meetings as $m): ?><option value="<?=$m['id']?>"><?=e($m['title'])?> (<?=formatDate($m['meeting_date'])?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Access Level</label>
      <select name="access_level" id="dAccess" class="form-select">
        <?php foreach (array_keys($accessColors) as $a): ?><option value="<?=$a?>" <?=$a==='internal'?'selected':''?>><?=ucfirst($a)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Document URL / Link</label>
      <input type="url" name="document_url" id="dUrl" class="form-control" placeholder="https://drive.google.com/…"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Uploaded By</label>
      <input type="text" name="uploaded_by" id="dBy" class="form-control" maxlength="150"></div>
    <div class="col-12"><label class="form-label fw-semibold">Description</label>
      <textarea name="description" id="dDesc" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Document</button>
  </div></form>
</div></div></div>
<form method="POST" id="delDocForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delDocId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('dTitle').innerHTML='<i class="fas fa-folder-open me-2"></i>Add Document';
  document.getElementById('dId').value='0';
  document.getElementById('dDocTitle').value='';
  document.getElementById('dType').value='other';
  document.getElementById('dMeeting').value='';
  document.getElementById('dAccess').value='internal';
  document.getElementById('dUrl').value='';
  document.getElementById('dBy').value='';
  document.getElementById('dDesc').value='';
}
function openEdit(d){
  document.getElementById('dTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Document';
  document.getElementById('dId').value=d.id;
  document.getElementById('dDocTitle').value=d.title||'';
  document.getElementById('dType').value=d.doc_type||'other';
  document.getElementById('dMeeting').value=d.meeting_id||'';
  document.getElementById('dAccess').value=d.access_level||'internal';
  document.getElementById('dUrl').value=d.document_url||'';
  document.getElementById('dBy').value=d.uploaded_by||'';
  document.getElementById('dDesc').value=d.description||'';
  new bootstrap.Modal(document.getElementById('docModal')).show();
}
function delDoc(id,title){
  Swal.fire({title:'Remove Document?',text:'"'+title+'" will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delDocId').value=id;document.getElementById('delDocForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
