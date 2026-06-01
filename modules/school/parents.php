<?php
require_once __DIR__ . '/_nav.php';

if($_SERVER['REQUEST_METHOD']==='POST'){
    require_once __DIR__.'/../../config/database.php';
    require_once __DIR__.'/../../includes/functions.php';
    if(session_status()===PHP_SESSION_NONE)session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);$user=currentUser();$orgId=(int)$user['org_id'];$action=$_POST['action']??'';

    if($action==='save'){
        $id=(int)($_POST['id']??0);
        $fn=sanitize($_POST['first_name']??'');$ln=sanitize($_POST['last_name']??'');
        $rel=sanitize($_POST['relationship']??'guardian');$phone=sanitize($_POST['phone']??'');
        $email=sanitize($_POST['email']??'');$nid=sanitize($_POST['national_id']??'');
        $occ=sanitize($_POST['occupation']??'');$addr=sanitize($_POST['address']??'');
        $status=sanitize($_POST['status']??'active');
        if(!$fn||!$ln){setFlash('error','First and last name are required.');redirect('parents.php');}
        if($id){
            $pdo->prepare("UPDATE sch_parents SET first_name=?,last_name=?,relationship=?,phone=?,email=?,national_id=?,occupation=?,address=?,status=? WHERE id=? AND org_id=?")
               ->execute([$fn,$ln,$rel,$phone,$email,$nid,$occ,$addr,$status,$id,$orgId]);
            setFlash('success','Parent updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_parents (org_id,first_name,last_name,relationship,phone,email,national_id,occupation,address,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$orgId,$fn,$ln,$rel,$phone,$email,$nid,$occ,$addr,$status]);
            setFlash('success','Parent added.');
        }
        redirect('parents.php');
    }

    if($action==='delete'){
        $id=(int)($_POST['id']??0);
        $pdo->prepare("DELETE FROM sch_student_parents WHERE parent_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM sch_parents WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Parent removed.');redirect('parents.php');
    }

    if($action==='link_student'){
        $parentId=(int)($_POST['parent_id']??0);$studentId=(int)($_POST['student_id']??0);$isPrimary=(int)($_POST['is_primary']??0);
        if($parentId&&$studentId){
            try{$pdo->prepare("INSERT INTO sch_student_parents (student_id,parent_id,is_primary) VALUES (?,?,?) ON DUPLICATE KEY UPDATE is_primary=VALUES(is_primary)")->execute([$studentId,$parentId,$isPrimary]);}catch(Exception $e){}
            setFlash('success','Student linked.');
        }
        redirect("parents.php?view=$parentId");
    }

    if($action==='unlink_student'){
        $parentId=(int)($_POST['parent_id']??0);$studentId=(int)($_POST['student_id']??0);
        $pdo->prepare("DELETE FROM sch_student_parents WHERE parent_id=? AND student_id=?")->execute([$parentId,$studentId]);
        setFlash('success','Student unlinked.');redirect("parents.php?view=$parentId");
    }

    if($action==='toggle_status'){
        $id=(int)($_POST['id']??0);
        $s=$pdo->prepare("SELECT status FROM sch_parents WHERE id=? AND org_id=?");$s->execute([$id,$orgId]);$cur=$s->fetchColumn();
        $new=$cur==='active'?'inactive':'active';
        $pdo->prepare("UPDATE sch_parents SET status=? WHERE id=? AND org_id=?")->execute([$new,$id,$orgId]);
        setFlash('success','Status updated.');redirect('parents.php');
    }

    // ── Parent portal PIN management ──────────────────────────────
    if ($action === 'set_pin') {
        $id  = (int)($_POST['id'] ?? 0);
        $pin = trim($_POST['parent_pin'] ?? '');
        if (!$id) { setFlash('danger','Invalid parent.'); redirect('parents.php'); }
        if (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
            setFlash('danger','PIN must be 4–8 digits (numbers only).');
            redirect('parents.php' . ($viewId ? "?view=$id" : ''));
        }
        try {
            // Add columns if the migration hasn't run yet (silently ignored if they already exist)
            $pdo->exec("ALTER TABLE sch_parents ADD COLUMN parent_pin VARCHAR(255) NULL DEFAULT NULL");
        } catch (Throwable $e) {}
        try {
            $pdo->exec("ALTER TABLE sch_parents ADD COLUMN portal_enabled TINYINT(1) NOT NULL DEFAULT 0");
        } catch (Throwable $e) {}
        $pdo->prepare("UPDATE sch_parents SET parent_pin=?, portal_enabled=1 WHERE id=? AND org_id=?")
            ->execute([password_hash($pin, PASSWORD_BCRYPT), $id, $orgId]);
        setFlash('success', 'Parent portal PIN set. Parent can now sign in using the school portal.');
        redirect('parents.php' . ($id ? "?view=$id" : ''));
    }

    if ($action === 'revoke_pin') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("UPDATE sch_parents SET parent_pin=NULL, portal_enabled=0 WHERE id=? AND org_id=?")
                ->execute([$id, $orgId]);
        } catch (Throwable $e) {}
        setFlash('success', 'Portal access revoked for this parent.');
        redirect('parents.php' . ($id ? "?view=$id" : ''));
    }
}
require_once __DIR__.'/../../includes/header-module.php';
$user=currentUser();$orgId=(int)$user['org_id'];
$viewId=(int)($_GET['view']??0);$search=sanitize($_GET['q']??'');

$parents=[];
try{
    $where='WHERE p.org_id=?';$params=[$orgId];
    if($search){$where.=" AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";$q="%$search%";$params=array_merge($params,[$q,$q,$q,$q]);}
    $s=$pdo->prepare("SELECT p.*,COUNT(sp.student_id) AS linked_count FROM sch_parents p LEFT JOIN sch_student_parents sp ON p.id=sp.parent_id $where GROUP BY p.id ORDER BY p.first_name,p.last_name");
    $s->execute($params);$parents=$s->fetchAll();
}catch(Exception $e){}

$totalParents=count($parents);$activeParents=count(array_filter($parents,fn($p)=>$p['status']==='active'));

$viewParent=null;$linkedStudents=[];$allStudents=[];
if($viewId){
    try{$s=$pdo->prepare("SELECT * FROM sch_parents WHERE id=? AND org_id=?");$s->execute([$viewId,$orgId]);$viewParent=$s->fetch();}catch(Exception $e){}
    if($viewParent){
        try{$s=$pdo->prepare("SELECT st.*,sp.is_primary,CONCAT(st.first_name,' ',st.last_name) AS name,c.name AS class_name FROM sch_student_parents sp JOIN sch_students st ON sp.student_id=st.id LEFT JOIN sch_classes c ON st.class_id=c.id WHERE sp.parent_id=?");$s->execute([$viewId]);$linkedStudents=$s->fetchAll();}catch(Exception $e){}
        try{$s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name,admission_no FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");$s->execute([$orgId]);$allStudents=$s->fetchAll();}catch(Exception $e){}
    }
}
$relColors=['father'=>'primary','mother'=>'danger','guardian'=>'success','other'=>'secondary'];
?>
<?=flashAlert()?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div><h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?=$moduleColor?>"></i>Parents & Guardians</h4><p class="text-muted mb-0">Manage parent/guardian profiles and student links</p></div>
  <?php if(!$viewParent):?>
  <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#parentModal"><i class="fas fa-plus me-2"></i>Add Parent</button>
  <?php else:?>
  <a href="parents.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Parents</a>
  <?php endif;?>
</div>

<?php if(!$viewParent):?>
<div class="row g-3 mb-4">
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?=$totalParents?></div><div class="stat-label">Total Parents</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check"></i></div><div class="stat-body"><div class="stat-value"><?=$activeParents?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-sm-4"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-link"></i></div><div class="stat-body"><div class="stat-value"><?=array_sum(array_column($parents,'linked_count'))?></div><div class="stat-label">Student Links</div></div></div></div>
</div>

<!-- Search -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" value="<?=e($search)?>" placeholder="Name, phone or emailâ€¦"></div>
    <div class="col-auto"><button class="btn btn-sm btn-success">Search</button><a href="parents.php" class="btn btn-sm btn-outline-secondary ms-1">Clear</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Parents/Guardians (<?=count($parents)?>)</h6></div>
  <div class="card-body p-0">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Email</th><th class="text-center">Linked Students</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if(empty($parents)):?><tr><td colspan="7" class="text-center text-muted py-4">No parents found.</td></tr>
    <?php else:foreach($parents as $p):$rc=$relColors[$p['relationship']]??'secondary';?>
    <tr>
      <td class="fw-semibold"><a href="parents.php?view=<?=$p['id']?>" class="text-decoration-none" style="color:<?=$moduleColor?>"><?=e($p['first_name'].' '.$p['last_name'])?></a></td>
      <td><span class="badge bg-<?=$rc?>"><?=ucfirst($p['relationship'])?></span></td>
      <td><?=e($p['phone']??'â€”')?></td>
      <td class="small"><?=e($p['email']??'â€”')?></td>
      <td class="text-center"><span class="badge bg-secondary"><?=$p['linked_count']?></span></td>
      <td><?=$p['status']==='active'?'<span class="badge bg-success">Active</span>':'<span class="badge bg-secondary">Inactive</span>'?></td>
      <td class="text-end">
        <a href="parents.php?view=<?=$p['id']?>" class="btn btn-xs btn-outline-primary me-1"><i class="fas fa-eye"></i></a>
        <button class="btn btn-xs btn-outline-secondary me-1 btn-edit"
          data-id="<?=$p['id']?>" data-fn="<?=e($p['first_name'])?>" data-ln="<?=e($p['last_name'])?>"
          data-rel="<?=$p['relationship']?>" data-phone="<?=e($p['phone']??'')?>" data-email="<?=e($p['email']??'')?>"
          data-nid="<?=e($p['national_id']??'')?>" data-occ="<?=e($p['occupation']??'')?>"
          data-addr="<?=e($p['address']??'')?>" data-status="<?=$p['status']?>"><i class="fas fa-edit"></i></button>
        <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$p['id']?>">
          <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this parent record?"><i class="fas fa-trash"></i></button>
        </form>
      </td>
    </tr>
    <?php endforeach;endif;?>
    </tbody>
  </table>
  </div>
</div>

<?php else:?>
<!-- View Parent -->
<?php $rc=$relColors[$viewParent['relationship']]??'secondary';?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-body text-center pt-4">
        <div class="rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;background:<?=$moduleColor?>20;font-size:2rem;color:<?=$moduleColor?>">
          <i class="fas fa-user"></i>
        </div>
        <h5 class="fw-bold mb-1"><?=e($viewParent['first_name'].' '.$viewParent['last_name'])?></h5>
        <span class="badge bg-<?=$rc?> mb-2"><?=ucfirst($viewParent['relationship'])?></span>
        <div class="text-muted small mt-1">
          <?php if($viewParent['phone']):?><div><i class="fas fa-phone me-1"></i><?=e($viewParent['phone'])?></div><?php endif;?>
          <?php if($viewParent['email']):?><div><i class="fas fa-envelope me-1"></i><?=e($viewParent['email'])?></div><?php endif;?>
          <?php if($viewParent['national_id']):?><div><i class="fas fa-id-card me-1"></i><?=e($viewParent['national_id'])?></div><?php endif;?>
          <?php if($viewParent['occupation']):?><div><i class="fas fa-briefcase me-1"></i><?=e($viewParent['occupation'])?></div><?php endif;?>
          <?php if($viewParent['address']):?><div class="mt-1"><i class="fas fa-map-marker-alt me-1"></i><?=e($viewParent['address'])?></div><?php endif;?>
        </div>
        <div class="mt-3 d-flex gap-2 justify-content-center">
          <button class="btn btn-sm btn-outline-secondary btn-edit"
            data-id="<?=$viewParent['id']?>" data-fn="<?=e($viewParent['first_name'])?>" data-ln="<?=e($viewParent['last_name'])?>"
            data-rel="<?=$viewParent['relationship']?>" data-phone="<?=e($viewParent['phone']??'')?>" data-email="<?=e($viewParent['email']??'')?>"
            data-nid="<?=e($viewParent['national_id']??'')?>" data-occ="<?=e($viewParent['occupation']??'')?>"
            data-addr="<?=e($viewParent['address']??'')?>" data-status="<?=$viewParent['status']?>"><i class="fas fa-edit me-1"></i>Edit</button>
          <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="toggle_status"><input type="hidden" name="id" value="<?=$viewParent['id']?>">
            <button type="submit" class="btn btn-sm btn-outline-<?=$viewParent['status']==='active'?'warning':'success'?>"><?=$viewParent['status']==='active'?'Deactivate':'Activate'?></button>
          </form>
        </div>
      </div>
    </div>

    <!-- Parent Portal Access -->
    <?php
    $portalEnabled = !empty($viewParent['portal_enabled']);
    $hasPin        = !empty($viewParent['parent_pin']);
    // Resolve org slug for portal URL
    $__portalOrgSlug = null;
    try { $__pr = $pdo->prepare("SELECT slug FROM organizations WHERE id=? LIMIT 1"); $__pr->execute([$orgId]); $__portalOrgSlug = $__pr->fetchColumn() ?: null; } catch (Throwable $e) {}
    $portalUrl = $__portalOrgSlug ? APP_URL . '/parent/login.php?org=' . rawurlencode($__portalOrgSlug) : null;
    ?>
    <div class="card mb-3">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-globe me-2" style="color:<?=$moduleColor?>"></i>Parent Portal Access</h6>
        <?php if ($portalEnabled && $hasPin): ?>
        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Enabled</span>
        <?php else: ?>
        <span class="badge bg-secondary">Not set</span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if ($portalEnabled && $hasPin): ?>
        <p class="small text-muted mb-3">This parent can sign in to the parent portal using the student's admission number and their PIN.</p>
        <?php if ($portalUrl): ?>
        <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0">
          <code class="small flex-fill text-truncate" style="font-size:.7rem;color:#0B2D4E"><?= e($portalUrl) ?></code>
          <button class="btn btn-xs btn-success" onclick="navigator.clipboard.writeText('<?= e($portalUrl) ?>');this.textContent='Copied!'">Copy</button>
        </div>
        <?php endif; ?>
        <div class="d-flex gap-2">
          <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#pinModal">
            <i class="fas fa-key me-1"></i>Reset PIN
          </button>
          <form method="POST" class="d-inline">
            <?=csrfField()?>
            <input type="hidden" name="action" value="revoke_pin">
            <input type="hidden" name="id" value="<?=$viewParent['id']?>">
            <button type="submit" class="btn btn-sm btn-outline-danger btn-confirm" data-msg="Revoke portal access for this parent?">
              <i class="fas fa-ban me-1"></i>Revoke
            </button>
          </form>
        </div>
        <?php else: ?>
        <p class="small text-muted mb-3">Grant this parent access to the parent portal so they can view their child's results, fees, and attendance online.</p>
        <button class="btn btn-sm text-white w-100" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#pinModal">
          <i class="fas fa-key me-1"></i>Set Portal PIN
        </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- Link New Student -->
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-link me-2" style="color:<?=$moduleColor?>"></i>Link Student</h6></div>
      <div class="card-body">
        <form method="POST">
          <?=csrfField()?><input type="hidden" name="action" value="link_student"><input type="hidden" name="parent_id" value="<?=$viewParent['id']?>">
          <div class="mb-2"><label class="form-label small fw-semibold">Student</label>
            <select name="student_id" class="form-select form-select-sm"><option value="">â€” Select student â€”</option><?php foreach($allStudents as $st):?><option value="<?=$st['id']?>"><?=e($st['name'])?> (<?=e($st['admission_no']??'')?>)</option><?php endforeach;?></select>
          </div>
          <div class="mb-2 form-check"><input type="checkbox" name="is_primary" value="1" class="form-check-input" id="isPrimary"><label class="form-check-label small" for="isPrimary">Primary guardian</label></div>
          <button type="submit" class="btn btn-sm text-white w-100" style="background:<?=$moduleColor?>"><i class="fas fa-link me-1"></i>Link</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card">
      <div class="card-header"><h6 class="mb-0"><i class="fas fa-user-graduate me-2" style="color:<?=$moduleColor?>"></i>Linked Students (<?=count($linkedStudents)?>)</h6></div>
      <div class="card-body p-0">
      <table class="table table-hover mb-0">
        <thead class="table-light"><tr><th>Student</th><th>Admission No</th><th>Class</th><th class="text-center">Primary?</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php if(empty($linkedStudents)):?><tr><td colspan="5" class="text-center text-muted py-4">No students linked yet.</td></tr>
        <?php else:foreach($linkedStudents as $ls):?>
        <tr>
          <td class="fw-semibold"><a href="students.php?view=<?=$ls['id']?>" class="text-decoration-none" style="color:<?=$moduleColor?>"><?=e($ls['name']??'')?></a></td>
          <td class="small text-muted"><?=e($ls['admission_no']??'â€”')?></td>
          <td><?=e($ls['class_name']??'â€”')?></td>
          <td class="text-center"><?=$ls['is_primary']?'<span class="badge bg-success">Yes</span>':'<span class="badge bg-secondary">No</span>'?></td>
          <td class="text-end">
            <form method="POST" class="d-inline"><?=csrfField()?><input type="hidden" name="action" value="unlink_student"><input type="hidden" name="parent_id" value="<?=$viewParent['id']?>"><input type="hidden" name="student_id" value="<?=$ls['id']?>">
              <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Unlink this student?"><i class="fas fa-unlink"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach;endif;?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
</div>
<?php endif;?>

<!-- Parent Modal -->
<div class="modal fade" id="parentModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header"><h5 class="modal-title"><i class="fas fa-users me-2"></i><span id="parentModalTitle">Add Parent/Guardian</span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
  <form method="POST"><div class="modal-body">
    <?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="parentId" value="0">
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="parentFn" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" id="parentLn" class="form-control" required></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Relationship</label>
        <select name="relationship" id="parentRel" class="form-select"><option value="father">Father</option><option value="mother">Mother</option><option value="guardian" selected>Guardian</option><option value="other">Other</option></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
        <select name="status" id="parentStatus" class="form-select"><option value="active">Active</option><option value="inactive">Inactive</option></select>
      </div>
      <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="text" name="phone" id="parentPhone" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Email</label><input type="email" name="email" id="parentEmail" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">National ID</label><input type="text" name="national_id" id="parentNid" class="form-control"></div>
      <div class="col-md-6"><label class="form-label fw-semibold">Occupation</label><input type="text" name="occupation" id="parentOcc" class="form-control"></div>
      <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" id="parentAddr" class="form-control" rows="2"></textarea></div>
    </div>
  </div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">Save</button></div>
  </form>
</div></div></div>

<!-- ── PIN Modal (shown in view-parent context) ─────────────────── -->
<?php if ($viewParent): ?>
<div class="modal fade" id="pinModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-key me-2" style="color:<?=$moduleColor?>"></i>Set Parent Portal PIN</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?=csrfField()?>
        <input type="hidden" name="action" value="set_pin">
        <input type="hidden" name="id" value="<?=$viewParent['id']?>">
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Set a 4–8 digit numeric PIN for <strong><?= e($viewParent['first_name'] . ' ' . $viewParent['last_name']) ?></strong>.
            Share this PIN with the parent so they can sign in to the parent portal using their child's admission number.
          </p>
          <label class="form-label fw-semibold">PIN <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" name="parent_pin" id="pinInput" class="form-control"
                   placeholder="4–8 digits" pattern="[0-9]{4,8}" inputmode="numeric"
                   maxlength="8" required>
            <button type="button" class="btn btn-outline-secondary"
                    onclick="const i=document.getElementById('pinInput');i.type=i.type==='password'?'text':'password'">
              <i class="fas fa-eye"></i>
            </button>
          </div>
          <div class="form-text">Only numbers allowed. The parent will use this with their child's admission number to log in.</div>
          <?php if (!empty($viewParent['portal_enabled'])): ?>
          <div class="alert alert-warning mt-3 py-2 small">
            <i class="fas fa-exclamation-triangle me-1"></i>This will replace the existing PIN. Inform the parent of their new PIN.
          </div>
          <?php endif; ?>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?=$moduleColor?>">
            <i class="fas fa-save me-2"></i>Save PIN &amp; Enable Access
          </button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<?php ob_start();?>
<script>
document.querySelectorAll('.btn-edit').forEach(btn=>{btn.addEventListener('click',function(){
  document.getElementById('parentModalTitle').textContent='Edit Parent/Guardian';
  document.getElementById('parentId').value=this.dataset.id;
  document.getElementById('parentFn').value=this.dataset.fn||'';
  document.getElementById('parentLn').value=this.dataset.ln||'';
  document.getElementById('parentRel').value=this.dataset.rel||'guardian';
  document.getElementById('parentPhone').value=this.dataset.phone||'';
  document.getElementById('parentEmail').value=this.dataset.email||'';
  document.getElementById('parentNid').value=this.dataset.nid||'';
  document.getElementById('parentOcc').value=this.dataset.occ||'';
  document.getElementById('parentAddr').value=this.dataset.addr||'';
  document.getElementById('parentStatus').value=this.dataset.status||'active';
  new bootstrap.Modal(document.getElementById('parentModal')).show();
});});
document.querySelectorAll('.btn-confirm').forEach(btn=>{btn.addEventListener('click',function(e){if(!confirm(this.dataset.msg||'Are you sure?'))e.preventDefault();});});
</script>
<?php $extraJs=ob_get_clean();
require_once __DIR__.'/../../includes/footer.php';?>

