<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'update_contact') {
        $id    = (int)($_POST['id'] ?? 0);
        $phone = sanitize($_POST['phone'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $currentCity  = sanitize($_POST['current_city'] ?? '');
        $currentEmployer = sanitize($_POST['current_employer'] ?? '');
        $notes = sanitize($_POST['alumni_notes'] ?? '');
        requireOrgOwnership('sch_students', $id, $orgId);
        $pdo->prepare("UPDATE sch_students SET phone=?,email=?,current_city=?,current_employer=?,alumni_notes=? WHERE id=? AND org_id=?")
            ->execute([$phone,$email,$currentCity,$currentEmployer,$notes,$id,$orgId]);
        setFlash('success','Alumni record updated.');
        redirect('alumni.php');
    }

    if ($action === 're_enroll') {
        $id      = (int)($_POST['id'] ?? 0);
        $classId = (int)($_POST['class_id'] ?? 0);
        requireOrgOwnership('sch_students', $id, $orgId);
        $pdo->prepare("UPDATE sch_students SET status='active', class_id=? WHERE id=? AND org_id=?")
            ->execute([$classId,$id,$orgId]);
        logActivity('update','school',"Alumni re-enrolled: student #$id into class #$classId");
        setFlash('success','Student re-enrolled as active.');
        redirect('alumni.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

// Filters
$fSearch     = sanitize($_GET['q'] ?? '');
$fStatus     = in_array($_GET['status'] ?? '', ['graduated','transferred']) ? $_GET['status'] : '';
$fYear       = sanitize($_GET['grad_year'] ?? '');
$fCurriculum = sanitize($_GET['curriculum'] ?? '');

$classes = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Exception $e){}

// Build query
$sql = "SELECT s.*,c.name AS class_name,
    COALESCE(s.graduation_year, YEAR(s.updated_at)) AS grad_year
    FROM sch_students s
    LEFT JOIN sch_classes c ON s.class_id=c.id
    WHERE s.org_id=? AND s.status IN('graduated','transferred')";
$params = [$orgId];
if ($fStatus) { $sql .= " AND s.status=?"; $params[] = $fStatus; }
if ($fSearch) { $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no LIKE ?)"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; $params[] = "%$fSearch%"; }
if ($fYear)   { $sql .= " AND (s.graduation_year=? OR YEAR(s.updated_at)=?)"; $params[] = $fYear; $params[] = $fYear; }
if ($fCurriculum) { $sql .= " AND s.curriculum=?"; $params[] = $fCurriculum; }
$sql .= " ORDER BY s.updated_at DESC";

$alumni = [];
try { $s=$pdo->prepare($sql); $s->execute($params); $alumni=$s->fetchAll(); } catch(Exception $e){}

// KPIs
$totalGraduated = countRows('sch_students',"org_id=? AND status='graduated'",[$orgId]);
$totalTransferred = countRows('sch_students',"org_id=? AND status='transferred'",[$orgId]);

$statusColors = ['graduated'=>'primary','transferred'=>'secondary'];
$statusIcons  = ['graduated'=>'fa-graduation-cap','transferred'=>'fa-exchange-alt'];
$curricula = ['IB','IGCSE','Cambridge','CBC','AP','Other'];
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-graduate me-2" style="color:<?= $moduleColor ?>"></i>Alumni & Graduate Records</h4>
    <p class="text-muted mb-0">Track graduated and transferred students, update contact info, and manage re-enrolments</p>
  </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-users"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalGraduated + $totalTransferred ?></div><div class="stat-label">Total Alumni</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon" style="background:rgba(11,45,78,.12);color:#0B2D4E"><i class="fas fa-graduation-cap"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalGraduated ?></div><div class="stat-label">Graduated</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-exchange-alt"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalTransferred ?></div><div class="stat-label">Transferred</div></div></div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-sm-3">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name or adm no…" value="<?= e($fSearch) ?>">
      </div>
      <div class="col-sm-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Status</option>
          <option value="graduated" <?= $fStatus==='graduated'?'selected':'' ?>>Graduated</option>
          <option value="transferred" <?= $fStatus==='transferred'?'selected':'' ?>>Transferred</option>
        </select>
      </div>
      <div class="col-sm-2">
        <input type="number" name="grad_year" class="form-control form-control-sm" placeholder="Grad Year" min="2000" max="2099" value="<?= e($fYear) ?>">
      </div>
      <div class="col-sm-2">
        <select name="curriculum" class="form-select form-select-sm">
          <option value="">All Curricula</option>
          <?php foreach ($curricula as $cur): ?>
          <option value="<?= $cur ?>" <?= $fCurriculum===$cur?'selected':'' ?>><?= $cur ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if ($fSearch||$fStatus||$fYear||$fCurriculum): ?>
      <div class="col-auto"><a href="alumni.php" class="btn btn-sm btn-link text-muted">Clear</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Alumni Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-list me-2 text-muted"></i><?= count($alumni) ?> record(s) found</h6>
    <small class="text-muted">Click <i class="fas fa-edit"></i> to update contact info</small>
  </div>
  <div class="card-body p-0">
    <?php if (empty($alumni)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-user-graduate fa-3x mb-3 d-block opacity-25"></i>
      <h6>No alumni records found</h6>
      <p class="small mb-0">Graduated and transferred students appear here after promotion.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Student</th>
            <th>Adm No</th>
            <th>Last Class</th>
            <th>Curriculum</th>
            <th class="text-center">Status</th>
            <th>Phone</th>
            <th>Email</th>
            <th class="text-muted">Year</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($alumni as $al): ?>
        <tr>
          <td class="ps-3">
            <div class="d-flex align-items-center gap-2">
              <?php if (!empty($al['photo'])): ?>
              <img src="/uploads/students/<?= e($al['photo']) ?>" class="rounded-circle" width="32" height="32" style="object-fit:cover">
              <?php else: ?>
              <div class="rounded-circle bg-light d-flex align-items-center justify-content-center border" style="width:32px;height:32px">
                <i class="fas fa-user-graduate text-muted small"></i>
              </div>
              <?php endif; ?>
              <div>
                <div class="fw-semibold"><?= e($al['first_name'].' '.$al['last_name']) ?></div>
                <?php if ($al['nationality']): ?>
                <small class="text-muted"><?= e($al['nationality']) ?></small>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td class="small text-muted"><?= e($al['admission_no'] ?? '—') ?></td>
          <td><?= e($al['class_name'] ?? '—') ?></td>
          <td><span class="badge bg-light text-dark border small"><?= e($al['curriculum'] ?: 'General') ?></span></td>
          <td class="text-center">
            <span class="badge bg-<?= $statusColors[$al['status']] ?? 'secondary' ?>">
              <i class="fas <?= $statusIcons[$al['status']] ?? 'fa-circle' ?> me-1"></i>
              <?= ucfirst($al['status']) ?>
            </span>
          </td>
          <td class="small"><?= $al['phone'] ? '<a href="tel:'.e($al['phone']).'">'.e($al['phone']).'</a>' : '<span class="text-muted">—</span>' ?></td>
          <td class="small"><?= $al['email'] ? '<a href="mailto:'.e($al['email']).'">'.e($al['email']).'</a>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-muted small"><?= $al['grad_year'] ?? '—' ?></td>
          <td class="text-center">
            <div class="btn-group btn-group-sm">
              <button class="btn btn-outline-primary" title="Update Contact"
                      onclick="editAlumni(<?= htmlspecialchars(json_encode([
                          'id'=>$al['id'],'name'=>$al['first_name'].' '.$al['last_name'],
                          'phone'=>$al['phone']??'','email'=>$al['email']??'',
                          'current_city'=>$al['current_city']??'','current_employer'=>$al['current_employer']??'',
                          'alumni_notes'=>$al['alumni_notes']??''
                      ]), ENT_QUOTES) ?>)">
                <i class="fas fa-edit"></i>
              </button>
              <?php if ($al['status'] === 'graduated' || $al['status'] === 'transferred'): ?>
              <button class="btn btn-outline-success" title="Re-enrol"
                      onclick="reEnrol(<?= $al['id'] ?>, '<?= e($al['first_name'].' '.$al['last_name']) ?>')">
                <i class="fas fa-undo"></i>
              </button>
              <?php endif; ?>
              <a href="students.php?highlight=<?= $al['id'] ?>" class="btn btn-outline-secondary" title="View Full Record">
                <i class="fas fa-eye"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Edit Contact Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-address-card me-2"></i>Update Alumni Contact</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_contact">
        <input type="hidden" name="id" id="editId">
        <div class="modal-body">
          <div class="alert alert-light border mb-3 py-2">
            <strong id="editName"></strong>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Phone Number</label>
              <input type="tel" name="phone" id="editPhone" class="form-control" placeholder="+254…">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Email Address</label>
              <input type="email" name="email" id="editEmail" class="form-control" placeholder="alumni@example.com">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Current City / Location</label>
              <input type="text" name="current_city" id="editCity" class="form-control" placeholder="e.g. Nairobi">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Current Employer / University</label>
              <input type="text" name="current_employer" id="editEmployer" class="form-control" placeholder="e.g. University of Nairobi">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="alumni_notes" id="editNotes" class="form-control" rows="2" placeholder="Any additional notes about this alumnus…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-save me-1"></i>Save Changes
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Re-enrol Modal -->
<div class="modal fade" id="reEnrolModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-undo me-2"></i>Re-enrol Student</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="re_enroll">
        <input type="hidden" name="id" id="reEnrolId">
        <div class="modal-body">
          <p>Re-enrol <strong id="reEnrolName"></strong> as an active student:</p>
          <div class="mb-3">
            <label class="form-label fw-semibold">Assign to Class <span class="text-danger">*</span></label>
            <select name="class_id" class="form-select" required>
              <option value="">— select class —</option>
              <?php foreach ($classes as $c): ?>
              <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="alert alert-warning small py-2">
            <i class="fas fa-exclamation-triangle me-1"></i>
            The student's status will be changed to <strong>Active</strong> and they will appear in all student lists.
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-undo me-1"></i>Re-enrol</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function editAlumni(data) {
    document.getElementById('editId').value       = data.id;
    document.getElementById('editName').textContent = data.name;
    document.getElementById('editPhone').value    = data.phone || '';
    document.getElementById('editEmail').value    = data.email || '';
    document.getElementById('editCity').value     = data.current_city || '';
    document.getElementById('editEmployer').value = data.current_employer || '';
    document.getElementById('editNotes').value    = data.alumni_notes || '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
function reEnrol(id, name) {
    document.getElementById('reEnrolId').value       = id;
    document.getElementById('reEnrolName').textContent = name;
    new bootstrap.Modal(document.getElementById('reEnrolModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
