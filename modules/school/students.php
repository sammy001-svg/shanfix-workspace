<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user = currentUser();
$orgId = (int)$user['org_id'];

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $admNo = sanitize($_POST['admission_no'] ?? '');
        $firstName = sanitize($_POST['first_name'] ?? '');
        $lastName = sanitize($_POST['last_name'] ?? '');
        $gender = in_array($_POST['gender'] ?? '', ['male', 'female']) ? $_POST['gender'] : 'male';
        $dob = $_POST['dob'] ?? date('Y-m-d', strtotime('-10 years'));
        $classId = (int)($_POST['class_id'] ?? 0) ?: null;
        $parentName = sanitize($_POST['parent_name'] ?? '');
        $parentPhone = sanitize($_POST['parent_phone'] ?? '');
        $parentEmail = sanitize($_POST['parent_email'] ?? '');
        $address = sanitize($_POST['address'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['active', 'inactive', 'graduated', 'transferred']) ? $_POST['status'] : 'active';
        $admittedOn = $_POST['admitted_on'] ?? date('Y-m-d');

        // New International Fields
        $nationality = sanitize($_POST['nationality'] ?? '');
        $passportNo = sanitize($_POST['passport_no'] ?? '');
        $visaExpiry = $_POST['visa_expiry'] ?: null;
        $curriculum = in_array($_POST['curriculum'] ?? '', ['IB','IGCSE','Cambridge','CBC','AP','Other']) ? $_POST['curriculum'] : 'IB';
        $motherTongue = sanitize($_POST['mother_tongue'] ?? '');
        $prevSchool = sanitize($_POST['previous_school'] ?? '');
        $medNotes = sanitize($_POST['medical_conditions'] ?? '');
        $learningSupport = (int)($_POST['learning_support'] ?? 0);
        $emergName = sanitize($_POST['emergency_contact'] ?? '');
        $emergPhone = sanitize($_POST['emergency_phone'] ?? '');

        // Photo Upload Handling
        $photoPath = null;
        if (!empty($_FILES['photo']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                if ($_FILES['photo']['size'] <= 2 * 1024 * 1024) {
                    $filename = 'student_' . $orgId . '_' . time() . '.' . $ext;
                    $dest = __DIR__ . '/../../assets/uploads/students/' . $filename;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                        $photoPath = 'assets/uploads/students/' . $filename;
                    }
                }
            }
        }

        try {
            if ($id > 0) {
                requireOrgOwnership('sch_students', $id, $orgId);
                $photoSet = $photoPath ? ", photo=?" : "";
                $params = [
                    $admNo, $firstName, $lastName, $gender, $dob, $classId,
                    $parentName, $parentPhone, $parentEmail, $address, $status, $admittedOn,
                    $nationality, $passportNo, $visaExpiry, $curriculum, $motherTongue, $prevSchool,
                    $medNotes, $learningSupport, $emergName, $emergPhone, $id, $orgId
                ];
                if ($photoPath) array_splice($params, 22, 0, [$photoPath]);
                $sql = "UPDATE sch_students SET
                        admission_no=?, first_name=?, last_name=?, gender=?, dob=?, class_id=?,
                        parent_name=?, parent_phone=?, parent_email=?, address=?, status=?, admitted_on=?,
                        nationality=?, passport_no=?, visa_expiry=?, curriculum=?, mother_tongue=?, previous_school=?,
                        medical_conditions=?, learning_support=?, emergency_contact=?, emergency_phone=? {$photoSet}
                        WHERE id=? AND org_id=?";
                $pdo->prepare($sql)->execute($params);
                $studentId = $id;
                setFlash('success', 'Student details updated successfully.');
            } else {
                $sql = "INSERT INTO sch_students (
                            org_id, admission_no, first_name, last_name, gender, dob, class_id,
                            parent_name, parent_phone, parent_email, address, status, admitted_on,
                            nationality, passport_no, visa_expiry, curriculum, mother_tongue, previous_school,
                            medical_conditions, learning_support, emergency_contact, emergency_phone, photo
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([
                    $orgId, $admNo, $firstName, $lastName, $gender, $dob, $classId,
                    $parentName, $parentPhone, $parentEmail, $address, $status, $admittedOn,
                    $nationality, $passportNo, $visaExpiry, $curriculum, $motherTongue, $prevSchool,
                    $medNotes, $learningSupport, $emergName, $emergPhone, $photoPath
                ]);
                $studentId = (int)$pdo->lastInsertId();
                setFlash('success', "Student '$firstName $lastName' enrolled successfully.");
            }

            // ── Auto-sync parent info → sch_parents + sch_student_parents ──
            if ($parentName && $studentId) {
                $nameParts = explode(' ', trim($parentName), 2);
                $parFn = trim($nameParts[0]);
                $parLn = trim($nameParts[1] ?? '');

                // Try to find an existing parent by phone or email
                $existingParId = null;
                if ($parentPhone || $parentEmail) {
                    $matchConds = []; $matchParams = [$orgId];
                    if ($parentPhone) { $matchConds[] = 'phone=?';  $matchParams[] = $parentPhone; }
                    if ($parentEmail) { $matchConds[] = 'email=?';  $matchParams[] = $parentEmail; }
                    $ep = $pdo->prepare("SELECT id FROM sch_parents WHERE org_id=? AND (" . implode(' OR ', $matchConds) . ") LIMIT 1");
                    $ep->execute($matchParams);
                    $existingParId = $ep->fetchColumn() ?: null;
                }
                // Fallback: match by full name within the same org
                if (!$existingParId && $parFn && $parLn) {
                    $np = $pdo->prepare("SELECT id FROM sch_parents WHERE org_id=? AND first_name=? AND last_name=? LIMIT 1");
                    $np->execute([$orgId, $parFn, $parLn]);
                    $existingParId = $np->fetchColumn() ?: null;
                }

                if ($existingParId) {
                    // Keep name in sync; preserve other fields the parent module may have filled in
                    $pdo->prepare("UPDATE sch_parents SET first_name=?, last_name=? WHERE id=? AND org_id=?")
                        ->execute([$parFn, $parLn, $existingParId, $orgId]);
                } else {
                    $pdo->prepare("INSERT INTO sch_parents (org_id,first_name,last_name,relationship,phone,email,status) VALUES (?,?,?,'guardian',?,?,'active')")
                        ->execute([$orgId, $parFn, $parLn, $parentPhone ?: null, $parentEmail ?: null]);
                    $existingParId = (int)$pdo->lastInsertId();
                }

                // Link student ↔ parent (upsert; mark as primary guardian)
                $pdo->prepare("INSERT INTO sch_student_parents (student_id,parent_id,is_primary) VALUES (?,?,1) ON DUPLICATE KEY UPDATE is_primary=1")
                    ->execute([$studentId, $existingParId]);
            }

            logActivity($id > 0 ? 'update' : 'create', 'school', "Student: $firstName $lastName (Adm: $admNo)");
        } catch (Throwable $e) {
            error_log('[school/students save] ' . $e->getMessage());
            setFlash('danger', 'Could not save student. Please run database/school_module_migration.sql first, then try again.');
        }
        redirect('students.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_students', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_students WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Student record removed.');
        redirect('students.php');
    }
}

// ── GET Handlers ──────────────────────────────────────────────────
if (isset($_GET['fetch_details'])) {
    $sid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM sch_students WHERE id = ? AND org_id = ?");
        $stmt->execute([$sid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}

// Filters and loaders
$fClass = $_GET['class_id'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fCurr = $_GET['curriculum'] ?? '';
$fQ = trim($_GET['q'] ?? '');

$where = 's.org_id = ?';
$params = [$orgId];

if ($fClass !== '') {
    $where .= ' AND s.class_id = ?';
    $params[] = $fClass;
}
if ($fStatus !== '') {
    $where .= ' AND s.status = ?';
    $params[] = $fStatus;
}
if ($fCurr !== '') {
    $where .= ' AND s.curriculum = ?';
    $params[] = $fCurr;
}
if ($fQ !== '') {
    $where .= ' AND (s.admission_no LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ? OR s.parent_name LIKE ?)';
    $like = "%$fQ%";
    array_push($params, $like, $like, $like, $like);
}

$studentsList = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, c.name AS class_name 
                           FROM sch_students s 
                           LEFT JOIN sch_classes c ON s.class_id = c.id 
                           WHERE $where 
                           ORDER BY s.admission_no ASC");
    $stmt->execute($params);
    $studentsList = $stmt->fetchAll();
} catch (Exception $e) {}

$classesList = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $classesList = $stmt->fetchAll();
} catch (Exception $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-graduate me-2" style="color:<?= $moduleColor ?>"></i>Student Directory</h4>
    <p class="text-muted mb-0">Enroll new students, assign curriculum plans, and manage parent profiles</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#stdModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Enroll Student</button>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Search Directory</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Admission number, name..." value="<?= e($fQ) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Academic Class</label>
        <select name="class_id" class="form-select form-select-sm">
          <option value="">All Classes</option>
          <?php foreach ($classesList as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $fClass == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Curriculum</label>
        <select name="curriculum" class="form-select form-select-sm">
          <option value="">All Curricula</option>
          <?php foreach(['IB','IGCSE','Cambridge','CBC','AP','Other'] as $c): ?><option value="<?=$c?>" <?=$fCurr===$c?'selected':''?>><?=$c?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <option value="active" <?= $fStatus === 'active' ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= $fStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
          <option value="graduated" <?= $fStatus === 'graduated' ? 'selected' : '' ?>>Graduated</option>
          <option value="transferred" <?= $fStatus === 'transferred' ? 'selected' : '' ?>>Transferred</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="students.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Grid / Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-user-graduate me-2 text-success"></i>Students List</h6>
    <span class="badge bg-secondary"><?= count($studentsList) ?> registered</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Photo</th>
            <th>Adm No</th>
            <th>Student Name</th>
            <th>Class</th>
            <th>Curriculum</th>
            <th>Parent / Guardian</th>
            <th>Nationality</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($studentsList)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No students found in directory.</td></tr>
          <?php else: foreach ($studentsList as $s): 
            $badges = ['active' => 'success', 'inactive' => 'secondary', 'graduated' => 'primary', 'transferred' => 'warning'];
            $bg = $badges[$s['status']] ?? 'info';
            $photoUrl = $s['photo'] ? APP_URL . '/' . e($s['photo']) : null;
          ?>
          <tr>
            <td>
              <?php if ($photoUrl): ?>
              <img src="<?=$photoUrl?>" class="rounded-circle" width="38" height="38" style="object-fit:cover" alt="">
              <?php else: ?>
              <div class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center fw-bold" style="width:38px;height:38px;font-size:.8rem"><?=strtoupper(substr($s['first_name'],0,1).substr($s['last_name'],0,1))?></div>
              <?php endif; ?>
            </td>
            <td class="fw-semibold text-dark"><?= e($s['admission_no'] ?: '—') ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
              <small class="text-muted"><i class="fas fa-baby me-1"></i>DOB: <?= formatDate($s['dob']) ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($s['class_name'] ?: 'Unassigned') ?></span></td>
            <td><span class="badge bg-primary"><?= e($s['curriculum'] ?: 'IB') ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($s['parent_name'] ?: '—') ?></div>
              <small class="text-muted"><?= e($s['parent_phone'] ?: '') ?></small>
            </td>
            <td><?= e(ucfirst($s['nationality'] ?: '—')) ?></td>
            <td><span class="badge bg-<?= $bg ?>"><?= ucfirst($s['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $s['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <a href="admission-letter-pdf.php?student_id=<?= $s['id'] ?>" target="_blank" class="btn btn-outline-success" title="Print Admission Letter"><i class="fas fa-file-alt"></i></a>
                <button class="btn btn-outline-danger" onclick="delStudent(<?= $s['id'] ?>, '<?= e($s['first_name'] . ' ' . $s['last_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- tabbed Modal -->
<div class="modal fade" id="stdModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST" enctype="multipart/form-data"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="stdId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="stdTitle"><i class="fas fa-user-graduate me-2"></i>Enroll Student</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <!-- Tabs Header -->
    <ul class="nav nav-tabs mb-3" id="studentTabs">
      <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-basic" type="button">Basic Details</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-intl" type="button">International & Medical</button></li>
      <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-family" type="button">Parent & Emergency</button></li>
    </ul>

    <!-- Tabs Content -->
    <div class="tab-content">
      <!-- Basic Details -->
      <div class="tab-pane fade show active" id="tab-basic">
        <div class="row g-3">
          <div class="col-md-3 text-center">
            <label class="form-label fw-semibold">Student Photo</label>
            <div class="mb-2"><img id="stdPhotoPreview" src="" class="rounded-circle mx-auto" width="90" height="90" style="object-fit:cover;display:none;border:3px solid #1A8A4E"></div>
            <div id="stdAvatarDefault" class="rounded-circle bg-success text-white d-flex align-items-center justify-content-center mx-auto fw-bold" style="width:90px;height:90px;font-size:1.6rem">S</div>
            <input type="file" name="photo" id="stdPhoto" class="form-control form-control-sm mt-2" accept="image/*" onchange="previewPhoto(this)">
          </div>
          <div class="col-md-9">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Admission No <span class="text-danger">*</span></label>
                <input type="text" name="admission_no" id="stdAdm" class="form-control" required placeholder="e.g. ADM-2026-004">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Academic Class</label>
                <select name="class_id" id="stdClass" class="form-select">
                  <option value="">-- unassigned --</option>
                  <?php foreach ($classesList as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" id="stdFirst" class="form-control" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" id="stdLast" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Gender <span class="text-danger">*</span></label>
                <select name="gender" id="stdGender" class="form-select" required>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Date of Birth <span class="text-danger">*</span></label>
                <input type="date" name="dob" id="stdDob" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Enrollment Date</label>
                <input type="date" name="admitted_on" id="stdAdmitted" class="form-control">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" id="stdStatus" class="form-select">
                  <option value="active">Active</option>
                  <option value="inactive">Inactive</option>
                  <option value="graduated">Graduated</option>
                  <option value="transferred">Transferred</option>
                </select>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- International & Medical -->
      <div class="tab-pane fade" id="tab-intl">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Nationality</label>
            <input type="text" name="nationality" id="stdNat" class="form-control" placeholder="e.g. Liberian, Kenyan">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Passport Number</label>
            <input type="text" name="passport_no" id="stdPass" class="form-control" placeholder="e.g. PP12345">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Visa Expiry Date</label>
            <input type="date" name="visa_expiry" id="stdVisa" class="form-control">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Target Curriculum</label>
            <select name="curriculum" id="stdCurr" class="form-select">
              <?php foreach(['IB','IGCSE','Cambridge','CBC','AP','Other'] as $c): ?><option value="<?=$c?>"><?=$c?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Mother Tongue</label>
            <input type="text" name="mother_tongue" id="stdTongue" class="form-control" placeholder="e.g. French, English">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Previous School</label>
            <input type="text" name="previous_school" id="stdPrev" class="form-control" placeholder="e.g. Nairobi Intl Academy">
          </div>
          <div class="col-md-8">
            <label class="form-label fw-semibold">Medical Conditions & Allergies</label>
            <textarea name="medical_conditions" id="stdMed" class="form-control" rows="2" placeholder="e.g. Asthma, Peanuts allergy..."></textarea>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Learning Support Required</label>
            <select name="learning_support" id="stdLearn" class="form-select">
              <option value="0">No</option>
              <option value="1">Yes</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Parent & Emergency -->
      <div class="tab-pane fade" id="tab-family">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label fw-semibold">Parent Full Name <span class="text-danger">*</span></label>
            <input type="text" name="parent_name" id="stdParentName" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Parent Phone <span class="text-danger">*</span></label>
            <input type="tel" name="parent_phone" id="stdParentPhone" class="form-control" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Parent Email</label>
            <input type="email" name="parent_email" id="stdParentEmail" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Residential / Home Address</label>
            <textarea name="address" id="stdAddress" class="form-control" rows="2"></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Emergency Contact Name</label>
            <input type="text" name="emergency_contact" id="stdEmergName" class="form-control" placeholder="Different from parent if possible">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Emergency Contact Phone</label>
            <input type="tel" name="emergency_phone" id="stdEmergPhone" class="form-control">
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Enroll Student</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delStdForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delStdId">
</form>

<?php ob_start(); ?>
<script>
function previewPhoto(input) {
  if (input.files && input.files[0]) {
    var reader = new FileReader();
    reader.onload = function(e) {
      document.getElementById('stdPhotoPreview').src = e.target.result;
      document.getElementById('stdPhotoPreview').style.display = 'block';
      document.getElementById('stdAvatarDefault').style.display = 'none';
    }
    reader.readAsDataURL(input.files[0]);
  }
}

function openAdd() {
  document.getElementById('stdTitle').innerHTML = '<i class="fas fa-user-graduate me-2"></i>Enroll Student';
  document.getElementById('stdId').value = '0';
  document.getElementById('stdAdm').value = '';
  document.getElementById('stdFirst').value = '';
  document.getElementById('stdLast').value = '';
  document.getElementById('stdGender').value = 'male';
  document.getElementById('stdClass').value = '';
  document.getElementById('stdStatus').value = 'active';
  
  const now = new Date().toISOString().split('T')[0];
  document.getElementById('stdAdmitted').value = now;
  document.getElementById('stdDob').value = new Date(new Date().setFullYear(new Date().getFullYear() - 10)).toISOString().split('T')[0];
  
  document.getElementById('stdParentName').value = '';
  document.getElementById('stdParentPhone').value = '';
  document.getElementById('stdParentEmail').value = '';
  document.getElementById('stdAddress').value = '';

  // New fields reset
  document.getElementById('stdNat').value = '';
  document.getElementById('stdPass').value = '';
  document.getElementById('stdVisa').value = '';
  document.getElementById('stdCurr').value = 'IB';
  document.getElementById('stdTongue').value = '';
  document.getElementById('stdPrev').value = '';
  document.getElementById('stdMed').value = '';
  document.getElementById('stdLearn').value = '0';
  document.getElementById('stdEmergName').value = '';
  document.getElementById('stdEmergPhone').value = '';

  document.getElementById('stdPhotoPreview').style.display = 'none';
  document.getElementById('stdAvatarDefault').style.display = 'block';
  
  // Show first tab
  var firstTabEl = document.querySelector('#studentTabs button[data-bs-target="#tab-basic"]');
  var tab = new bootstrap.Tab(firstTabEl);
  tab.show();
}

function openEdit(id) {
  fetch('students.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('stdTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Student Profile';
      document.getElementById('stdId').value = data.id;
      document.getElementById('stdAdm').value = data.admission_no || '';
      document.getElementById('stdFirst').value = data.first_name;
      document.getElementById('stdLast').value = data.last_name;
      document.getElementById('stdGender').value = data.gender;
      document.getElementById('stdDob').value = data.dob;
      document.getElementById('stdClass').value = data.class_id || '';
      document.getElementById('stdStatus').value = data.status;
      document.getElementById('stdAdmitted').value = data.admitted_on || '';
      
      document.getElementById('stdParentName').value = data.parent_name || '';
      document.getElementById('stdParentPhone').value = data.parent_phone || '';
      document.getElementById('stdParentEmail').value = data.parent_email || '';
      document.getElementById('stdAddress').value = data.address || '';

      // New international fields assignment
      document.getElementById('stdNat').value = data.nationality || '';
      document.getElementById('stdPass').value = data.passport_no || '';
      document.getElementById('stdVisa').value = data.visa_expiry || '';
      document.getElementById('stdCurr').value = data.curriculum || 'IB';
      document.getElementById('stdTongue').value = data.mother_tongue || '';
      document.getElementById('stdPrev').value = data.previous_school || '';
      document.getElementById('stdMed').value = data.medical_conditions || '';
      document.getElementById('stdLearn').value = data.learning_support || '0';
      document.getElementById('stdEmergName').value = data.emergency_contact || '';
      document.getElementById('stdEmergPhone').value = data.emergency_phone || '';

      if (data.photo) {
        document.getElementById('stdPhotoPreview').src = '<?= APP_URL ?>/' + data.photo;
        document.getElementById('stdPhotoPreview').style.display = 'block';
        document.getElementById('stdAvatarDefault').style.display = 'none';
      } else {
        document.getElementById('stdPhotoPreview').style.display = 'none';
        document.getElementById('stdAvatarDefault').style.display = 'block';
      }
      
      var firstTabEl = document.querySelector('#studentTabs button[data-bs-target="#tab-basic"]');
      var tab = new bootstrap.Tab(firstTabEl);
      tab.show();

      new bootstrap.Modal(document.getElementById('stdModal')).show();
    });
}

function delStudent(id, name) {
  if (confirm('Permanently delete "' + name + '"? This will also clear all grading rosters and fee history.')) {
    document.getElementById('delStdId').value = id;
    document.getElementById('delStdForm').submit();
  }
}
</script>
<?php 
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
