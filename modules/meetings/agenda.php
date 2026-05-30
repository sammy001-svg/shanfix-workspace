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
    verifyCsrf();denyIfReadOnly($moduleSlug); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $meetingId = (int)($_POST['meeting_id'] ?? 0);
        $order     = (int)($_POST['item_order'] ?? 1);
        $title     = sanitize($_POST['title'] ?? '');
        $presenter = sanitize($_POST['presenter'] ?? '');
        $duration  = (int)($_POST['duration_minutes'] ?? 0);
        $notes     = sanitize($_POST['notes'] ?? '');
        $status    = in_array($_POST['status'] ?? '', ['pending','discussed','deferred','skipped']) ? $_POST['status'] : 'pending';
        if (!$meetingId || !$title) { setFlash('error', 'Meeting and title are required.'); redirect('agenda.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE meeting_agenda_items SET meeting_id=?,item_order=?,title=?,presenter=?,duration_minutes=?,notes=?,status=? WHERE id=? AND org_id=?")
                ->execute([$meetingId,$order,$title,$presenter,$duration,$notes,$status,$id,$orgId]);
            setFlash('success', 'Agenda item updated.');
        } else {
            $pdo->prepare("INSERT INTO meeting_agenda_items(org_id,meeting_id,item_order,title,presenter,duration_minutes,notes,status)VALUES(?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$meetingId,$order,$title,$presenter,$duration,$notes,$status]);
            setFlash('success', "Agenda item '$title' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'meetings', "Agenda: $title");
        redirect('agenda.php' . ($meetingId ? "?meeting_id=$meetingId" : ''));
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $meetingId = (int)($_POST['meeting_id'] ?? 0);
        $pdo->prepare("DELETE FROM meeting_agenda_items WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Agenda item removed.');
        redirect('agenda.php' . ($meetingId ? "?meeting_id=$meetingId" : ''));
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fMeeting = (int)($_GET['meeting_id'] ?? 0);

$where = 'a.org_id=?'; $params = [$orgId];
if ($fMeeting) { $where .= ' AND a.meeting_id=?'; $params[] = $fMeeting; }

$items = [];
try {
    $s = $pdo->prepare("SELECT a.*,m.title AS meeting_title,m.meeting_date FROM meeting_agenda_items a LEFT JOIN meetings m ON a.meeting_id=m.id WHERE $where ORDER BY a.meeting_id DESC, a.item_order ASC");
    $s->execute($params); $items = $s->fetchAll();
} catch (Exception $e) {}

$meetings = [];
try { $s = $pdo->prepare("SELECT id,title,meeting_date FROM meetings WHERE org_id=? ORDER BY meeting_date DESC LIMIT 50"); $s->execute([$orgId]); $meetings = $s->fetchAll(); } catch (Exception $e) {}

$selectedMeeting = null;
if ($fMeeting) {
    foreach ($meetings as $m) { if ($m['id'] === $fMeeting) { $selectedMeeting = $m; break; } }
}

$totalMinutes = array_sum(array_column($items, 'duration_minutes'));
$statusColors = ['pending'=>'warning','discussed'=>'success','deferred'=>'info','skipped'=>'secondary'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-list-ul me-2" style="color:<?=$moduleColor?>"></i>Agenda</h4>
    <p class="text-muted mb-0">Manage meeting agenda items and discussion order</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#agModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Agenda Item
  </button>
</div>

<?php if ($selectedMeeting): ?>
<div class="alert alert-info d-flex align-items-center mb-4">
  <i class="fas fa-video me-2"></i>
  <div><strong><?=e($selectedMeeting['title'])?></strong> — <?=formatDate($selectedMeeting['meeting_date'])?>
    &nbsp;<a href="agenda.php" class="btn btn-sm btn-outline-secondary ms-2">Show All</a>
  </div>
</div>
<?php endif; ?>

<!-- Meeting filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-6"><label class="form-label small fw-semibold mb-1">Filter by Meeting</label>
      <select name="meeting_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Meetings</option>
        <?php foreach ($meetings as $m): ?><option value="<?=$m['id']?>" <?=$fMeeting==$m['id']?'selected':''?>><?=e($m['title'])?> (<?=formatDate($m['meeting_date'])?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><a href="agenda.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
  </form>
</div></div>

<?php if ($fMeeting && !empty($items)): ?>
<div class="alert alert-secondary mb-3">
  <i class="fas fa-clock me-2"></i>Total estimated duration: <strong><?=$totalMinutes?> minutes</strong> (<?=floor($totalMinutes/60)?>'h <?=$totalMinutes%60?>'m)
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list-ul me-2" style="color:<?=$moduleColor?>"></i>Agenda Items</h6>
    <span class="badge bg-secondary"><?=count($items)?> items</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>#</th><th>Meeting</th><th>Title</th><th>Presenter</th><th>Duration</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($items)): ?>
      <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-list-ul fa-2x mb-2 d-block"></i>No agenda items found.</td></tr>
    <?php else: foreach ($items as $it): ?>
      <tr>
        <td><span class="badge bg-secondary"><?=$it['item_order']?></span></td>
        <td class="small"><?=e($it['meeting_title']??'—')?><?=$it['meeting_date']?'<br><span class="text-muted">'.formatDate($it['meeting_date']).'</span>':''?></td>
        <td class="fw-semibold"><?=e($it['title'])?><?=$it['notes']?'<br><small class="text-muted">'.e(mb_substr($it['notes'],0,60)).'</small>':''?></td>
        <td class="small"><?=e($it['presenter']??'—')?></td>
        <td><?=$it['duration_minutes']?$it['duration_minutes'].' min':'—'?></td>
        <td><span class="badge bg-<?=$statusColors[$it['status']]??'secondary'?>"><?=ucfirst($it['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($it),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delItem(<?=$it['id']?>,<?=$it['meeting_id']?>,<?=json_encode($it['title'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Agenda Modal -->
<div class="modal fade" id="agModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="agId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="agTitle"><i class="fas fa-list-ul me-2"></i>Add Agenda Item</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Meeting <span class="text-danger">*</span></label>
      <select name="meeting_id" id="agMeeting" class="form-select" required>
        <option value="">— Select Meeting —</option>
        <?php foreach ($meetings as $m): ?><option value="<?=$m['id']?>" <?=$fMeeting==$m['id']?'selected':''?>><?=e($m['title'])?> (<?=formatDate($m['meeting_date'])?>)</option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Order / Position</label>
      <input type="number" name="item_order" id="agOrder" class="form-control" min="1" value="1"></div>
    <div class="col-12"><label class="form-label fw-semibold">Item Title <span class="text-danger">*</span></label>
      <input type="text" name="title" id="agItemTitle" class="form-control" required maxlength="255"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Presenter</label>
      <input type="text" name="presenter" id="agPresenter" class="form-control" maxlength="150"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Duration (min)</label>
      <input type="number" name="duration_minutes" id="agDuration" class="form-control" min="0" value="0"></div>
    <div class="col-md-3"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="agStatus" class="form-select">
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="agNotes" class="form-control" rows="3"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Item</button>
  </div></form>
</div></div></div>
<form method="POST" id="delAgForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delAgId"><input type="hidden" name="meeting_id" id="delAgMeeting"></form>

<?php
$fMeetingJ = json_encode($fMeeting ?: '');
$extraJs = <<<JS
<script>
var defaultMeeting={$fMeetingJ};
function openAdd(){
  document.getElementById('agTitle').innerHTML='<i class="fas fa-list-ul me-2"></i>Add Agenda Item';
  document.getElementById('agId').value='0';
  document.getElementById('agMeeting').value=defaultMeeting||'';
  document.getElementById('agOrder').value='1';
  document.getElementById('agItemTitle').value='';
  document.getElementById('agPresenter').value='';
  document.getElementById('agDuration').value='0';
  document.getElementById('agStatus').value='pending';
  document.getElementById('agNotes').value='';
}
function openEdit(a){
  document.getElementById('agTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Agenda Item';
  document.getElementById('agId').value=a.id;
  document.getElementById('agMeeting').value=a.meeting_id||'';
  document.getElementById('agOrder').value=a.item_order||1;
  document.getElementById('agItemTitle').value=a.title||'';
  document.getElementById('agPresenter').value=a.presenter||'';
  document.getElementById('agDuration').value=a.duration_minutes||0;
  document.getElementById('agStatus').value=a.status||'pending';
  document.getElementById('agNotes').value=a.notes||'';
  new bootstrap.Modal(document.getElementById('agModal')).show();
}
function delItem(id,meetingId,title){
  Swal.fire({title:'Remove Agenda Item?',text:'"'+title+'" will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delAgId').value=id;document.getElementById('delAgMeeting').value=meetingId;document.getElementById('delAgForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
