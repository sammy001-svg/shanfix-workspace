<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $title=sanitize($_POST['title']??'');$content=sanitize($_POST['content']??'');
        $priority=sanitize($_POST['priority']??'normal');$audience=sanitize($_POST['audience']??'all');
        $classId=$audience==='class'?(int)($_POST['class_id']??0):null;
        $publish=sanitize($_POST['publish_date']??date('Y-m-d'));$expiry=sanitize($_POST['expiry_date']??'');
        $pinned=(int)($_POST['is_pinned']??0);
        if(!$title||!$content){setFlash('error','Title and content are required.');redirect('notices.php');}
        if($id){
            $pdo->prepare("UPDATE sch_notices SET title=?,content=?,priority=?,audience=?,class_id=?,publish_date=?,expiry_date=?,is_pinned=? WHERE id=? AND org_id=?")
               ->execute([$title,$content,$priority,$audience,$classId,$publish,$expiry?:null,$pinned,$id,$orgId]);
            setFlash('success','Notice updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_notices (org_id,title,content,priority,audience,class_id,publish_date,expiry_date,is_pinned,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$title,$content,$priority,$audience,$classId,$publish,$expiry?:null,$pinned,$user['id']]);
            setFlash('success','Notice posted.');
        }
        redirect('notices.php');
    }

    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_notices WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Notice deleted.');redirect('notices.php');
    }

    if($action==='toggle_pin'){
        $id=(int)($_POST['id']??0);
        $s=$pdo->prepare("SELECT is_pinned FROM sch_notices WHERE id=? AND org_id=?");$s->execute([$id,$orgId]);$cur=(int)$s->fetchColumn();
        $pdo->prepare("UPDATE sch_notices SET is_pinned=? WHERE id=? AND org_id=?")->execute([1-$cur,$id,$orgId]);
        redirect('notices.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fPriority=$_GET['priority']??'';$fAudience=$_GET['audience']??'';$fStatus=$_GET['status']??'active';

$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}

$notices=[];
try{
    $where='WHERE n.org_id=?';$params=[$orgId];
    if($fPriority){$where.=' AND n.priority=?';$params[]=$fPriority;}
    if($fAudience){$where.=' AND n.audience=?';$params[]=$fAudience;}
    if($fStatus==='active'){$where.=" AND n.publish_date<=? AND (n.expiry_date IS NULL OR n.expiry_date>=?)";$params[]=date('Y-m-d');$params[]=date('Y-m-d');}
    elseif($fStatus==='expired'){$where.=" AND n.expiry_date<? AND n.expiry_date IS NOT NULL";$params[]=date('Y-m-d');}
    $s=$pdo->prepare("SELECT n.*,c.name AS class_name FROM sch_notices n LEFT JOIN sch_classes c ON n.class_id=c.id $where ORDER BY n.is_pinned DESC,n.priority='urgent' DESC,n.priority='important' DESC,n.publish_date DESC");
    $s->execute($params);$notices=$s->fetchAll();
}catch(Exception $e){}

$totalNotices=count($notices);
$urgentCount=count(array_filter($notices,fn($n)=>$n['priority']==='urgent'));
$pinnedCount=count(array_filter($notices,fn($n)=>$n['is_pinned']));
$today=date('Y-m-d');
$todayCount=count(array_filter($notices,fn($n)=>$n['publish_date']===$today));

$priorityColors=['normal'=>'secondary','important'=>'warning','urgent'=>'danger'];
$priorityIcons=['normal'=>'fas fa-info-circle','important'=>'fas fa-exclamation-circle','urgent'=>'fas fa-exclamation-triangle'];
$audienceColors=['all'=>'primary','students'=>'info','staff'=>'success','parents'=>'warning','class'=>'secondary'];
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-bullhorn me-2" style="color:<?=$moduleColor?>"></i>Notice Board</h4><p class="text-muted mb-0">Post and manage school announcements and notices</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#noticeModal"><i class="fas fa-plus me-2"></i>Post Notice</button>
</div>

<!-- KPI -->
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-bullhorn"></i></div><div class="stat-body"><div class="stat-value"><?=$totalNotices?></div><div class="stat-label">Active Notices</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div><div class="stat-body"><div class="stat-value"><?=$urgentCount?></div><div class="stat-label">Urgent</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-thumbtack"></i></div><div class="stat-body"><div class="stat-value"><?=$pinnedCount?></div><div class="stat-label">Pinned</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-calendar-day"></i></div><div class="stat-body"><div class="stat-value"><?=$todayCount?></div><div class="stat-label">Posted Today</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Priority</label>
      <select name="priority" class="form-select form-select-sm"><option value="">All</option><?php foreach(array_keys($priorityColors) as $p):?><option value="<?=$p?>" <?=$fPriority===$p?'selected':''?>><?=ucfirst($p)?></option><?php endforeach;?></select>
    </div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Audience</label>
      <select name="audience" class="form-select form-select-sm"><option value="">All</option><?php foreach(array_keys($audienceColors) as $a):?><option value="<?=$a?>" <?=$fAudience===$a?'selected':''?>><?=ucfirst($a)?></option><?php endforeach;?></select>
    </div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm"><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="expired" <?=$fStatus==='expired'?'selected':''?>>Expired</option><option value="" <?=$fStatus===''?'selected':''?>>All</option></select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-success">Filter</button><a href="notices.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a></div>
  </form>
</div></div>

<!-- Notice Board Cards -->
<?php if(empty($notices)):?>
<div class="text-center py-5 text-muted"><i class="fas fa-bullhorn fa-3x mb-3 d-block"></i>No notices found.</div>
<?php else:?>
<div class="row g-3">
<?php foreach($notices as $n):$pc=$priorityColors[$n['priority']]??'secondary';$pi=$priorityIcons[$n['priority']]??'fas fa-info-circle';$ac=$audienceColors[$n['audience']]??'secondary';$expired=$n['expiry_date']&&$n['expiry_date']<$today;?>
<div class="col-md-6">
  <div class="card h-100 <?=$expired?'opacity-75':''?>" style="border-left:4px solid var(--bs-<?=$pc?>);">
    <div class="card-header d-flex justify-content-between align-items-start pb-1">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php if($n['is_pinned']):?><i class="fas fa-thumbtack text-warning" title="Pinned"></i><?php endif;?>
        <span class="badge bg-<?=$pc?>"><i class="<?=$pi?> me-1"></i><?=ucfirst($n['priority'])?></span>
        <span class="badge bg-<?=$ac?>"><?=ucfirst($n['audience'])?><?=$n['audience']==='class'&&$n['class_name']?' ('.$n['class_name'].')':''?></span>
        <?php if($expired):?><span class="badge bg-secondary">Expired</span><?php endif;?>
      </div>
      <div class="d-flex gap-1">
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="toggle_pin"><input type="hidden" name="id" value="<?=$n['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-warning" title="<?=$n['is_pinned']?'Unpin':'Pin'?>"><i class="fas fa-thumbtack"></i></button>
        </form>
        <button class="btn btn-xs btn-outline-secondary btn-edit"
          data-id="<?=$n['id']?>" data-title="<?=e($n['title'])?>" data-content="<?=e($n['content'])?>"
          data-priority="<?=$n['priority']?>" data-audience="<?=$n['audience']?>"
          data-class_id="<?=$n['class_id']??0?>" data-publish="<?=$n['publish_date']?>"
          data-expiry="<?=$n['expiry_date']??''?>" data-pinned="<?=$n['is_pinned']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$n['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this notice?"><i class="fas fa-trash"></i></button>
        </form>
      </div>
    </div>
    <div class="card-body pt-2">
      <h6 class="fw-bold mb-1"><?=e($n['title'])?></h6>
      <p class="text-muted small mb-2" style="white-space:pre-line"><?=e($n['content'])?></p>
      <div class="text-muted" style="font-size:.75rem">
        <i class="fas fa-calendar me-1"></i>Published: <?=formatDate($n['publish_date'])?>
        <?php if($n['expiry_date']):?> &bull; Expires: <span class="<?=$expired?'text-danger':''?>"><?=formatDate($n['expiry_date'])?></span><?php endif;?>
      </div>
    </div>
  </div>
</div>
<?php endforeach;?>
</div>
<?php endif;?>

<!-- Notice Modal -->
<div class="modal fade" id="noticeModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i><span id="noticeModalTitle">Post Notice</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="noticeId" value="0">
    <div class="row g-3">
      <div class="col-12"><label class="form-label fw-semibold">Title <span class="text-danger">*</span></label><input type="text" name="title" id="noticeTitle" class="form-control" required></div>
      <div class="col-12"><label class="form-label fw-semibold">Content <span class="text-danger">*</span></label><textarea name="content" id="noticeContent" class="form-control" rows="4" required></textarea></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Priority</label>
        <select name="priority" id="noticePriority" class="form-select"><option value="normal">Normal</option><option value="important">Important</option><option value="urgent">Urgent</option></select>
      </div>
      <div class="col-md-4"><label class="form-label fw-semibold">Audience</label>
        <select name="audience" id="noticeAudience" class="form-select" onchange="toggleClassPicker()"><option value="all">All</option><option value="students">Students</option><option value="staff">Staff</option><option value="parents">Parents</option><option value="class">Specific Class</option></select>
      </div>
      <div class="col-md-4" id="classPickerWrap" style="display:none"><label class="form-label fw-semibold">Class</label>
        <select name="class_id" id="noticeClass" class="form-select"><option value="">â€” Select â€”</option><?php foreach($classes as $c):?><option value="<?=$c['id']?>"><?=e($c['name'])?></option><?php endforeach;?></select>
      </div>
      <div class="col-md-4"><label class="form-label fw-semibold">Publish Date</label><input type="date" name="publish_date" id="noticePublish" class="form-control" value="<?=date('Y-m-d')?>"></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Expiry Date</label><input type="date" name="expiry_date" id="noticeExpiry" class="form-control"></div>
      <div class="col-md-4 d-flex align-items-end"><div class="form-check"><input type="checkbox" name="is_pinned" value="1" id="noticePinned" class="form-check-input"><label class="form-check-label fw-semibold" for="noticePinned"><i class="fas fa-thumbtack text-warning me-1"></i>Pin to top</label></div></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Post Notice</button></div>
  </form>
</div></div></div>

<?php ob_start();?>
<script>
function toggleClassPicker(){
  var audience=document.getElementById('noticeAudience').value;
  document.getElementById('classPickerWrap').style.display=audience==='class'?'block':'none';
}
document.querySelectorAll('.btn-edit').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('noticeModalTitle').textContent='Edit Notice';
  document.getElementById('noticeId').value=this.dataset.id;
  document.getElementById('noticeTitle').value=this.dataset.title||'';
  document.getElementById('noticeContent').value=this.dataset.content||'';
  document.getElementById('noticePriority').value=this.dataset.priority||'normal';
  document.getElementById('noticeAudience').value=this.dataset.audience||'all';
  document.getElementById('noticeClass').value=this.dataset.class_id||'';
  document.getElementById('noticePublish').value=this.dataset.publish||'';
  document.getElementById('noticeExpiry').value=this.dataset.expiry||'';
  document.getElementById('noticePinned').checked=this.dataset.pinned==='1';
  toggleClassPicker();
  new bootstrap.Modal(document.getElementById('noticeModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>

