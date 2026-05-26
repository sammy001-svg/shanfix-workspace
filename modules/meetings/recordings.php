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
        $id         = (int)($_POST['id'] ?? 0);
        $meetingId  = (int)($_POST['meeting_id'] ?? 0) ?: null;
        $title      = sanitize($_POST['title'] ?? '');
        $type       = in_array($_POST['recording_type'] ?? '', ['audio','video','transcript','notes']) ? $_POST['recording_type'] : 'video';
        $url        = sanitize($_POST['recording_url'] ?? '');
        $duration   = (int)($_POST['duration_minutes'] ?? 0);
        $recordedBy = sanitize($_POST['recorded_by'] ?? '');
        $recordDate = $_POST['recorded_at'] ?? null;
        $access     = in_array($_POST['access_level'] ?? '', ['public','internal','restricted']) ? $_POST['access_level'] : 'internal';
        $notes      = sanitize($_POST['notes'] ?? '');
        if (!$title) { setFlash('error', 'Title is required.'); redirect('recordings.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE meeting_recordings SET meeting_id=?,title=?,recording_type=?,recording_url=?,duration_minutes=?,recorded_by=?,recorded_at=?,access_level=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$meetingId,$title,$type,$url,$duration,$recordedBy,$recordDate?:null,$access,$notes,$id,$orgId]);
            setFlash('success', 'Recording updated.');
        } else {
            $pdo->prepare("INSERT INTO meeting_recordings(org_id,meeting_id,title,recording_type,recording_url,duration_minutes,recorded_by,recorded_at,access_level,notes)VALUES(?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$meetingId,$title,$type,$url,$duration,$recordedBy,$recordDate?:null,$access,$notes]);
            setFlash('success', "Recording '$title' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'meetings', "Recording: $title");
        redirect('recordings.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_recordings WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Recording deleted.'); redirect('recordings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fMeeting = (int)($_GET['meeting_id'] ?? 0);
$fType    = $_GET['type'] ?? '';

$where = 'r.org_id=?'; $params = [$orgId];
if ($fMeeting) { $where .= ' AND r.meeting_id=?'; $params[] = $fMeeting; }
if ($fType)    { $where .= ' AND r.recording_type=?'; $params[] = $fType; }

$recordings = [];
try {
    $s = $pdo->prepare("SELECT r.*,m.title AS meeting_title,m.meeting_date FROM meeting_recordings r LEFT JOIN meetings m ON r.meeting_id=m.id WHERE $where ORDER BY r.recorded_at DESC, r.id DESC");
    $s->execute($params); $recordings = $s->fetchAll();
} catch (Exception $e) {}

$meetings = [];
try { $s = $pdo->prepare("SELECT id,title,meeting_date FROM meetings WHERE org_id=? ORDER BY meeting_date DESC LIMIT 50"); $s->execute([$orgId]); $meetings = $s->fetchAll(); } catch (Exception $e) {}

$totalRec = 0; $videoCount = 0; $audioCount = 0; $totalDuration = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*),SUM(recording_type='video'),SUM(recording_type='audio'),COALESCE(SUM(duration_minutes),0) FROM meeting_recordings WHERE org_id=?");
    $s->execute([$orgId]); $r = $s->fetch(PDO::FETCH_NUM); $totalRec=(int)$r[0]; $videoCount=(int)$r[1]; $audioCount=(int)$r[2]; $totalDuration=(int)$r[3];
} catch (Exception $e) {}

$typeIcons  = ['audio'=>'fa-microphone','video'=>'fa-video','transcript'=>'fa-file-alt','notes'=>'fa-sticky-note'];
$typeColors = ['audio'=>'warning','video'=>'primary','transcript'=>'info','notes'=>'secondary'];
$accessColors = ['public'=>'success','internal'=>'warning','restricted'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-microphone me-2" style="color:<?=$moduleColor?>"></i>Recordings</h4>
    <p class="text-muted mb-0">Store links and metadata for meeting audio, video and transcripts</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#recModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Recording
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#e8eaf6;color:<?=$moduleColor?>"><i class="fas fa-microphone"></i></div><div class="stat-body"><div class="stat-value"><?=$totalRec?></div><div class="stat-label">Total Records</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-video"></i></div><div class="stat-body"><div class="stat-value"><?=$videoCount?></div><div class="stat-label">Videos</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-microphone-alt"></i></div><div class="stat-body"><div class="stat-value"><?=$audioCount?></div><div class="stat-label">Audio</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=floor($totalDuration/60)?>h <?=$totalDuration%60?>m</div><div class="stat-label">Total Duration</div></div></div></div>
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
        <?php foreach (array_keys($typeColors) as $t): ?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="recordings.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-microphone me-2" style="color:<?=$moduleColor?>"></i>Recording Library</h6>
    <span class="badge bg-secondary"><?=count($recordings)?> records</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Type</th><th>Title</th><th>Meeting</th><th>Recorded By</th><th>Date</th><th>Duration</th><th>Access</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($recordings)): ?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-microphone fa-2x mb-2 d-block"></i>No recordings added yet.</td></tr>
    <?php else: foreach ($recordings as $rec): ?>
      <tr>
        <td><span class="badge bg-<?=$typeColors[$rec['recording_type']]??'secondary'?>"><i class="fas <?=$typeIcons[$rec['recording_type']]??'fa-file'?> me-1"></i><?=ucfirst($rec['recording_type'])?></span></td>
        <td>
          <?php if ($rec['recording_url']): ?>
            <a href="<?=e($rec['recording_url'])?>" target="_blank" class="fw-semibold text-decoration-none"><?=e($rec['title'])?> <i class="fas fa-external-link-alt fa-xs ms-1"></i></a>
          <?php else: ?>
            <span class="fw-semibold"><?=e($rec['title'])?></span>
          <?php endif; ?>
          <?=$rec['notes']?'<br><small class="text-muted">'.e(mb_substr($rec['notes'],0,60)).'</small>':''?>
        </td>
        <td class="small"><?=e($rec['meeting_title']??'—')?></td>
        <td class="small"><?=e($rec['recorded_by']??'—')?></td>
        <td class="small"><?=$rec['recorded_at']?formatDate(substr($rec['recorded_at'],0,10)):'—'?></td>
        <td><?=$rec['duration_minutes']?$rec['duration_minutes'].' min':'—'?></td>
        <td><span class="badge bg-<?=$accessColors[$rec['access_level']]??'secondary'?>"><?=ucfirst($rec['access_level']??'')?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($rec),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delRec(<?=$rec['id']?>,<?=json_encode($rec['title'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="recModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="rId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="rTitle"><i class="fas fa-microphone me-2"></i>Add Recording</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label>
      <input type="text" name="title" id="rRecTitle" class="form-control" required maxlength="255"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Type</label>
      <select name="recording_type" id="rType" class="form-select">
        <?php foreach (array_keys($typeColors) as $t): ?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Meeting</label>
      <select name="meeting_id" id="rMeeting" class="form-select">
        <option value="">— None —</option>
        <?php foreach ($meetings as $m): ?><option value="<?=$m['id']?>"><?=e($m['title'])?> (<?=formatDate($m['meeting_date'])?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Access Level</label>
      <select name="access_level" id="rAccess" class="form-select">
        <?php foreach (array_keys($accessColors) as $a): ?><option value="<?=$a?>"><?=ucfirst($a)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Recording URL / Link</label>
      <input type="url" name="recording_url" id="rUrl" class="form-control" placeholder="https://zoom.us/rec/share/…"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Recorded By</label>
      <input type="text" name="recorded_by" id="rBy" class="form-control" maxlength="150"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Date Recorded</label>
      <input type="date" name="recorded_at" id="rDate" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Duration (min)</label>
      <input type="number" name="duration_minutes" id="rDuration" class="form-control" min="0" value="0"></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="rNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Recording</button>
  </div></form>
</div></div></div>
<form method="POST" id="delRecForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delRecId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('rTitle').innerHTML='<i class="fas fa-microphone me-2"></i>Add Recording';
  document.getElementById('rId').value='0';
  document.getElementById('rRecTitle').value='';
  document.getElementById('rType').value='video';
  document.getElementById('rMeeting').value='';
  document.getElementById('rAccess').value='internal';
  document.getElementById('rUrl').value='';
  document.getElementById('rBy').value='';
  document.getElementById('rDate').value='';
  document.getElementById('rDuration').value='0';
  document.getElementById('rNotes').value='';
}
function openEdit(r){
  document.getElementById('rTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Recording';
  document.getElementById('rId').value=r.id;
  document.getElementById('rRecTitle').value=r.title||'';
  document.getElementById('rType').value=r.recording_type||'video';
  document.getElementById('rMeeting').value=r.meeting_id||'';
  document.getElementById('rAccess').value=r.access_level||'internal';
  document.getElementById('rUrl').value=r.recording_url||'';
  document.getElementById('rBy').value=r.recorded_by||'';
  document.getElementById('rDate').value=r.recorded_at?r.recorded_at.substring(0,10):'';
  document.getElementById('rDuration').value=r.duration_minutes||0;
  document.getElementById('rNotes').value=r.notes||'';
  new bootstrap.Modal(document.getElementById('recModal')).show();
}
function delRec(id,title){
  Swal.fire({title:'Delete Recording?',text:'"'+title+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delRecId').value=id;document.getElementById('delRecForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
