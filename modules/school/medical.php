<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $studentId   = (int)($_POST['student_id'] ?? 0);
        $visitDate   = $_POST['visit_date']   ?? date('Y-m-d');
        $complaint   = sanitize($_POST['complaint']   ?? '');
        $diagnosis   = sanitize($_POST['diagnosis']   ?? '');
        $treatment   = sanitize($_POST['treatment']   ?? '');
        $prescBy     = sanitize($_POST['prescribed_by'] ?? '');
        $temp        = $_POST['temperature']    !== '' ? (float)$_POST['temperature']  : null;
        $bp          = sanitize($_POST['blood_pressure'] ?? '');
        $weight      = $_POST['weight_kg']      !== '' ? (float)$_POST['weight_kg']    : null;
        $height      = $_POST['height_cm']      !== '' ? (float)$_POST['height_cm']    : null;
        $followDate  = $_POST['follow_up_date'] ?: null;
        $followNotes = sanitize($_POST['follow_up_notes'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['open','resolved','referred']) ? $_POST['status'] : 'open';

        if (!$studentId || !$visitDate) {
            setFlash('danger', 'Student and visit date are required.');
            redirect('medical.php');
        }
        assertOrgOwnership('sch_students', $studentId, $orgId);

        try {
            if ($id > 0) {
                requireOrgOwnership('sch_medical_records', $id, $orgId);
                $pdo->prepare(
                    "UPDATE sch_medical_records SET
                        student_id=?, visit_date=?, complaint=?, diagnosis=?, treatment=?,
                        prescribed_by=?, temperature=?, blood_pressure=?, weight_kg=?, height_cm=?,
                        follow_up_date=?, follow_up_notes=?, status=?
                     WHERE id=? AND org_id=?"
                )->execute([
                    $studentId, $visitDate, $complaint, $diagnosis, $treatment,
                    $prescBy, $temp, $bp ?: null, $weight, $height,
                    $followDate, $followNotes, $status,
                    $id, $orgId
                ]);
                setFlash('success', 'Clinic record updated.');
            } else {
                $pdo->prepare(
                    "INSERT INTO sch_medical_records
                        (org_id, student_id, visit_date, complaint, diagnosis, treatment,
                         prescribed_by, temperature, blood_pressure, weight_kg, height_cm,
                         follow_up_date, follow_up_notes, status, created_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $orgId, $studentId, $visitDate, $complaint, $diagnosis, $treatment,
                    $prescBy, $temp, $bp ?: null, $weight, $height,
                    $followDate, $followNotes, $status, $user['id']
                ]);
                setFlash('success', 'Clinic visit recorded.');
            }
            logActivity($id > 0 ? 'update' : 'create', 'school', "Medical record for student #$studentId");
        } catch (Throwable $e) {
            error_log('[school/medical save] ' . $e->getMessage());
            setFlash('danger', 'Could not save. Run database/school_medical_admissions_migration.sql first.');
        }
        redirect('medical.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_medical_records', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_medical_records WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Record deleted.');
        redirect('medical.php');
    }
}

// ── AJAX fetch for edit modal ──────────────────────────────────────────
if (isset($_GET['fetch'])) {
    $r = $pdo->prepare("SELECT * FROM sch_medical_records WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch'], $orgId]);
    header('Content-Type: application/json');
    echo json_encode($r->fetch() ?: []);
    exit;
}

// ── Filters ────────────────────────────────────────────────────────────
$fStudent = (int)($_GET['student_id'] ?? 0);
$fStatus  = sanitize($_GET['status'] ?? '');
$fSearch  = sanitize($_GET['q'] ?? '');

$where  = 'm.org_id = ?';
$params = [$orgId];
if ($fStudent) { $where .= ' AND m.student_id = ?'; $params[] = $fStudent; }
if ($fStatus)  { $where .= ' AND m.status = ?';     $params[] = $fStatus; }
if ($fSearch) {
    $where .= ' AND (st.first_name LIKE ? OR st.last_name LIKE ? OR m.complaint LIKE ? OR m.diagnosis LIKE ?)';
    $q = "%$fSearch%";
    array_push($params, $q, $q, $q, $q);
}

$records = [];
try {
    $stmt = $pdo->prepare(
        "SELECT m.*, CONCAT(st.first_name,' ',st.last_name) AS student_name,
                st.admission_no, c.name AS class_name
         FROM sch_medical_records m
         JOIN sch_students st ON st.id = m.student_id
         LEFT JOIN sch_classes c ON c.id = st.class_id
         WHERE $where
         ORDER BY m.visit_date DESC, m.id DESC"
    );
    $stmt->execute($params);
    $records = $stmt->fetchAll();
} catch (Throwable $e) { error_log('[school/medical load] ' . $e->getMessage()); }

// ── Summary stats ──────────────────────────────────────────────────────
$statTotal = $statOpen = $statResolved = $statFollowUp = 0;
try {
    $s = $pdo->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(status='open') AS open_cases,
            SUM(status='resolved') AS resolved,
            SUM(follow_up_date IS NOT NULL AND follow_up_date >= CURDATE() AND status='open') AS follow_ups
         FROM sch_medical_records WHERE org_id=?"
    );
    $s->execute([$orgId]);
    $stats      = $s->fetch();
    $statTotal  = (int)($stats['total']    ?? 0);
    $statOpen   = (int)($stats['open_cases']?? 0);
    $statResolved=(int)($stats['resolved'] ?? 0);
    $statFollowUp=(int)($stats['follow_ups']?? 0);
} catch (Throwable $e) {}

// ── Student list for dropdown ──────────────────────────────────────────
$students = [];
try {
    $s = $pdo->prepare(
        "SELECT id, CONCAT(first_name,' ',last_name) AS name, admission_no
         FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name ASC"
    );
    $s->execute([$orgId]);
    $students = $s->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-notes-medical me-2" style="color:<?= $moduleColor ?>"></i>Student Medical Records</h4>
    <p class="text-muted mb-0">Track clinic visits, diagnoses, treatments, and follow-ups for all students</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#medModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Record Visit
  </button>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width:48px;height:48px;background:#0B2D4E">
          <i class="fas fa-heartbeat"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-dark"><?= number_format($statTotal) ?></div>
          <div class="text-muted small">Total Visits</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width:48px;height:48px;background:#dc3545">
          <i class="fas fa-exclamation-circle"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-dark"><?= number_format($statOpen) ?></div>
          <div class="text-muted small">Open Cases</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-check-circle"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-dark"><?= number_format($statResolved) ?></div>
          <div class="text-muted small">Resolved</div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0" style="width:48px;height:48px;background:#fd7e14">
          <i class="fas fa-calendar-check"></i>
        </div>
        <div>
          <div class="fs-4 fw-bold text-dark"><?= number_format($statFollowUp) ?></div>
          <div class="text-muted small">Follow-ups Due</div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-4">
        <label class="form-label small fw-semibold mb-1">Search</label>
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Student name, complaint, diagnosis..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Student</label>
        <select name="student_id" class="form-select form-select-sm">
          <option value="">All Students</option>
          <?php foreach ($students as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $fStudent == $st['id'] ? 'selected' : '' ?>>
            <?= e($st['name']) ?> (<?= e($st['admission_no']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All</option>
          <option value="open"     <?= $fStatus === 'open'     ? 'selected' : '' ?>>Open</option>
          <option value="resolved" <?= $fStatus === 'resolved' ? 'selected' : '' ?>>Resolved</option>
          <option value="referred" <?= $fStatus === 'referred' ? 'selected' : '' ?>>Referred</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="medical.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Records Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-notes-medical me-2 text-success"></i>Clinic Visit Log</h6>
    <span class="badge bg-secondary"><?= count($records) ?> record<?= count($records) != 1 ? 's' : '' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Student</th>
            <th>Class</th>
            <th>Visit Date</th>
            <th>Complaint</th>
            <th>Diagnosis</th>
            <th>Prescribed By</th>
            <th>Follow-up</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($records)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-notes-medical fa-2x mb-2 d-block"></i>No clinic records found.
          </td></tr>
          <?php else: foreach ($records as $rec):
            $statusColors = ['open' => 'danger', 'resolved' => 'success', 'referred' => 'warning'];
            $sc = $statusColors[$rec['status']] ?? 'secondary';
            $followDue = $rec['follow_up_date'] && $rec['status'] === 'open' && $rec['follow_up_date'] <= date('Y-m-d');
          ?>
          <tr <?= $followDue ? 'class="table-warning"' : '' ?>>
            <td>
              <div class="fw-semibold text-dark"><?= e($rec['student_name']) ?></div>
              <small class="text-muted"><?= e($rec['admission_no']) ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($rec['class_name'] ?: '—') ?></span></td>
            <td><?= formatDate($rec['visit_date']) ?></td>
            <td>
              <span title="<?= e($rec['complaint']) ?>" data-bs-toggle="tooltip">
                <?= e(mb_strimwidth($rec['complaint'] ?? '—', 0, 40, '...')) ?>
              </span>
            </td>
            <td>
              <span title="<?= e($rec['diagnosis']) ?>" data-bs-toggle="tooltip">
                <?= e(mb_strimwidth($rec['diagnosis'] ?? '—', 0, 40, '...')) ?>
              </span>
            </td>
            <td><?= e($rec['prescribed_by'] ?: '—') ?></td>
            <td>
              <?php if ($rec['follow_up_date']): ?>
              <span class="<?= $followDue ? 'text-danger fw-semibold' : 'text-dark' ?>">
                <?= formatDate($rec['follow_up_date']) ?>
                <?= $followDue ? '<i class="fas fa-bell ms-1 text-danger"></i>' : '' ?>
              </span>
              <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($rec['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $rec['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger"  onclick="delRec(<?= $rec['id'] ?>, '<?= e($rec['student_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal fade" id="medModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="medId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="medTitle"><i class="fas fa-notes-medical me-2"></i>Record Clinic Visit</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <!-- Tabs -->
        <ul class="nav nav-tabs mb-3" id="medTabs">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#med-tab-visit"   type="button">Visit Details</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#med-tab-vitals"  type="button">Vitals</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#med-tab-followup" type="button">Follow-up</button></li>
        </ul>
        <div class="tab-content">

          <!-- Visit Details -->
          <div class="tab-pane fade show active" id="med-tab-visit">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
                <select name="student_id" id="medStudent" class="form-select" required>
                  <option value="">-- Select Student --</option>
                  <?php foreach ($students as $st): ?>
                  <option value="<?= $st['id'] ?>"><?= e($st['name']) ?> (<?= e($st['admission_no']) ?>)</option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Visit Date <span class="text-danger">*</span></label>
                <input type="date" name="visit_date" id="medDate" class="form-control" required>
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" id="medStatus" class="form-select">
                  <option value="open">Open</option>
                  <option value="resolved">Resolved</option>
                  <option value="referred">Referred</option>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Complaint / Presenting Symptoms</label>
                <textarea name="complaint" id="medComplaint" class="form-control" rows="2" placeholder="What did the student complain about?"></textarea>
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Diagnosis</label>
                <textarea name="diagnosis" id="medDiagnosis" class="form-control" rows="2" placeholder="Clinical diagnosis..."></textarea>
              </div>
              <div class="col-md-8">
                <label class="form-label fw-semibold">Treatment / Medication</label>
                <textarea name="treatment" id="medTreatment" class="form-control" rows="2" placeholder="Treatment given, medication prescribed..."></textarea>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Prescribed / Seen By</label>
                <input type="text" name="prescribed_by" id="medPrescBy" class="form-control" placeholder="Nurse / Doctor name">
              </div>
            </div>
          </div>

          <!-- Vitals -->
          <div class="tab-pane fade" id="med-tab-vitals">
            <div class="row g-3">
              <div class="col-md-3">
                <label class="form-label fw-semibold">Temperature (°C)</label>
                <input type="number" name="temperature" id="medTemp" class="form-control" step="0.1" min="30" max="45" placeholder="e.g. 37.5">
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Blood Pressure</label>
                <input type="text" name="blood_pressure" id="medBP" class="form-control" placeholder="e.g. 120/80">
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Weight (kg)</label>
                <input type="number" name="weight_kg" id="medWeight" class="form-control" step="0.1" min="0" placeholder="e.g. 45.5">
              </div>
              <div class="col-md-3">
                <label class="form-label fw-semibold">Height (cm)</label>
                <input type="number" name="height_cm" id="medHeight" class="form-control" step="0.1" min="0" placeholder="e.g. 152.0">
              </div>
              <div class="col-12">
                <div class="alert alert-light border mb-0 small">
                  <i class="fas fa-info-circle me-1 text-primary"></i>
                  Vitals are optional. Leave blank if not measured during this visit.
                </div>
              </div>
            </div>
          </div>

          <!-- Follow-up -->
          <div class="tab-pane fade" id="med-tab-followup">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Follow-up Date</label>
                <input type="date" name="follow_up_date" id="medFollowDate" class="form-control">
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Follow-up Notes</label>
                <textarea name="follow_up_notes" id="medFollowNotes" class="form-control" rows="4" placeholder="Instructions for follow-up visit, referral details, parent communication notes..."></textarea>
              </div>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Record</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete form -->
<form method="POST" id="delMedForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delMedId">
</form>

<?php ob_start(); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
});

function openAdd() {
  document.getElementById('medTitle').innerHTML = '<i class="fas fa-notes-medical me-2"></i>Record Clinic Visit';
  document.getElementById('medId').value = '0';
  document.getElementById('medStudent').value = '';
  document.getElementById('medDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('medStatus').value = 'open';
  document.getElementById('medComplaint').value = '';
  document.getElementById('medDiagnosis').value = '';
  document.getElementById('medTreatment').value = '';
  document.getElementById('medPrescBy').value = '';
  document.getElementById('medTemp').value = '';
  document.getElementById('medBP').value = '';
  document.getElementById('medWeight').value = '';
  document.getElementById('medHeight').value = '';
  document.getElementById('medFollowDate').value = '';
  document.getElementById('medFollowNotes').value = '';
  bootstrap.Tab.getOrCreateInstance(document.querySelector('#medTabs button[data-bs-target="#med-tab-visit"]')).show();
}

function openEdit(id) {
  fetch('medical.php?fetch=' + id)
    .then(r => r.json())
    .then(d => {
      if (!d || !d.id) return;
      document.getElementById('medTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Clinic Record';
      document.getElementById('medId').value          = d.id;
      document.getElementById('medStudent').value     = d.student_id || '';
      document.getElementById('medDate').value        = d.visit_date || '';
      document.getElementById('medStatus').value      = d.status || 'open';
      document.getElementById('medComplaint').value   = d.complaint || '';
      document.getElementById('medDiagnosis').value   = d.diagnosis || '';
      document.getElementById('medTreatment').value   = d.treatment || '';
      document.getElementById('medPrescBy').value     = d.prescribed_by || '';
      document.getElementById('medTemp').value        = d.temperature || '';
      document.getElementById('medBP').value          = d.blood_pressure || '';
      document.getElementById('medWeight').value      = d.weight_kg || '';
      document.getElementById('medHeight').value      = d.height_cm || '';
      document.getElementById('medFollowDate').value  = d.follow_up_date || '';
      document.getElementById('medFollowNotes').value = d.follow_up_notes || '';
      bootstrap.Tab.getOrCreateInstance(document.querySelector('#medTabs button[data-bs-target="#med-tab-visit"]')).show();
      new bootstrap.Modal(document.getElementById('medModal')).show();
    });
}

function delRec(id, name) {
  if (confirm('Delete clinic record for "' + name + '"? This cannot be undone.')) {
    document.getElementById('delMedId').value = id;
    document.getElementById('delMedForm').submit();
  }
}
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
