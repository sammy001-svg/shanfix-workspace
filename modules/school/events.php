<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $title=sanitize($_POST['title']??'');$desc=sanitize($_POST['description']??'');
        $type=sanitize($_POST['event_type']??'academic');$startDate=sanitize($_POST['start_date']??'');
        $endDate=sanitize($_POST['end_date']??'');$startTime=sanitize($_POST['start_time']??'');
        $endTime=sanitize($_POST['end_time']??'');$venue=sanitize($_POST['venue']??'');
        $audience=sanitize($_POST['audience']??'all');$status=sanitize($_POST['status']??'upcoming');
        if(!$title||!$startDate){setFlash('error','Title and start date are required.');redirect('events.php');}
        if($id){
            $pdo->prepare("UPDATE sch_events SET title=?,description=?,event_type=?,start_date=?,end_date=?,start_time=?,end_time=?,venue=?,audience=?,status=? WHERE id=? AND org_id=?")
               ->execute([$title,$desc,$type,$startDate,$endDate?:null,$startTime?:null,$endTime?:null,$venue,$audience,$status,$id,$orgId]);
            setFlash('success','Event updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_events (org_id,title,description,event_type,start_date,end_date,start_time,end_time,venue,audience,status,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$title,$desc,$type,$startDate,$endDate?:null,$startTime?:null,$endTime?:null,$venue,$audience,$status,$user['id']]);
            setFlash('success','Event created.');
        }
        redirect('events.php');
    }

    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_events WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Event deleted.');redirect('events.php');
    }

    if($action==='update_status'){
        $id=(int)($_POST['id']??0);$status=sanitize($_POST['status']??'');
        $valid=['upcoming','ongoing','completed','cancelled'];
        if(in_array($status,$valid)){$pdo->prepare("UPDATE sch_events SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);}
        redirect('events.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fType=$_GET['type']??'';$fStatus=$_GET['status']??'';$fMonth=$_GET['month']??date('Y-m');

$events=[];
try{
    $where='WHERE org_id=?';$params=[$orgId];
    if($fType){$where.=' AND event_type=?';$params[]=$fType;}
    if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
    if($fMonth){$ym=explode('-',$fMonth);$where.=' AND YEAR(start_date)=? AND MONTH(start_date)=?';$params[]=(int)$ym[0];$params[]=(int)($ym[1]??1);}
    $s=$pdo->prepare("SELECT * FROM sch_events $where ORDER BY start_date ASC,start_time ASC");$s->execute($params);$events=$s->fetchAll();
}catch(Exception $e){}

$upcomingCount=0;$todayEvents=[];$today=date('Y-m-d');
try{$s=$pdo->prepare("SELECT COUNT(*) FROM sch_events WHERE org_id=? AND status='upcoming' AND start_date>=?");$s->execute([$orgId,$today]);$upcomingCount=(int)$s->fetchColumn();}catch(Exception $e){}
try{$s=$pdo->prepare("SELECT * FROM sch_events WHERE org_id=? AND start_date=? ORDER BY start_time");$s->execute([$orgId,$today]);$todayEvents=$s->fetchAll();}catch(Exception $e){}

$typeColors=['academic'=>'primary','sports'=>'success','cultural'=>'warning','holiday'=>'danger','meeting'=>'info','exam'=>'dark','other'=>'secondary'];
$typeIcons=['academic'=>'fas fa-graduation-cap','sports'=>'fas fa-futbol','cultural'=>'fas fa-music','holiday'=>'fas fa-umbrella-beach','meeting'=>'fas fa-handshake','exam'=>'fas fa-file-alt','other'=>'fas fa-calendar'];
$statusColors=['upcoming'=>'primary','ongoing'=>'success','completed'=>'secondary','cancelled'=>'danger'];
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-calendar-day me-2" style="color:<?=$moduleColor?>"></i>School Events</h4><p class="text-muted mb-0">Academic calendar, activities and event management</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#eventModal"><i class="fas fa-plus me-2"></i>Add Event</button>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-calendar-alt"></i></div><div class="stat-body"><div class="stat-value"><?=count($events)?></div><div class="stat-label">Events This Month</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?=$upcomingCount?></div><div class="stat-label">Upcoming Events</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-star"></i></div><div class="stat-body"><div class="stat-value"><?=count($todayEvents)?></div><div class="stat-label">Today's Events</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?=date('d M Y')?></div><div class="stat-label">Today</div></div></div></div>
</div>

<?php if(!empty($todayEvents)):?>
<div class="alert border-start border-4 border-warning bg-warning-subtle mb-3">
  <div class="fw-semibold mb-1"><i class="fas fa-star me-1 text-warning"></i>Events Today (<?=date('d M Y')?>)</div>
  <?php foreach($todayEvents as $te):$tc=$typeColors[$te['event_type']]??'secondary';?>
  <span class="badge bg-<?=$tc?> me-2"><?=e($te['title'])?><?=$te['start_time']?' @ '.date('H:i',strtotime($te['start_time'])):''?></span>
  <?php endforeach;?>
</div>
<?php endif;?>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Month</label><input type="month" name="month" class="form-control form-control-sm" value="<?=e($fMonth)?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm"><option value="">All Types</option><?php foreach(array_keys($typeColors) as $t):?><option value="<?=$t?>" <?=$fType===$t?'selected':''?>><?=ucfirst($t)?></option><?php endforeach;?></select>
    </div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="">All Statuses</option><?php foreach(['upcoming','ongoing','completed','cancelled'] as $s):?><option value="<?=$s?>" <?=$fStatus===$s?'selected':''?>><?=ucfirst($s)?></option><?php endforeach;?></select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-success">Filter</button><a href="events.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Events (<?=count($events)?>)</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Event</th><th>Type</th><th>Date</th><th>Time</th><th>Venue</th><th>Audience</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($events)):?><tr><td colspan="8" class="text-center text-muted py-4">No events found for the selected filters.</td></tr>
    <?php else:foreach($events as $ev):$tc=$typeColors[$ev['event_type']]??'secondary';$ti=$typeIcons[$ev['event_type']]??'fas fa-calendar';$sc=$statusColors[$ev['status']]??'secondary';?>
    <tr>
      <td class="fw-semibold"><i class="<?=$ti?> me-1 text-<?=$tc?>"></i><?=e($ev['title'])?><?php if($ev['description']):?><div class="small text-muted text-truncate" style="max-width:200px"><?=e($ev['description'])?></div><?php endif;?></td>
      <td><span class="badge bg-<?=$tc?>"><?=ucfirst($ev['event_type'])?></span></td>
      <td class="small"><?=formatDate($ev['start_date'])?><?=$ev['end_date']&&$ev['end_date']!==$ev['start_date']?' â€“ '.formatDate($ev['end_date']):''?></td>
      <td class="small text-muted"><?=$ev['start_time']?date('H:i',strtotime($ev['start_time'])):''?><?=$ev['end_time']?' â€“ '.date('H:i',strtotime($ev['end_time'])):''?></td>
      <td class="small"><?=e($ev['venue']??'â€”')?></td>
      <td><span class="badge bg-light text-dark border"><?=ucfirst($ev['audience'])?></span></td>
      <td>
        <form method="POST" class="d-inline">
          <?=csrfField()?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?=$ev['id']?>">
          <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
            <?php foreach(['upcoming','ongoing','completed','cancelled'] as $st):?><option value="<?=$st?>" <?=$ev['status']===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach;?>
          </select>
        </form>
      </td>
      <td class="text-end">
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
          data-id="<?=$ev['id']?>" data-title="<?=e($ev['title'])?>" data-desc="<?=e($ev['description']??'')?>"
          data-type="<?=$ev['event_type']?>" data-start="<?=$ev['start_date']?>" data-end="<?=$ev['end_date']??''?>"
          data-stime="<?=$ev['start_time']??''?>" data-etime="<?=$ev['end_time']??''?>"
          data-venue="<?=e($ev['venue']??'')?>" data-audience="<?=$ev['audience']?>" data-status="<?=$ev['status']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$ev['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this event?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<!-- Event Modal -->
<div class="modal fade" id="eventModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-calendar-plus me-2"></i><span id="eventModalTitle">Add Event</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="eventId" value="0">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Event Title <span class="text-danger">*</span></label><input type="text" name="title" id="evTitle" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Event Type</label>
        <select name="event_type" id="evType" class="form-select">
          <?php foreach(array_keys($typeColors) as $t):?><option value="<?=$t?>"><?=ucfirst($t)?></option><?php endforeach;?>
        </select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Audience</label>
        <select name="audience" id="evAudience" class="form-select"><option value="all">All</option><option value="students">Students</option><option value="staff">Staff</option><option value="parents">Parents</option></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" id="evStart" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="evEnd" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Start Time</label><input type="time" name="start_time" id="evSTime" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">End Time</label><input type="time" name="end_time" id="evETime" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Venue</label><input type="text" name="venue" id="evVenue" class="form-control" placeholder="e.g. School Hall"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
        <select name="status" id="evStatus" class="form-select"><option value="upcoming">Upcoming</option><option value="ongoing">Ongoing</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select>
      </div>
      <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="evDesc" class="form-control" rows="2" placeholder="Optional details"></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Event</button></div>
  </form>
</div></div></div>

<?php ob_start();?>
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('eventModalTitle').textContent='Edit Event';
  document.getElementById('eventId').value=this.dataset.id;
  document.getElementById('evTitle').value=this.dataset.title||'';
  document.getElementById('evType').value=this.dataset.type||'academic';
  document.getElementById('evAudience').value=this.dataset.audience||'all';
  document.getElementById('evStart').value=this.dataset.start||'';
  document.getElementById('evEnd').value=this.dataset.end||'';
  document.getElementById('evSTime').value=this.dataset.stime||'';
  document.getElementById('evETime').value=this.dataset.etime||'';
  document.getElementById('evVenue').value=this.dataset.venue||'';
  document.getElementById('evStatus').value=this.dataset.status||'upcoming';
  document.getElementById('evDesc').value=this.dataset.desc||'';
  new bootstrap.Modal(document.getElementById('eventModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>

