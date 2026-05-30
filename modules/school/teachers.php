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

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $empId        = sanitize($_POST['employee_id'] ?? '');
        $firstName    = sanitize($_POST['first_name'] ?? '');
        $lastName     = sanitize($_POST['last_name'] ?? '');
        $gender       = in_array($_POST['gender'] ?? '', ['male','female','other']) ? $_POST['gender'] : 'male';
        $dob          = $_POST['dob'] ?? null;
        $nationality  = sanitize($_POST['nationality'] ?? '');
        $passportNo   = sanitize($_POST['passport_no'] ?? '');
        $email        = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
        $phone        = sanitize($_POST['phone'] ?? '');
        $qual         = sanitize($_POST['qualification'] ?? '');
        $spec         = sanitize($_POST['specialization'] ?? '');
        $curriculum   = sanitize($_POST['curriculum'] ?? 'IB');
        $contract     = in_array($_POST['contract_type'] ?? '', ['permanent','contract','part-time','volunteer','visiting']) ? $_POST['contract_type'] : 'permanent';
        $joinDate     = $_POST['join_date'] ?? null;
        $endDate      = $_POST['end_date'] ?: null;
        $emergName    = sanitize($_POST['emergency_contact'] ?? '');
        $emergPhone   = sanitize($_POST['emergency_phone'] ?? '');
        $address      = sanitize($_POST['address'] ?? '');
        $status       = in_array($_POST['status'] ?? '', ['active','on-leave','resigned','terminated']) ? $_POST['status'] : 'active';
        $notes        = sanitize($_POST['notes'] ?? '');

        if (!$firstName || !$lastName) { setFlash('danger', 'First and last name are required.'); redirect('teachers.php'); }

        // Handle photo upload
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) { setFlash('danger','Photo must be JPG, PNG or WebP.'); redirect('teachers.php'); }
            if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { setFlash('danger','Photo must be under 2MB.'); redirect('teachers.php'); }
            $filename = 'teacher_' . $orgId . '_' . time() . '.' . $ext;
            $dest = __DIR__ . '/../../assets/uploads/teachers/' . $filename;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                $photoPath = 'assets/uploads/teachers/' . $filename;
            }
        }

        if ($id > 0) {
            requireOrgOwnership('sch_teachers', $id, $orgId);
            $photoSet = $photoPath ? ', photo=?' : '';
            $params = [$empId,$firstName,$lastName,$gender,$dob?:null,$nationality,$passportNo,$email,$phone,$qual,$spec,$curriculum,$contract,$joinDate?:null,$endDate,$emergName,$emergPhone,$address,$status,$notes,$id,$orgId];
            if ($photoPath) array_splice($params, 20, 0, [$photoPath]);
            $pdo->prepare("UPDATE sch_teachers SET employee_id=?,first_name=?,last_name=?,gender=?,dob=?,nationality=?,passport_no=?,email=?,phone=?,qualification=?,specialization=?,curriculum=?,contract_type=?,join_date=?,end_date=?,emergency_contact=?,emergency_phone=?,address=?,status=?,notes=?{$photoSet} WHERE id=? AND org_id=?")->execute($params);
            setFlash('success', "Teacher '$firstName $lastName' updated.");
        } else {
            $pdo->prepare("INSERT INTO sch_teachers (org_id,employee_id,first_name,last_name,gender,dob,nationality,passport_no,email,phone,qualification,specialization,curriculum,contract_type,join_date,end_date,emergency_contact,emergency_phone,address,status,notes,photo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$empId,$firstName,$lastName,$gender,$dob?:null,$nationality,$passportNo,$email,$phone,$qual,$spec,$curriculum,$contract,$joinDate?:null,$endDate,$emergName,$emergPhone,$address,$status,$notes,$photoPath]);
            setFlash('success', "Teacher '$firstName $lastName' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'school', "Teacher: $firstName $lastName");
        redirect('teachers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_teachers', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_teachers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Teacher record removed.');
        redirect('teachers.php');
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = in_array($_POST['status'] ?? '', ['active','on-leave','resigned','terminated']) ? $_POST['status'] : 'active';
        requireOrgOwnership('sch_teachers', $id, $orgId);
        $pdo->prepare("UPDATE sch_teachers SET status=? WHERE id=? AND org_id=?")->execute([$st, $id, $orgId]);
        setFlash('success', 'Status updated.');
        redirect('teachers.php');
    }
}

// ── GET Handlers ──────────────────────────────────────────────────
if (isset($_GET['fetch']) && is_numeric($_GET['fetch'])) {
    $row = $pdo->prepare("SELECT * FROM sch_teachers WHERE id=? AND org_id=?");
    $row->execute([(int)$_GET['fetch'], $orgId]);
    header('Content-Type: application/json');
    echo json_encode($row->fetch(PDO::FETCH_ASSOC) ?: []);
    exit;
}

// ── Load data ─────────────────────────────────────────────────────
$search   = sanitize($_GET['q'] ?? '');
$fStatus  = sanitize($_GET['status'] ?? '');
$fCurr    = sanitize($_GET['curriculum'] ?? '');

$where = 'org_id=?'; $params = [$orgId];
if ($search)  { $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR employee_id LIKE ?)'; $q="%$search%"; array_push($params,$q,$q,$q,$q); }
if ($fStatus) { $where .= ' AND status=?'; $params[] = $fStatus; }
if ($fCurr)   { $where .= ' AND FIND_IN_SET(?,curriculum)'; $params[] = $fCurr; }

$teachers = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM sch_teachers WHERE $where ORDER BY first_name,last_name");
    $stmt->execute($params); $teachers = $stmt->fetchAll();
} catch (Exception $e) {}

$stats = ['total'=>0,'active'=>0,'on_leave'=>0,'resigned'=>0];
try {
    $s = $pdo->prepare("SELECT status,COUNT(*) c FROM sch_teachers WHERE org_id=? GROUP BY status"); $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) { $stats['total'] += $r['c']; $key = str_replace('-','_',$r['status']); $stats[$key] = (int)$r['c']; }
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chalkboard-teacher me-2" style="color:<?= $moduleColor ?>"></i>Teacher Management</h4>
    <p class="text-muted mb-0">Manage teaching staff profiles, qualifications, contracts and curriculum assignments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openAdd()" data-bs-toggle="modal" data-bs-target="#teacherModal">
    <i class="fas fa-plus me-2"></i>Add Teacher
  </button>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-users"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Total Teachers</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['active'] ?></div><div class="stat-label">Active</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-pause-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['on_leave'] ?></div><div class="stat-label">On Leave</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-user-times"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['resigned'] ?></div><div class="stat-label">Resigned / Left</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search</label><input type="text" name="q" class="form-control form-control-sm" placeholder="Name, email, employee ID…" value="<?= e($search) ?>"></div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach(['active','on-leave','resigned','terminated'] as $st): ?><option value="<?=$st?>" <?=$fStatus===$st?'selected':''?>><?=ucfirst($st)?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-sm-2"><label class="form-label small fw-semibold mb-1">Curriculum</label>
      <select name="curriculum" class="form-select form-select-sm">
        <option value="">All</option>
        <?php foreach(['IB','IGCSE','Cambridge','CBC','AP','Other'] as $c): ?><option value="<?=$c?>" <?=$fCurr===$c?'selected':''?>><?=$c?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button> <a href="teachers.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<!-- Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?=$moduleColor?>"></i>Teaching Staff (<?=count($teachers)?>)</h6>
  </div>
  <div class="card-body p-0">
  <div class="table-responsive">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Photo</th><th>Teacher</th><th>Curriculum</th><th>Qualification</th><th>Contract</th><th>Email / Phone</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($teachers)): ?>
    <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-chalkboard-teacher fa-3x mb-2 d-block opacity-25"></i>No teachers added yet. Click "Add Teacher" to get started.</td></tr>
    <?php else: foreach ($teachers as $t):
      $stColors = ['active'=>'success','on-leave'=>'warning','resigned'=>'secondary','terminated'=>'danger'];
      $stColor = $stColors[$t['status']] ?? 'secondary';
      $photoUrl = $t['photo'] ? APP_URL . '/' . e($t['photo']) : null;
    ?>
    <tr>
      <td>
        <?php if ($photoUrl): ?>
        <img src="<?=$photoUrl?>" class="rounded-circle" width="40" height="40" style="object-fit:cover" alt="">
        <?php else: ?>
        <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold" style="width:40px;height:40px;font-size:.85rem"><?=strtoupper(substr($t['first_name'],0,1).substr($t['last_name'],0,1))?></div>
        <?php endif; ?>
      </td>
      <td>
        <div class="fw-semibold"><?=e($t['first_name'].' '.$t['last_name'])?></div>
        <small class="text-muted"><?=e($t['employee_id'] ?: '—')?> · <?=ucfirst($t['nationality']?:'—')?></small>
      </td>
      <td><?php foreach(explode(',',$t['curriculum']?:'IB') as $c): ?><span class="badge bg-primary me-1"><?=trim($c)?></span><?php endforeach; ?></td>
      <td class="small text-muted"><?=e(mb_strimwidth($t['qualification']??'—',0,50,'…'))?></td>
      <td><span class="badge bg-light text-dark border"><?=ucfirst($t['contract_type']??'')?></span></td>
      <td class="small"><?=e($t['email']??'—')?><br><span class="text-muted"><?=e($t['phone']??'')?></span></td>
      <td><span class="badge bg-<?=$stColor?>"><?=ucfirst(str_replace('-',' ',$t['status']))?></span></td>
      <td class="text-center">
        <div class="btn-group btn-group-sm">
          <button class="btn btn-outline-primary" onclick="openEdit(<?=$t['id']?>)" data-bs-toggle="modal" data-bs-target="#teacherModal" title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-outline-danger" onclick="delTeacher(<?=$t['id']?>,'<?=e($t['first_name'].' '.$t['last_name'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div></div>
</div>

<!-- Teacher Modal -->
<div class="modal fade" id="teacherModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST" enctype="multipart/form-data">
    <?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="tchId" value="0">
    <div class="modal-header text-white" style="background:<?=$moduleColor?>">
      <h5 class="modal-title" id="tchTitle"><i class="fas fa-chalkboard-teacher me-2"></i>Add Teacher</h5>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
      <ul class="nav nav-tabs mb-3" id="tchTabs">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-personal">Personal</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-professional">Professional</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-contact">Contact & Emergency</button></li>
      </ul>
      <div class="tab-content">
        <!-- Personal Tab -->
        <div class="tab-pane fade show active" id="tab-personal">
          <div class="row g-3">
            <div class="col-md-2 text-center">
              <label class="form-label fw-semibold">Photo</label>
              <div class="mb-2"><img id="tchPhotoPreview" src="" class="rounded-circle" width="80" height="80" style="object-fit:cover;display:none;border:3px solid #1A8A4E"></div>
              <div id="tchAvatarDefault" class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto fw-bold" style="width:80px;height:80px;font-size:1.4rem">T</div>
              <input type="file" name="photo" id="tchPhoto" class="form-control form-control-sm mt-2" accept="image/*" onchange="previewTchPhoto(this)">
              <small class="text-muted">JPG/PNG, max 2MB</small>
            </div>
            <div class="col-md-10">
              <div class="row g-3">
                <div class="col-md-3"><label class="form-label fw-semibold">Employee ID</label><input type="text" name="employee_id" id="tchEmpId" class="form-control" placeholder="TCH-001"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label><input type="text" name="first_name" id="tchFirst" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label><input type="text" name="last_name" id="tchLast" class="form-control" required></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Gender</label>
                  <select name="gender" id="tchGender" class="form-select"><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option></select>
                </div>
                <div class="col-md-3"><label class="form-label fw-semibold">Date of Birth</label><input type="date" name="dob" id="tchDob" class="form-control"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Nationality</label><input type="text" name="nationality" id="tchNationality" class="form-control" placeholder="e.g. British"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Passport No</label><input type="text" name="passport_no" id="tchPassport" class="form-control"></div>
                <div class="col-md-3"><label class="form-label fw-semibold">Status</label>
                  <select name="status" id="tchStatus" class="form-select">
                    <?php foreach(['active','on-leave','resigned','terminated'] as $st): ?><option value="<?=$st?>"><?=ucfirst($st)?></option><?php endforeach; ?>
                  </select>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- Professional Tab -->
        <div class="tab-pane fade" id="tab-professional">
          <div class="row g-3">
            <div class="col-12"><label class="form-label fw-semibold">Qualifications</label><input type="text" name="qualification" id="tchQual" class="form-control" placeholder="e.g. B.Ed Mathematics, MSc Education Management"></div>
            <div class="col-12"><label class="form-label fw-semibold">Specialization</label><input type="text" name="specialization" id="tchSpec" class="form-control" placeholder="e.g. IB Mathematics HL, IGCSE Physics, AS Chemistry"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Curriculum</label>
              <select name="curriculum" id="tchCurr" class="form-select">
                <?php foreach(['IB','IGCSE','Cambridge','CBC','AP','Other'] as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach; ?>
              </select>
              <small class="text-muted">Select primary curriculum</small>
            </div>
            <div class="col-md-4"><label class="form-label fw-semibold">Contract Type</label>
              <select name="contract_type" id="tchContract" class="form-select">
                <?php foreach(['permanent','contract','part-time','volunteer','visiting'] as $ct): ?><option value="<?=$ct?>"><?=ucfirst($ct)?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-2"><label class="form-label fw-semibold">Join Date</label><input type="date" name="join_date" id="tchJoin" class="form-control"></div>
            <div class="col-md-2"><label class="form-label fw-semibold">End Date</label><input type="date" name="end_date" id="tchEnd" class="form-control"></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" id="tchNotes" class="form-control" rows="2" placeholder="Additional notes…"></textarea></div>
          </div>
        </div>
        <!-- Contact Tab -->
        <div class="tab-pane fade" id="tab-contact">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Email <span class="text-danger">*</span></label><input type="email" name="email" id="tchEmail" class="form-control"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Phone</label><input type="tel" name="phone" id="tchPhone" class="form-control" placeholder="+254 700 000 000"></div>
            <div class="col-12"><label class="form-label fw-semibold">Address</label><textarea name="address" id="tchAddress" class="form-control" rows="2"></textarea></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Emergency Contact Name</label><input type="text" name="emergency_contact" id="tchEmergName" class="form-control"></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Emergency Contact Phone</label><input type="tel" name="emergency_phone" id="tchEmergPhone" class="form-control"></div>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
      <button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-1"></i>Save Teacher</button>
    </div>
  </form>
</div></div></div>

<form method="POST" id="delTchForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delTchId"></form>

<?php ob_start(); ?>
<script>
function previewTchPhoto(input) {
  if (input.files && input.files[0]) {
    var r = new FileReader();
    r.onload = e => {
      document.getElementById('tchPhotoPreview').src = e.target.result;
      document.getElementById('tchPhotoPreview').style.display = '';
      document.getElementById('tchAvatarDefault').style.display = 'none';
    };
    r.readAsDataURL(input.files[0]);
  }
}
function openAdd() {
  document.getElementById('tchTitle').innerHTML = '<i class="fas fa-chalkboard-teacher me-2"></i>Add Teacher';
  const fields = ['tchId','tchEmpId','tchFirst','tchLast','tchDob','tchNationality','tchPassport','tchQual','tchSpec','tchJoin','tchEnd','tchEmail','tchPhone','tchAddress','tchEmergName','tchEmergPhone','tchNotes'];
  fields.forEach(f => { const el = document.getElementById(f); if(el) el.value = ''; });
  document.getElementById('tchGender').value = 'male';
  document.getElementById('tchCurr').value = 'IB';
  document.getElementById('tchContract').value = 'permanent';
  document.getElementById('tchStatus').value = 'active';
  document.getElementById('tchPhotoPreview').style.display = 'none';
  document.getElementById('tchAvatarDefault').style.display = '';
}
function openEdit(id) {
  fetch('teachers.php?fetch=' + id).then(r => r.json()).then(d => {
    if (!d.id) return;
    document.getElementById('tchTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Teacher';
    document.getElementById('tchId').value = d.id;
    document.getElementById('tchEmpId').value = d.employee_id || '';
    document.getElementById('tchFirst').value = d.first_name;
    document.getElementById('tchLast').value = d.last_name;
    document.getElementById('tchGender').value = d.gender;
    document.getElementById('tchDob').value = d.dob || '';
    document.getElementById('tchNationality').value = d.nationality || '';
    document.getElementById('tchPassport').value = d.passport_no || '';
    document.getElementById('tchQual').value = d.qualification || '';
    document.getElementById('tchSpec').value = d.specialization || '';
    document.getElementById('tchCurr').value = (d.curriculum||'IB').split(',')[0];
    document.getElementById('tchContract').value = d.contract_type;
    document.getElementById('tchJoin').value = d.join_date || '';
    document.getElementById('tchEnd').value = d.end_date || '';
    document.getElementById('tchStatus').value = d.status;
    document.getElementById('tchEmail').value = d.email || '';
    document.getElementById('tchPhone').value = d.phone || '';
    document.getElementById('tchAddress').value = d.address || '';
    document.getElementById('tchEmergName').value = d.emergency_contact || '';
    document.getElementById('tchEmergPhone').value = d.emergency_phone || '';
    document.getElementById('tchNotes').value = d.notes || '';
    if (d.photo) {
      document.getElementById('tchPhotoPreview').src = '<?= APP_URL ?>/' + d.photo;
      document.getElementById('tchPhotoPreview').style.display = '';
      document.getElementById('tchAvatarDefault').style.display = 'none';
    } else {
      document.getElementById('tchPhotoPreview').style.display = 'none';
      document.getElementById('tchAvatarDefault').style.display = '';
    }
  });
}
function delTeacher(id, name) {
  if (confirm('Remove teacher "' + name + '"? This cannot be undone.')) {
    document.getElementById('delTchId').value = id;
    document.getElementById('delTchForm').submit();
  }
}
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
