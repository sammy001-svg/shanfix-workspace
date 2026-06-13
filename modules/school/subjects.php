<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/_nav.php';

// Auto-create / patch tables if migration hasn't been run
$pdo->exec("CREATE TABLE IF NOT EXISTS sch_subjects (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    org_id      INT NOT NULL,
    code        VARCHAR(20)  NULL,
    name        VARCHAR(150) NOT NULL,
    department  VARCHAR(100) NULL,
    description TEXT         NULL,
    is_elective TINYINT(1)   NOT NULL DEFAULT 0,
    pass_mark   DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    status      VARCHAR(20)  NOT NULL DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS sch_class_subjects (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    org_id       INT NOT NULL,
    class_id     INT NOT NULL,
    subject_id   INT NOT NULL,
    staff_id     INT NULL,
    periods_week INT NOT NULL DEFAULT 4,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_subject (class_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

foreach ([
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS code VARCHAR(20) NULL",
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS department VARCHAR(100) NULL",
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS description TEXT NULL",
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS is_elective TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS pass_mark DECIMAL(5,2) NOT NULL DEFAULT 50.00",
    "ALTER TABLE sch_subjects ADD COLUMN IF NOT EXISTS status VARCHAR(20) NOT NULL DEFAULT 'active'",
] as $sql) {
    try { $pdo->exec($sql); } catch (Throwable $ignored) {}
}

requireLogin();

if($_SERVER['REQUEST_METHOD']==='POST'){
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';
    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $code=sanitize($_POST['code']??'');$name=sanitize($_POST['name']??'');
        $dept=sanitize($_POST['department']??'');$desc=sanitize($_POST['description']??'');
        $elective=(int)($_POST['is_elective']??0);$pass=(float)($_POST['pass_mark']??50);
        $status=in_array($_POST['status']??'',['active','inactive'])?$_POST['status']:'active';
        if(!$name){setFlash('danger','Subject name required.');redirect('subjects.php');}
        try {
            if($id){$pdo->prepare("UPDATE sch_subjects SET code=?,name=?,department=?,description=?,is_elective=?,pass_mark=?,status=? WHERE id=? AND org_id=?")->execute([$code,$name,$dept,$desc,$elective,$pass,$status,$id,$orgId]);setFlash('success','Subject updated.');}
            else{$pdo->prepare("INSERT INTO sch_subjects (org_id,code,name,department,description,is_elective,pass_mark,status) VALUES (?,?,?,?,?,?,?,?)")->execute([$orgId,$code,$name,$dept,$desc,$elective,$pass,$status]);setFlash('success','Subject added.');}
        } catch (Throwable $e) {
            error_log('[school/subjects save] ' . $e->getMessage());
            setFlash('danger','Could not save subject: ' . htmlspecialchars($e->getMessage()));
        }
        redirect('subjects.php');
    }
    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        try{$pdo->prepare("DELETE FROM sch_subjects WHERE id=? AND org_id=?")->execute([$id,$orgId]);setFlash('success','Subject deleted.');}
        catch(Throwable $e){setFlash('danger','Could not delete subject.');}
        redirect('subjects.php');
    }
    if($action==='assign'){
        $classId=(int)($_POST['class_id']??0);$subjectId=(int)($_POST['subject_id']??0);
        $staffId=(int)($_POST['staff_id']??0)||null;$periods=(int)($_POST['periods_week']??1);
        if($classId&&$subjectId){
            try{$pdo->prepare("INSERT INTO sch_class_subjects (org_id,class_id,subject_id,staff_id,periods_week) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE staff_id=VALUES(staff_id),periods_week=VALUES(periods_week)")->execute([$orgId,$classId,$subjectId,$staffId,$periods]);setFlash('success','Subject assigned to class.');}
            catch(Exception $e){setFlash('danger','Assignment failed. Run database/school_module_migration.sql if this is a new install.');}
        }
        redirect('subjects.php');
    }
    if($action==='unassign'){
        $id=(int)($_POST['id']??0);
        try{$pdo->prepare("DELETE FROM sch_class_subjects WHERE id=? AND org_id=?")->execute([$id,$orgId]);}catch(Throwable $e){}
        redirect('subjects.php');
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$fDept=$_GET['department']??'';$fStatus=$_GET['status']??'';$view=(int)($_GET['view']??0);
$where='org_id=?';$params=[$orgId];
if($fDept){$where.=' AND department=?';$params[]=$fDept;}
if($fStatus){$where.=' AND status=?';$params[]=$fStatus;}
$subjects=[];try{$s=$pdo->prepare("SELECT * FROM sch_subjects WHERE $where ORDER BY name");$s->execute($params);$subjects=$s->fetchAll();}catch(Exception $e){}
$depts=[];try{$s=$pdo->prepare("SELECT DISTINCT department FROM sch_subjects WHERE org_id=? AND department IS NOT NULL ORDER BY department");$s->execute([$orgId]);$depts=array_column($s->fetchAll(),'department');}catch(Exception $e){}
$classes=[];try{$s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name");$s->execute([$orgId]);$classes=$s->fetchAll();}catch(Exception $e){}
$staff=[];try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_staff WHERE org_id=? ORDER BY first_name");$s->execute([$orgId]);$staff=$s->fetchAll();}catch(Exception $e){}
$assignments=[];
try{$s=$pdo->prepare("SELECT cs.*,sub.name AS subject_name,cl.name AS class_name,st.first_name,st.last_name FROM sch_class_subjects cs JOIN sch_subjects sub ON cs.subject_id=sub.id JOIN sch_classes cl ON cs.class_id=cl.id LEFT JOIN sch_staff st ON cs.staff_id=st.id WHERE cs.org_id=? ORDER BY cl.name,sub.name");$s->execute([$orgId]);$assignments=$s->fetchAll();}catch(Exception $e){}
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-book me-2" style="color:<?=$moduleColor?>"></i>Subjects</h4><p class="text-muted mb-0">Manage the curriculum, subject catalog and class assignments</p></div>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#subModal"><i class="fas fa-plus me-2"></i>Add Subject</button>
</div>
<div class="row g-3 mb-4">
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-book"></i></div><div class="stat-body"><div class="stat-value"><?=count($subjects)?></div><div class="stat-label">Total Subjects</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?=count(array_filter($subjects,fn($s)=>$s['status']==='active'))?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-layer-group"></i></div><div class="stat-body"><div class="stat-value"><?=count($depts)?></div><div class="stat-label">Departments</div></div></div></div>
  <div class="col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-link"></i></div><div class="stat-body"><div class="stat-value"><?=count($assignments)?></div><div class="stat-label">Class Assignments</div></div></div></div>
</div>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header with-filter">
        <h6 class="mb-0"><i class="fas fa-book me-2" style="color:<?=$moduleColor?>"></i>Subject Catalog</h6>
        <form class="row g-2" method="GET">
          <div class="col-sm-4"><select name="department" class="form-select form-select-sm"><option value="">All Departments</option><?php foreach($depts as $d):?><option value="<?=e($d)?>" <?=$fDept===$d?'selected':''?>><?=e($d)?></option><?php endforeach;?></select></div>
          <div class="col-sm-3"><select name="status" class="form-select form-select-sm"><option value="">All Status</option><option value="active" <?=$fStatus==='active'?'selected':''?>>Active</option><option value="inactive" <?=$fStatus==='inactive'?'selected':''?>>Inactive</option></select></div>
          <div class="col-auto"><button class="btn btn-sm btn-success"><i class="fas fa-filter"></i></button><a href="subjects.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
        </form>
      </div>
      <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
        <thead class="table-light"><tr><th>Code</th><th>Subject</th><th>Department</th><th>Pass Mark</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if(empty($subjects)):?><tr><td colspan="7" class="text-center text-muted py-4">No subjects found.</td></tr>
        <?php else:foreach($subjects as $sub):?>
        <tr>
          <td><code><?=e($sub['code']??'&mdash;')?></code></td>
          <td class="fw-semibold"><?=e($sub['name'])?></td>
          <td class=”small”><?=e($sub['department']??'&mdash;')?></td>
          <td><?=$sub['pass_mark']?>%</td>
          <td><?=$sub['is_elective']?'<span class="badge bg-info">Elective</span>':'<span class="badge bg-secondary">Core</span>'?></td>
          <td><?=statusBadge($sub['status'])?></td>
          <td>
            <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
              data-id="<?=$sub['id']?>" data-code="<?=e($sub['code']??'')?>"
              data-name="<?=e($sub['name'])?>" data-department="<?=e($sub['department']??'')?>"
              data-description="<?=e($sub['description']??'')?>" data-is_elective="<?=$sub['is_elective']?>"
              data-pass_mark="<?=$sub['pass_mark']?>" data-status="<?=$sub['status']?>"><i class="fas fa-edit"></i></button>
            <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$sub['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete subject <?=e($sub['name'])?>?"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table></div></div>
    </div>
  </div>
  <div class=”col-lg-5”>

    <!-- New Assignment Form Card -->
    <div class=”card mb-3”>
      <div class=”card-header” style=”background:<?=$moduleColor?>;color:#fff;”>
        <h6 class=”mb-0”><i class=”fas fa-link me-2”></i>New Class Assignment</h6>
      </div>
      <div class=”card-body”>
        <form method=”POST”>
          <?=csrfField()?>
          <div class=”mb-3”>
            <label class=”form-label fw-semibold”>Class <span class=”text-danger”>*</span></label>
            <select name=”class_id” class=”form-select” required>
              <option value=””>Select class…</option>
              <?php foreach($classes as $c): ?>
              <option value=”<?=$c['id']?>”><?=e($c['name'])?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class=”mb-3”>
            <label class=”form-label fw-semibold”>Subject <span class=”text-danger”>*</span></label>
            <select name=”subject_id” class=”form-select” required>
              <option value=””>Select subject…</option>
              <?php foreach($subjects as $s): if($s['status']==='active'): ?>
              <option value=”<?=$s['id']?>”><?=e($s['name'])?><?=$s['code']?' — '.$s['code']:''?></option>
              <?php endif; endforeach; ?>
            </select>
          </div>
          <div class=”row g-2 mb-3”>
            <div class=”col-8”>
              <label class=”form-label fw-semibold”>Teacher</label>
              <select name=”staff_id” class=”form-select”>
                <option value=””>None assigned</option>
                <?php foreach($staff as $st): ?>
                <option value=”<?=$st['id']?>”><?=e($st['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class=”col-4”>
              <label class=”form-label fw-semibold”>Periods/wk</label>
              <input type=”number” name=”periods_week” class=”form-control” min=”1” max=”30” value=”4”>
            </div>
          </div>
          <button type=”submit” name=”action” value=”assign” class=”btn text-white w-100” style=”background:<?=$moduleColor?>”>
            <i class=”fas fa-link me-2”></i>Assign to Class
          </button>
        </form>
      </div>
    </div>

    <!-- Assignments List Card -->
    <div class=”card”>
      <div class=”card-header d-flex align-items-center justify-content-between”>
        <h6 class=”mb-0”><i class=”fas fa-layer-group me-2” style=”color:<?=$moduleColor?>”></i>Assignments</h6>
        <span class=”badge bg-secondary”><?=count($assignments)?></span>
      </div>
      <div class=”card-body p-0” style=”max-height:360px;overflow-y:auto”>
        <?php if(empty($assignments)): ?>
        <div class=”text-center text-muted py-4”>
          <i class=”fas fa-layer-group fa-2x d-block mb-2 opacity-25”></i>
          <p class=”mb-0 small”>No assignments yet.</p>
        </div>
        <?php else: ?>
        <table class=”table table-hover align-middle mb-0 small”>
          <thead class=”table-light sticky-top”>
            <tr>
              <th class=”ps-3”>Subject</th>
              <th>Class</th>
              <th>Teacher</th>
              <th class=”text-center”>pw</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($assignments as $a): ?>
          <tr>
            <td class=”ps-3 fw-semibold”><?=e($a['subject_name'])?></td>
            <td><span class=”badge bg-light text-dark border”><?=e($a['class_name'])?></span></td>
            <td class=”text-muted”><?=$a['first_name']?e($a['first_name'].' '.$a['last_name']):'—'?></td>
            <td class=”text-center”><?=$a['periods_week']?></td>
            <td class=”text-end pe-2”>
              <form method=”POST” class=”d-inline”>
                <?=csrfField()?>
                <input type=”hidden” name=”id” value=”<?=$a['id']?>”>
                <button type=”submit” name=”action” value=”unassign”
                  class=”btn btn-xs btn-outline-danger btn-confirm”
                  data-msg=”Remove <?=e($a['subject_name'])?> from <?=e($a['class_name'])?>?”>
                  <i class=”fas fa-times”></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

  </div>
</div>
<!-- Modal -->
<div class="modal fade" id="subModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-book me-2"></i><span id="subModalTitle">Add Subject</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="subId" value="0">
    <div class="row g-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Code</label><input type="text" name="code" id="subCode" class="form-control" placeholder="e.g. MTH"></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Subject Name <span class="text-danger">*</span></label><input type="text" name="name" id="subName" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Department</label><input type="text" name="department" id="subDept" class="form-control" list="deptList"><datalist id="deptList"><?php foreach($depts as $d):?><option value="<?=e($d)?>"><?php endforeach;?></datalist></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Pass Mark (%)</label><input type="number" name="pass_mark" id="subPass" class="form-control" min="0" max="100" value="50"></div>
      <div class="col-md-3"><label class="form-label fw-semibold">Type</label><select name="is_elective" id="subElective" class="form-select"><option value="0">Core</option><option value="1">Elective</option></select></div>
      <div class="col-12"><label class="form-label fw-semibold">Description</label><textarea name="description" id="subDesc" class="form-control" rows="2"></textarea></div>
      <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" id="subStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save Subject</button></div>
  </form>
</div></div></div>
<?php $extraJs=<<<JS
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('subModalTitle').textContent='Edit Subject';
  ['id','code','name','department','description','pass_mark','status'].forEach(f=>{const el=document.getElementById('sub'+f.charAt(0).toUpperCase()+f.slice(1).replace('_m','M'));if(el)el.value=this.dataset[f]??'';});
  document.getElementById('subId').value=this.dataset.id;
  document.getElementById('subCode').value=this.dataset.code;
  document.getElementById('subName').value=this.dataset.name;
  document.getElementById('subDept').value=this.dataset.department;
  document.getElementById('subDesc').value=this.dataset.description;
  document.getElementById('subPass').value=this.dataset.pass_mark;
  document.getElementById('subElective').value=this.dataset.is_elective;
  document.getElementById('subStatus').value=this.dataset.status;
  new bootstrap.Modal(document.getElementById('subModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
JS;
require_once __DIR__.'/../../includes/footer.php';?>

