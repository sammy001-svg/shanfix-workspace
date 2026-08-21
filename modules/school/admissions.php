<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Stage definitions ──────────────────────────────────────────────────
$stages = [
    'inquiry'   => ['label' => 'Inquiry',   'color' => '#6c757d', 'icon' => 'fas fa-question-circle'],
    'applied'   => ['label' => 'Applied',   'color' => '#0d6efd', 'icon' => 'fas fa-file-alt'],
    'review'    => ['label' => 'Review',    'color' => '#fd7e14', 'icon' => 'fas fa-search'],
    'interview' => ['label' => 'Interview', 'color' => '#6f42c1', 'icon' => 'fas fa-comments'],
    'accepted'  => ['label' => 'Accepted',  'color' => '#1A8A4E', 'icon' => 'fas fa-check-circle'],
    'enrolled'  => ['label' => 'Enrolled',  'color' => '#0B2D4E', 'icon' => 'fas fa-user-graduate'],
    'rejected'  => ['label' => 'Rejected',  'color' => '#dc3545', 'icon' => 'fas fa-times-circle'],
    'withdrawn' => ['label' => 'Withdrawn', 'color' => '#adb5bd', 'icon' => 'fas fa-minus-circle'],
];

// ── Next stage map ─────────────────────────────────────────────────────
$nextStage = [
    'inquiry'   => 'applied',
    'applied'   => 'review',
    'review'    => 'interview',
    'interview' => 'accepted',
    'accepted'  => 'enrolled',
];

// ── Generate app number ────────────────────────────────────────────────
function generateAppNo(PDO $pdo, int $orgId): string {
    $yr  = date('Y');
    $pre = 'APP-' . $yr . '-';
    $stmt = $pdo->prepare(
        "SELECT app_no FROM sch_admissions WHERE org_id=? AND app_no LIKE ? ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$orgId, $pre . '%']);
    $last = $stmt->fetchColumn();
    $seq  = $last ? ((int)substr($last, -4) + 1) : 1;
    return $pre . str_pad($seq, 4, '0', STR_PAD_LEFT);
}

// ── POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $firstName      = sanitize($_POST['first_name']  ?? '');
        $lastName       = sanitize($_POST['last_name']   ?? '');
        $dob            = $_POST['dob'] ?: null;
        $gender         = in_array($_POST['gender']??'',['male','female','other']) ? $_POST['gender'] : 'male';
        $nationality    = sanitize($_POST['nationality'] ?? '');
        $classApplying  = sanitize($_POST['class_applying'] ?? '');
        $curriculum     = in_array($_POST['curriculum']??'',['IB','IGCSE','Cambridge','CBC','AP','Other']) ? $_POST['curriculum'] : 'IB';
        $prevSchool     = sanitize($_POST['previous_school'] ?? '');
        $parentName     = sanitize($_POST['parent_name']  ?? '');
        $parentPhone    = sanitize($_POST['parent_phone'] ?? '');
        $parentEmail    = sanitize($_POST['parent_email'] ?? '');
        $address        = sanitize($_POST['address']      ?? '');
        $stage          = array_key_exists($_POST['stage'] ?? '', $stages) ? $_POST['stage'] : 'applied';
        $appliedDate    = $_POST['applied_date'] ?: date('Y-m-d');
        $interviewDate  = $_POST['interview_date'] ?: null;
        $interviewNotes = sanitize($_POST['interview_notes'] ?? '');
        $notes          = sanitize($_POST['notes'] ?? '');

        if (!$firstName || !$lastName) {
            setFlash('danger', 'Applicant first and last name are required.');
            redirect('admissions.php');
        }

        try {
            if ($id > 0) {
                requireOrgOwnership('sch_admissions', $id, $orgId);
                $pdo->prepare(
                    "UPDATE sch_admissions SET
                        first_name=?, last_name=?, dob=?, gender=?, nationality=?,
                        class_applying=?, curriculum=?, previous_school=?,
                        parent_name=?, parent_phone=?, parent_email=?, address=?,
                        stage=?, applied_date=?, interview_date=?, interview_notes=?, notes=?,
                        reviewed_by=?
                     WHERE id=? AND org_id=?"
                )->execute([
                    $firstName, $lastName, $dob, $gender, $nationality,
                    $classApplying, $curriculum, $prevSchool,
                    $parentName, $parentPhone, $parentEmail, $address,
                    $stage, $appliedDate, $interviewDate, $interviewNotes, $notes,
                    $user['id'],
                    $id, $orgId
                ]);
                setFlash('success', 'Application updated.');
            } else {
                $appNo = generateAppNo($pdo, $orgId);
                $pdo->prepare(
                    "INSERT INTO sch_admissions
                        (org_id, app_no, first_name, last_name, dob, gender, nationality,
                         class_applying, curriculum, previous_school,
                         parent_name, parent_phone, parent_email, address,
                         stage, applied_date, interview_date, interview_notes, notes, reviewed_by)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
                )->execute([
                    $orgId, $appNo, $firstName, $lastName, $dob, $gender, $nationality,
                    $classApplying, $curriculum, $prevSchool,
                    $parentName, $parentPhone, $parentEmail, $address,
                    $stage, $appliedDate, $interviewDate, $interviewNotes, $notes, $user['id']
                ]);
                setFlash('success', "Application $appNo submitted.");
            }
            logActivity($id > 0 ? 'update' : 'create', 'school', "Admission: $firstName $lastName → $stage");
        } catch (Throwable $e) {
            error_log('[school/admissions save] ' . $e->getMessage());
            setFlash('danger', 'Could not save. Run database/school_medical_admissions_migration.sql first.');
        }
        redirect('admissions.php');
    }

    if ($action === 'advance') {
        $id       = (int)($_POST['id'] ?? 0);
        $curStage = sanitize($_POST['current_stage'] ?? '');
        $next     = $nextStage[$curStage] ?? null;
        if ($id && $next) {
            requireOrgOwnership('sch_admissions', $id, $orgId);
            $pdo->prepare("UPDATE sch_admissions SET stage=?, reviewed_by=? WHERE id=? AND org_id=?")
                ->execute([$next, $user['id'], $id, $orgId]);
            if ($next === 'accepted') {
                $pdo->prepare("UPDATE sch_admissions SET offer_sent=0 WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            }
            setFlash('success', 'Application advanced to ' . ucfirst($next) . '.');
        }
        redirect('admissions.php');
    }

    if ($action === 'reject') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_admissions', $id, $orgId);
        $pdo->prepare("UPDATE sch_admissions SET stage='rejected', reviewed_by=? WHERE id=? AND org_id=?")
            ->execute([$user['id'], $id, $orgId]);
        setFlash('info', 'Application marked as Rejected.');
        redirect('admissions.php');
    }

    if ($action === 'mark_offer') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_admissions', $id, $orgId);
        $pdo->prepare("UPDATE sch_admissions SET offer_sent=1 WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Offer letter marked as sent.');
        redirect('admissions.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_admissions', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_admissions WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Application deleted.');
        redirect('admissions.php');
    }
}

// ── AJAX fetch ─────────────────────────────────────────────────────────
if (isset($_GET['fetch'])) {
    $r = $pdo->prepare("SELECT * FROM sch_admissions WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch'], $orgId]);
    header('Content-Type: application/json');
    echo json_encode($r->fetch() ?: []);
    exit;
}

// ── Stage counts ───────────────────────────────────────────────────────
$stageCounts = array_fill_keys(array_keys($stages), 0);
try {
    $s = $pdo->prepare("SELECT stage, COUNT(*) AS cnt FROM sch_admissions WHERE org_id=? GROUP BY stage");
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $row) {
        $stageCounts[$row['stage']] = (int)$row['cnt'];
    }
} catch (Throwable $e) {}

// ── Filter & load applications ─────────────────────────────────────────
$fStage  = sanitize($_GET['stage'] ?? '');
$fSearch = sanitize($_GET['q'] ?? '');

$where  = 'org_id = ?';
$params = [$orgId];
if ($fStage)  { $where .= ' AND stage = ?';                                             $params[] = $fStage; }
if ($fSearch) {
    $where .= ' AND (first_name LIKE ? OR last_name LIKE ? OR app_no LIKE ? OR parent_name LIKE ? OR parent_phone LIKE ?)';
    $q = "%$fSearch%";
    array_push($params, $q, $q, $q, $q, $q);
}

$applications = [];
try {
    $stmt = $pdo->prepare(
        "SELECT * FROM sch_admissions WHERE $where ORDER BY applied_date DESC, id DESC"
    );
    $stmt->execute($params);
    $applications = $stmt->fetchAll();
} catch (Throwable $e) { error_log('[school/admissions load] ' . $e->getMessage()); }

// ── Classes list ───────────────────────────────────────────────────────
$classesList = [];
try {
    $s = $pdo->prepare("SELECT id, name FROM sch_classes WHERE org_id=? ORDER BY name ASC");
    $s->execute([$orgId]);
    $classesList = $s->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-clipboard-list me-2" style="color:<?= $moduleColor ?>"></i>Admissions Pipeline</h4>
    <p class="text-muted mb-0">Track applicants from inquiry through enrollment with a structured review pipeline</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#admModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Application
  </button>
</div>

<!-- Pipeline Summary -->
<div class="row g-2 mb-4">
  <?php foreach ($stages as $slug => $stg): ?>
  <div class="col-6 col-sm-4 col-lg">
    <a href="admissions.php?stage=<?= $slug ?><?= $fSearch ? '&q='.urlencode($fSearch) : '' ?>"
       class="card border-0 shadow-sm text-decoration-none h-100 <?= $fStage === $slug ? 'border-2' : '' ?>"
       style="<?= $fStage === $slug ? "border-color:{$stg['color']}!important;border-width:2px!important;border-style:solid!important;" : '' ?>">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="<?= $stg['icon'] ?> small" style="color:<?= $stg['color'] ?>"></i>
          <span class="small fw-semibold" style="color:<?= $stg['color'] ?>"><?= $stg['label'] ?></span>
        </div>
        <div class="fs-4 fw-bold text-dark"><?= $stageCounts[$slug] ?></div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
  <div class="col-6 col-sm-4 col-lg">
    <a href="admissions.php" class="card border-0 shadow-sm text-decoration-none h-100 <?= !$fStage ? 'border-2' : '' ?>"
       style="<?= !$fStage ? 'border-color:#1A8A4E!important;border-width:2px!important;border-style:solid!important;' : '' ?>">
      <div class="card-body p-3">
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="fas fa-list small text-secondary"></i>
          <span class="small fw-semibold text-secondary">All</span>
        </div>
        <div class="fs-4 fw-bold text-dark"><?= array_sum($stageCounts) ?></div>
      </div>
    </a>
  </div>
</div>

<!-- Search filter -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <?php if ($fStage): ?><input type="hidden" name="stage" value="<?= e($fStage) ?>"><?php endif; ?>
      <div class="col-sm-5">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name, app no, parent, phone..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-search me-1"></i>Search</button>
        <a href="admissions.php<?= $fStage ? '?stage='.$fStage : '' ?>" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
      <?php if ($fStage): ?>
      <div class="col-auto ms-auto">
        <span class="badge fs-6" style="background:<?= $stages[$fStage]['color'] ?>">
          <i class="<?= $stages[$fStage]['icon'] ?> me-1"></i><?= $stages[$fStage]['label'] ?>
        </span>
      </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Applications Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold text-dark"><i class="fas fa-clipboard-list me-2 text-success"></i>Applications</h6>
    <span class="badge bg-secondary"><?= count($applications) ?> application<?= count($applications) != 1 ? 's' : '' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>App No.</th>
            <th>Applicant</th>
            <th>Applying For</th>
            <th>Curriculum</th>
            <th>Parent / Guardian</th>
            <th>Applied Date</th>
            <th>Stage</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($applications)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-clipboard-list fa-2x mb-2 d-block"></i>No applications found<?= $fStage ? ' in this stage' : '' ?>.
          </td></tr>
          <?php else: foreach ($applications as $app):
            $stgInfo = $stages[$app['stage']] ?? ['label' => ucfirst($app['stage']), 'color' => '#6c757d', 'icon' => 'fas fa-circle'];
            $canAdvance  = isset($nextStage[$app['stage']]);
            $isAccepted  = $app['stage'] === 'accepted';
            $isTerminal  = in_array($app['stage'], ['enrolled','rejected','withdrawn']);
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($app['app_no']) ?></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($app['first_name'] . ' ' . $app['last_name']) ?></div>
              <small class="text-muted"><?= e(ucfirst($app['gender'])) ?><?= $app['dob'] ? ' · '.date('Y', strtotime($app['dob'])).' born' : '' ?><?= $app['nationality'] ? ' · '.e($app['nationality']) : '' ?></small>
            </td>
            <td><?= e($app['class_applying'] ?: '—') ?></td>
            <td><span class="badge bg-primary"><?= e($app['curriculum']) ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($app['parent_name'] ?: '—') ?></div>
              <small class="text-muted"><?= e($app['parent_phone'] ?: '') ?></small>
            </td>
            <td><?= formatDate($app['applied_date']) ?></td>
            <td>
              <span class="badge" style="background:<?= $stgInfo['color'] ?>">
                <i class="<?= $stgInfo['icon'] ?> me-1"></i><?= $stgInfo['label'] ?>
              </span>
              <?php if ($isAccepted && !$app['offer_sent']): ?>
              <br><small class="text-warning fw-semibold"><i class="fas fa-envelope me-1"></i>Offer not sent</small>
              <?php elseif ($isAccepted && $app['offer_sent']): ?>
              <br><small class="text-success"><i class="fas fa-check me-1"></i>Offer sent</small>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $app['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <?php if ($canAdvance): ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="advance">
                  <input type="hidden" name="id" value="<?= $app['id'] ?>">
                  <input type="hidden" name="current_stage" value="<?= $app['stage'] ?>">
                  <button type="submit" class="btn btn-outline-success" title="Advance to <?= ucfirst($nextStage[$app['stage']]) ?>">
                    <i class="fas fa-arrow-right"></i>
                  </button>
                </form>
                <?php endif; ?>
                <?php if ($isAccepted): ?>
                <a href="admissions-offer-pdf.php?id=<?= $app['id'] ?>" target="_blank" class="btn btn-outline-dark" title="Print Offer Letter"><i class="fas fa-file-alt"></i></a>
                <?php if (!$app['offer_sent']): ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="mark_offer">
                  <input type="hidden" name="id" value="<?= $app['id'] ?>">
                  <button type="submit" class="btn btn-outline-warning" title="Mark offer as sent"><i class="fas fa-envelope-open"></i></button>
                </form>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!$isTerminal): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Reject this application?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reject">
                  <input type="hidden" name="id" value="<?= $app['id'] ?>">
                  <button type="submit" class="btn btn-outline-danger" title="Reject"><i class="fas fa-times"></i></button>
                </form>
                <?php endif; ?>
                <button class="btn btn-outline-secondary" onclick="delApp(<?= $app['id'] ?>, '<?= e($app['first_name'].' '.$app['last_name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
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
<div class="modal fade" id="admModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="admId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="admTitle"><i class="fas fa-clipboard-list me-2"></i>New Application</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" id="admTabs">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#adm-tab-applicant" type="button">Applicant</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#adm-tab-guardian"  type="button">Parent / Guardian</button></li>
          <li class="nav-item"><button class="nav-link"        data-bs-toggle="tab" data-bs-target="#adm-tab-process"   type="button">Pipeline</button></li>
        </ul>
        <div class="tab-content">

          <!-- Applicant -->
          <div class="tab-pane fade show active" id="adm-tab-applicant">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-semibold">First Name <span class="text-danger">*</span></label>
                <input type="text" name="first_name" id="admFirst" class="form-control" required>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
                <input type="text" name="last_name" id="admLast" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Date of Birth</label>
                <input type="date" name="dob" id="admDob" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Gender</label>
                <select name="gender" id="admGender" class="form-select">
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Nationality</label>
                <input type="text" name="nationality" id="admNat" class="form-control" placeholder="e.g. Kenyan">
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Applying for Class</label>
                <select name="class_applying" id="admClass" class="form-select">
                  <option value="">-- Not specified --</option>
                  <?php foreach ($classesList as $cl): ?>
                  <option value="<?= e($cl['name']) ?>"><?= e($cl['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-6">
                <label class="form-label fw-semibold">Curriculum</label>
                <select name="curriculum" id="admCurr" class="form-select">
                  <?php foreach (['IB','IGCSE','Cambridge','CBC','AP','Other'] as $c): ?>
                  <option value="<?= $c ?>"><?= $c ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Previous School</label>
                <input type="text" name="previous_school" id="admPrevSchool" class="form-control" placeholder="e.g. Nairobi Academy">
              </div>
            </div>
          </div>

          <!-- Parent / Guardian -->
          <div class="tab-pane fade" id="adm-tab-guardian">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Parent / Guardian Name</label>
                <input type="text" name="parent_name" id="admParentName" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Phone</label>
                <input type="tel" name="parent_phone" id="admParentPhone" class="form-control">
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Email</label>
                <input type="email" name="parent_email" id="admParentEmail" class="form-control">
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Home Address</label>
                <textarea name="address" id="admAddress" class="form-control" rows="3"></textarea>
              </div>
            </div>
          </div>

          <!-- Pipeline -->
          <div class="tab-pane fade" id="adm-tab-process">
            <div class="row g-3">
              <div class="col-md-4">
                <label class="form-label fw-semibold">Application Date <span class="text-danger">*</span></label>
                <input type="date" name="applied_date" id="admAppliedDate" class="form-control" required>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Current Stage</label>
                <select name="stage" id="admStage" class="form-select">
                  <?php foreach ($stages as $slug => $stg): ?>
                  <option value="<?= $slug ?>"><?= $stg['label'] ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-4">
                <label class="form-label fw-semibold">Interview Date</label>
                <input type="date" name="interview_date" id="admInterviewDate" class="form-control">
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">Interview Notes</label>
                <textarea name="interview_notes" id="admInterviewNotes" class="form-control" rows="2" placeholder="Observations, scores, committee feedback..."></textarea>
              </div>
              <div class="col-12">
                <label class="form-label fw-semibold">General Notes</label>
                <textarea name="notes" id="admNotes" class="form-control" rows="2" placeholder="Any additional remarks, conditions, scholarships, etc."></textarea>
              </div>
            </div>
          </div>

        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Application</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete form -->
<form method="POST" id="delAdmForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delAdmId">
</form>

<?php ob_start(); ?>
<script>
function openAdd() {
  document.getElementById('admTitle').innerHTML = '<i class="fas fa-clipboard-list me-2"></i>New Application';
  document.getElementById('admId').value = '0';
  document.getElementById('admFirst').value = '';
  document.getElementById('admLast').value = '';
  document.getElementById('admDob').value = '';
  document.getElementById('admGender').value = 'male';
  document.getElementById('admNat').value = '';
  document.getElementById('admClass').value = '';
  document.getElementById('admCurr').value = 'IB';
  document.getElementById('admPrevSchool').value = '';
  document.getElementById('admParentName').value = '';
  document.getElementById('admParentPhone').value = '';
  document.getElementById('admParentEmail').value = '';
  document.getElementById('admAddress').value = '';
  document.getElementById('admAppliedDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('admStage').value = 'applied';
  document.getElementById('admInterviewDate').value = '';
  document.getElementById('admInterviewNotes').value = '';
  document.getElementById('admNotes').value = '';
  bootstrap.Tab.getOrCreateInstance(document.querySelector('#admTabs button[data-bs-target="#adm-tab-applicant"]')).show();
}

function openEdit(id) {
  fetch('admissions.php?fetch=' + id)
    .then(r => r.json())
    .then(d => {
      if (!d || !d.id) return;
      document.getElementById('admTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Application';
      document.getElementById('admId').value              = d.id;
      document.getElementById('admFirst').value           = d.first_name || '';
      document.getElementById('admLast').value            = d.last_name  || '';
      document.getElementById('admDob').value             = d.dob || '';
      document.getElementById('admGender').value          = d.gender || 'male';
      document.getElementById('admNat').value             = d.nationality || '';
      document.getElementById('admClass').value           = d.class_applying || '';
      document.getElementById('admCurr').value            = d.curriculum || 'IB';
      document.getElementById('admPrevSchool').value      = d.previous_school || '';
      document.getElementById('admParentName').value      = d.parent_name  || '';
      document.getElementById('admParentPhone').value     = d.parent_phone || '';
      document.getElementById('admParentEmail').value     = d.parent_email || '';
      document.getElementById('admAddress').value         = d.address || '';
      document.getElementById('admAppliedDate').value     = d.applied_date || '';
      document.getElementById('admStage').value           = d.stage || 'applied';
      document.getElementById('admInterviewDate').value   = d.interview_date || '';
      document.getElementById('admInterviewNotes').value  = d.interview_notes || '';
      document.getElementById('admNotes').value           = d.notes || '';
      bootstrap.Tab.getOrCreateInstance(document.querySelector('#admTabs button[data-bs-target="#adm-tab-applicant"]')).show();
      new bootstrap.Modal(document.getElementById('admModal')).show();
    });
}

function delApp(id, name) {
  if (confirm('Permanently delete application for "' + name + '"?')) {
    document.getElementById('delAdmId').value = id;
    document.getElementById('delAdmForm').submit();
  }
}
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
