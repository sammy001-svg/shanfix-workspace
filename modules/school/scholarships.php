<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$view  = in_array($_GET['view'] ?? '', ['schemes','awards']) ? $_GET['view'] : 'awards';

// ── POST Handlers ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    // ── Schemes CRUD ───────────────────────────────────────────────
    if ($action === 'save_scheme') {
        $id        = (int)($_POST['id'] ?? 0);
        $name      = sanitize($_POST['name']      ?? '');
        $type      = in_array($_POST['type']??'',['scholarship','bursary','grant','discount']) ? $_POST['type'] : 'scholarship';
        $valType   = in_array($_POST['value_type']??'',['fixed','percentage']) ? $_POST['value_type'] : 'fixed';
        $value     = max(0, (float)($_POST['value'] ?? 0));
        $currency  = sanitize($_POST['currency'] ?? 'KES');
        $criteria  = sanitize($_POST['criteria'] ?? '');
        $renewable = (int)($_POST['renewable'] ?? 1);
        $status    = in_array($_POST['status']??'',['active','inactive']) ? $_POST['status'] : 'active';

        if (!$name) { setFlash('danger','Scheme name is required.'); redirect('scholarships.php?view=schemes'); }
        try {
            if ($id > 0) {
                requireOrgOwnership('sch_scholarship_schemes', $id, $orgId);
                $pdo->prepare("UPDATE sch_scholarship_schemes SET name=?,type=?,value_type=?,value=?,currency=?,criteria=?,renewable=?,status=? WHERE id=? AND org_id=?")
                    ->execute([$name,$type,$valType,$value,$currency,$criteria,$renewable,$status,$id,$orgId]);
                setFlash('success','Scheme updated.');
            } else {
                $pdo->prepare("INSERT INTO sch_scholarship_schemes (org_id,name,type,value_type,value,currency,criteria,renewable,status) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId,$name,$type,$valType,$value,$currency,$criteria,$renewable,$status]);
                setFlash('success',"Scheme '$name' created.");
            }
            logActivity($id>0?'update':'create','school',"Scholarship scheme: $name ($type)");
        } catch (Throwable $e) {
            error_log('[school/scholarships scheme] '.$e->getMessage());
            setFlash('danger','Could not save. Run database/school_scholarships_store_migration.sql first.');
        }
        redirect('scholarships.php?view=schemes');
    }

    if ($action === 'delete_scheme') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_scholarship_schemes', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_scholarship_schemes WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Scheme deleted.'); redirect('scholarships.php?view=schemes');
    }

    // ── Awards CRUD ────────────────────────────────────────────────
    if ($action === 'save_award') {
        $id           = (int)($_POST['id'] ?? 0);
        $schemeId     = (int)($_POST['scheme_id']    ?? 0);
        $studentId    = (int)($_POST['student_id']   ?? 0);
        $academicYear = sanitize($_POST['academic_year'] ?? '');
        $amount       = max(0,(float)($_POST['amount_awarded'] ?? 0));
        $disbursed    = max(0,(float)($_POST['disbursed']      ?? 0));
        $status       = in_array($_POST['status']??'',['pending','active','disbursed','cancelled','expired'])?$_POST['status']:'pending';
        $awardedDate  = $_POST['awarded_date'] ?: date('Y-m-d');
        $notes        = sanitize($_POST['notes'] ?? '');

        if (!$schemeId || !$studentId) { setFlash('danger','Scheme and student are required.'); redirect('scholarships.php'); }
        assertOrgOwnership('sch_students', $studentId, $orgId);
        assertOrgOwnership('sch_scholarship_schemes', $schemeId, $orgId);

        try {
            if ($id > 0) {
                requireOrgOwnership('sch_scholarship_awards', $id, $orgId);
                $pdo->prepare("UPDATE sch_scholarship_awards SET scheme_id=?,student_id=?,academic_year=?,amount_awarded=?,disbursed=?,status=?,awarded_date=?,notes=?,awarded_by=? WHERE id=? AND org_id=?")
                    ->execute([$schemeId,$studentId,$academicYear,$amount,$disbursed,$status,$awardedDate,$notes,$user['id'],$id,$orgId]);
                setFlash('success','Award updated.');
            } else {
                $pdo->prepare("INSERT INTO sch_scholarship_awards (org_id,scheme_id,student_id,academic_year,amount_awarded,disbursed,status,awarded_date,notes,awarded_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId,$schemeId,$studentId,$academicYear,$amount,$disbursed,$status,$awardedDate,$notes,$user['id']]);
                setFlash('success','Scholarship awarded successfully.');
            }
            logActivity($id>0?'update':'create','school',"Scholarship award: student #$studentId, scheme #$schemeId");
        } catch (Throwable $e) {
            error_log('[school/scholarships award] '.$e->getMessage());
            setFlash('danger','Could not save award.');
        }
        redirect('scholarships.php');
    }

    if ($action === 'delete_award') {
        $id = (int)($_POST['id'] ?? 0);
        requireOrgOwnership('sch_scholarship_awards', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_scholarship_awards WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Award removed.'); redirect('scholarships.php');
    }

    if ($action === 'disburse') {
        $id     = (int)($_POST['id']     ?? 0);
        $amount = max(0,(float)($_POST['disburse_amount'] ?? 0));
        requireOrgOwnership('sch_scholarship_awards', $id, $orgId);
        $pdo->prepare("UPDATE sch_scholarship_awards SET disbursed = disbursed + ?, status = IF(disbursed + ? >= amount_awarded,'disbursed','active') WHERE id=? AND org_id=?")
            ->execute([$amount,$amount,$id,$orgId]);
        setFlash('success','Disbursement recorded.');
        redirect('scholarships.php');
    }
}

// ── AJAX fetch ─────────────────────────────────────────────────────────
if (isset($_GET['fetch_scheme'])) {
    $r = $pdo->prepare("SELECT * FROM sch_scholarship_schemes WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch_scheme'], $orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch() ?: []); exit;
}
if (isset($_GET['fetch_award'])) {
    $r = $pdo->prepare("SELECT * FROM sch_scholarship_awards WHERE id=? AND org_id=?");
    $r->execute([(int)$_GET['fetch_award'], $orgId]);
    header('Content-Type: application/json'); echo json_encode($r->fetch() ?: []); exit;
}

// ── Summary stats ──────────────────────────────────────────────────────
$statSchemes = $statAwards = $statDisbursed = $statPending = 0;
try {
    $statSchemes  = (int)$pdo->prepare("SELECT COUNT(*) FROM sch_scholarship_schemes WHERE org_id=? AND status='active'")->execute([$orgId]) ? (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    $s = $pdo->prepare("SELECT COUNT(*) as awards, COALESCE(SUM(amount_awarded),0) as total_awarded, COALESCE(SUM(disbursed),0) as total_disbursed FROM sch_scholarship_awards WHERE org_id=?");
    $s->execute([$orgId]); $row = $s->fetch();
    $statAwards    = (int)($row['awards'] ?? 0);
    $statDisbursed = (float)($row['total_disbursed'] ?? 0);
    $statPending   = (float)(($row['total_awarded'] ?? 0) - ($row['total_disbursed'] ?? 0));

    $sc = $pdo->prepare("SELECT COUNT(*) FROM sch_scholarship_schemes WHERE org_id=? AND status='active'");
    $sc->execute([$orgId]); $statSchemes = (int)$sc->fetchColumn();
} catch (Throwable $e) {}

// ── Load schemes ───────────────────────────────────────────────────────
$schemes = [];
try {
    $s = $pdo->prepare(
        "SELECT sc.*, COUNT(aw.id) AS award_count, COALESCE(SUM(aw.amount_awarded),0) AS total_awarded
         FROM sch_scholarship_schemes sc
         LEFT JOIN sch_scholarship_awards aw ON aw.scheme_id=sc.id AND aw.status NOT IN ('cancelled','expired')
         WHERE sc.org_id=?
         GROUP BY sc.id
         ORDER BY sc.status ASC, sc.name ASC"
    );
    $s->execute([$orgId]); $schemes = $s->fetchAll();
} catch (Throwable $e) {}

// ── Load awards (with filters) ─────────────────────────────────────────
$fScheme  = (int)($_GET['scheme_id'] ?? 0);
$fStatus  = sanitize($_GET['status'] ?? '');
$fSearch  = sanitize($_GET['q'] ?? '');

$where  = 'aw.org_id=?'; $params = [$orgId];
if ($fScheme) { $where .= ' AND aw.scheme_id=?'; $params[] = $fScheme; }
if ($fStatus) { $where .= ' AND aw.status=?';    $params[] = $fStatus; }
if ($fSearch) {
    $where .= ' AND (st.first_name LIKE ? OR st.last_name LIKE ? OR st.admission_no LIKE ?)';
    $q = "%$fSearch%"; array_push($params,$q,$q,$q);
}

$awards = [];
try {
    $s = $pdo->prepare(
        "SELECT aw.*, CONCAT(st.first_name,' ',st.last_name) AS student_name, st.admission_no,
                c.name AS class_name, sc.name AS scheme_name, sc.type AS scheme_type, sc.currency
         FROM sch_scholarship_awards aw
         JOIN sch_students st ON st.id=aw.student_id
         LEFT JOIN sch_classes c ON c.id=st.class_id
         JOIN sch_scholarship_schemes sc ON sc.id=aw.scheme_id
         WHERE $where
         ORDER BY aw.awarded_date DESC, aw.id DESC"
    );
    $s->execute($params); $awards = $s->fetchAll();
} catch (Throwable $e) {}

// ── Students dropdown ──────────────────────────────────────────────────
$students = [];
try {
    $s = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, admission_no FROM sch_students WHERE org_id=? AND status='active' ORDER BY first_name");
    $s->execute([$orgId]); $students = $s->fetchAll();
} catch (Throwable $e) {}

// ── Academic years ─────────────────────────────────────────────────────
$academicYears = [];
try {
    $s = $pdo->prepare("SELECT id, name, is_current FROM sch_academic_years WHERE org_id=? ORDER BY is_current DESC, name DESC");
    $s->execute([$orgId]); $academicYears = $s->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-award me-2" style="color:<?= $moduleColor ?>"></i>Scholarships &amp; Bursaries</h4>
    <p class="text-muted mb-0">Manage scholarship schemes, award to students, and track disbursements</p>
  </div>
  <?php if ($view === 'schemes'): ?>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#schemeModal" onclick="openAddScheme()">
    <i class="fas fa-plus me-2"></i>New Scheme
  </button>
  <?php else: ?>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#awardModal" onclick="openAddAward()">
    <i class="fas fa-plus me-2"></i>Award Scholarship
  </button>
  <?php endif; ?>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-star"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statSchemes ?></div><div class="text-muted small">Active Schemes</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#0B2D4E">
          <i class="fas fa-user-check"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= $statAwards ?></div><div class="text-muted small">Students Awarded</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#1A8A4E">
          <i class="fas fa-check-double"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= number_format($statDisbursed, 2) ?></div><div class="text-muted small">Total Disbursed</div></div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body d-flex align-items-center gap-3">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:48px;height:48px;background:#fd7e14">
          <i class="fas fa-hourglass-half"></i>
        </div>
        <div><div class="fs-4 fw-bold"><?= number_format(max(0,$statPending), 2) ?></div><div class="text-muted small">Pending Disbursement</div></div>
      </div>
    </div>
  </div>
</div>

<!-- View Tabs -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $view==='awards'  ? 'active' : '' ?>" href="scholarships.php?view=awards">
      <i class="fas fa-user-check me-1"></i>Student Awards
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $view==='schemes' ? 'active' : '' ?>" href="scholarships.php?view=schemes">
      <i class="fas fa-star me-1"></i>Schemes
    </a>
  </li>
</ul>

<?php if ($view === 'schemes'): ?>
<!-- ═══════════════════════════════ SCHEMES VIEW ════════════════════════════ -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-star me-2 text-success"></i>Scholarship & Bursary Schemes</h6>
    <span class="badge bg-secondary"><?= count($schemes) ?> scheme<?= count($schemes)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Scheme Name</th>
            <th>Type</th>
            <th>Value</th>
            <th>Criteria</th>
            <th>Renewable</th>
            <th>Awards</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($schemes)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5">
            <i class="fas fa-star fa-2x d-block mb-2"></i>No schemes defined yet. Create your first scholarship scheme.
          </td></tr>
          <?php else: foreach ($schemes as $sc):
            $typeColors = ['scholarship'=>'primary','bursary'=>'success','grant'=>'info','discount'=>'warning'];
            $tc = $typeColors[$sc['type']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($sc['name']) ?></td>
            <td><span class="badge bg-<?= $tc ?>"><?= ucfirst($sc['type']) ?></span></td>
            <td class="fw-semibold">
              <?php if ($sc['value_type']==='percentage'): ?>
                <?= number_format($sc['value'],1) ?>%
              <?php else: ?>
                <?= e($sc['currency']) ?> <?= number_format($sc['value'],2) ?>
              <?php endif; ?>
            </td>
            <td>
              <span title="<?= e($sc['criteria']) ?>">
                <?= e(mb_strimwidth($sc['criteria'] ?? '—', 0, 50, '...')) ?>
              </span>
            </td>
            <td><?= $sc['renewable'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
            <td>
              <a href="scholarships.php?view=awards&scheme_id=<?= $sc['id'] ?>" class="badge bg-light text-dark border text-decoration-none">
                <?= $sc['award_count'] ?> award<?= $sc['award_count']!=1?'s':'' ?>
              </a>
            </td>
            <td><?= $sc['status']==='active' ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEditScheme(<?= $sc['id'] ?>)"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger"  onclick="delScheme(<?= $sc['id'] ?>, '<?= e($sc['name']) ?>')"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════ AWARDS VIEW ════════════════════════════ -->

<!-- Filter bar -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <input type="hidden" name="view" value="awards">
      <div class="col-sm-4">
        <input type="text" name="q" class="form-control form-control-sm" placeholder="Search student name or admission no..." value="<?= e($fSearch) ?>">
      </div>
      <div class="col-sm-3">
        <select name="scheme_id" class="form-select form-select-sm">
          <option value="">All Schemes</option>
          <?php foreach ($schemes as $sc): ?>
          <option value="<?= $sc['id'] ?>" <?= $fScheme==$sc['id']?'selected':'' ?>><?= e($sc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach(['pending','active','disbursed','cancelled','expired'] as $st): ?>
          <option value="<?= $st ?>" <?= $fStatus===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="scholarships.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-user-check me-2 text-success"></i>Student Awards</h6>
    <span class="badge bg-secondary"><?= count($awards) ?> award<?= count($awards)!=1?'s':'' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Student</th>
            <th>Class</th>
            <th>Scheme</th>
            <th>Academic Year</th>
            <th>Awarded</th>
            <th>Disbursed</th>
            <th>Balance</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($awards)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5">
            <i class="fas fa-award fa-2x d-block mb-2"></i>No awards found.
          </td></tr>
          <?php else: foreach ($awards as $aw):
            $statusColors = ['pending'=>'warning','active'=>'primary','disbursed'=>'success','cancelled'=>'danger','expired'=>'secondary'];
            $sc = $statusColors[$aw['status']] ?? 'secondary';
            $balance = max(0, (float)$aw['amount_awarded'] - (float)$aw['disbursed']);
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($aw['student_name']) ?></div>
              <small class="text-muted"><?= e($aw['admission_no']) ?></small>
            </td>
            <td><span class="badge bg-light text-dark border"><?= e($aw['class_name'] ?: '—') ?></span></td>
            <td>
              <div class="fw-semibold"><?= e($aw['scheme_name']) ?></div>
              <small class="text-muted"><?= ucfirst($aw['scheme_type']) ?></small>
            </td>
            <td><?= e($aw['academic_year'] ?: '—') ?></td>
            <td class="fw-semibold"><?= e($aw['currency']) ?> <?= number_format((float)$aw['amount_awarded'],2) ?></td>
            <td class="text-success fw-semibold"><?= e($aw['currency']) ?> <?= number_format((float)$aw['disbursed'],2) ?></td>
            <td class="<?= $balance > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>">
              <?= e($aw['currency']) ?> <?= number_format($balance,2) ?>
            </td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($aw['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEditAward(<?= $aw['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <?php if ($balance > 0 && $aw['status'] !== 'cancelled' && $aw['status'] !== 'expired'): ?>
                <button class="btn btn-outline-success" onclick="openDisburse(<?= $aw['id'] ?>, '<?= e($aw['student_name']) ?>', <?= $balance ?>, '<?= e($aw['currency']) ?>')" title="Record Disbursement">
                  <i class="fas fa-hand-holding-usd"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-outline-danger" onclick="delAward(<?= $aw['id'] ?>, '<?= e($aw['student_name']) ?>')" title="Remove"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── Scheme Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="schemeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save_scheme">
      <input type="hidden" name="id" id="scId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="scTitle"><i class="fas fa-star me-2"></i>New Scholarship Scheme</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Scheme Name <span class="text-danger">*</span></label>
            <input type="text" name="name" id="scName" class="form-control" required placeholder="e.g. Academic Excellence Award">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Type</label>
            <select name="type" id="scType" class="form-select">
              <option value="scholarship">Scholarship</option>
              <option value="bursary">Bursary</option>
              <option value="grant">Grant</option>
              <option value="discount">Fee Discount</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Value Type</label>
            <select name="value_type" id="scValType" class="form-select">
              <option value="fixed">Fixed Amount</option>
              <option value="percentage">Percentage of Fees</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Value</label>
            <input type="number" name="value" id="scValue" class="form-control" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Currency</label>
            <select name="currency" id="scCurrency" class="form-select">
              <option value="KES">KES</option>
              <option value="USD">USD</option>
              <option value="LRD">LRD</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Renewable</label>
            <select name="renewable" id="scRenewable" class="form-select">
              <option value="1">Yes (annual)</option>
              <option value="0">No (once)</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Award Criteria</label>
            <textarea name="criteria" id="scCriteria" class="form-control" rows="3" placeholder="Eligibility requirements, GPA threshold, financial need, etc."></textarea>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="scStatus" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Scheme</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Award Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="awardModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="save_award">
      <input type="hidden" name="id" id="awId" value="0">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="awTitle"><i class="fas fa-award me-2"></i>Award Scholarship</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Student <span class="text-danger">*</span></label>
            <select name="student_id" id="awStudent" class="form-select" required>
              <option value="">-- Select Student --</option>
              <?php foreach ($students as $st): ?>
              <option value="<?= $st['id'] ?>"><?= e($st['name']) ?> (<?= e($st['admission_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Scheme <span class="text-danger">*</span></label>
            <select name="scheme_id" id="awScheme" class="form-select" required onchange="prefillAmount(this)">
              <option value="">-- Select Scheme --</option>
              <?php foreach ($schemes as $sc): if ($sc['status']!=='active') continue; ?>
              <option value="<?= $sc['id'] ?>"
                data-value="<?= $sc['value'] ?>"
                data-value-type="<?= $sc['value_type'] ?>"
                data-currency="<?= $sc['currency'] ?>">
                <?= e($sc['name']) ?> (<?= $sc['value_type']==='percentage' ? $sc['value'].'%' : $sc['currency'].' '.number_format($sc['value'],2) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Amount Awarded</label>
            <input type="number" name="amount_awarded" id="awAmount" class="form-control" step="0.01" min="0" placeholder="0.00">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Academic Year</label>
            <select name="academic_year" id="awYear" class="form-select">
              <option value="">-- Not specified --</option>
              <?php foreach ($academicYears as $ay): ?>
              <option value="<?= e($ay['name']) ?>" <?= $ay['is_current'] ? 'selected' : '' ?>><?= e($ay['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Award Date</label>
            <input type="date" name="awarded_date" id="awDate" class="form-control">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="awStatus" class="form-select">
              <option value="pending">Pending</option>
              <option value="active">Active</option>
              <option value="disbursed">Fully Disbursed</option>
              <option value="cancelled">Cancelled</option>
              <option value="expired">Expired</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Already Disbursed</label>
            <input type="number" name="disbursed" id="awDisbursed" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" id="awNotes" class="form-control" rows="2" placeholder="Any conditions or remarks..."></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Award</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- ── Disburse Modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="disburseModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <form method="POST"><?= csrfField() ?>
      <input type="hidden" name="action" value="disburse">
      <input type="hidden" name="id" id="disId">
      <div class="modal-header text-white" style="background:#1A8A4E">
        <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Record Disbursement</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="mb-3 small">Recording disbursement for <strong id="disStudentName"></strong>.</p>
        <label class="form-label fw-semibold">Amount to Disburse <span class="text-muted small" id="disBalance"></span></label>
        <input type="number" name="disburse_amount" id="disAmount" class="form-control" step="0.01" min="0.01" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-check me-1"></i>Confirm</button>
      </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete forms -->
<form method="POST" id="delSchemeForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete_scheme"><input type="hidden" name="id" id="delScId"></form>
<form method="POST" id="delAwardForm"  style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete_award"> <input type="hidden" name="id" id="delAwId"></form>

<?php ob_start(); ?>
<script>
// ── Scheme modal ────────────────────────────────────────────────────────
function openAddScheme() {
  document.getElementById('scTitle').innerHTML = '<i class="fas fa-star me-2"></i>New Scholarship Scheme';
  ['scId','scName','scValue','scCriteria'].forEach(id => document.getElementById(id).value = id==='scId'?'0':'');
  document.getElementById('scType').value = 'scholarship';
  document.getElementById('scValType').value = 'fixed';
  document.getElementById('scCurrency').value = 'KES';
  document.getElementById('scRenewable').value = '1';
  document.getElementById('scStatus').value = 'active';
}
function openEditScheme(id) {
  fetch('scholarships.php?fetch_scheme='+id).then(r=>r.json()).then(d=>{
    if(!d.id) return;
    document.getElementById('scTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Scheme';
    document.getElementById('scId').value       = d.id;
    document.getElementById('scName').value     = d.name||'';
    document.getElementById('scType').value     = d.type||'scholarship';
    document.getElementById('scValType').value  = d.value_type||'fixed';
    document.getElementById('scValue').value    = d.value||'';
    document.getElementById('scCurrency').value = d.currency||'KES';
    document.getElementById('scRenewable').value= d.renewable||'1';
    document.getElementById('scCriteria').value = d.criteria||'';
    document.getElementById('scStatus').value   = d.status||'active';
    new bootstrap.Modal(document.getElementById('schemeModal')).show();
  });
}
function delScheme(id,name){
  if(confirm('Delete scheme "'+name+'"? This will also remove all associated awards.')) {
    document.getElementById('delScId').value=id; document.getElementById('delSchemeForm').submit();
  }
}

// ── Award modal ─────────────────────────────────────────────────────────
function openAddAward() {
  document.getElementById('awTitle').innerHTML = '<i class="fas fa-award me-2"></i>Award Scholarship';
  document.getElementById('awId').value = '0';
  document.getElementById('awStudent').value = '';
  document.getElementById('awScheme').value  = '';
  document.getElementById('awAmount').value  = '';
  document.getElementById('awDisbursed').value = '0';
  document.getElementById('awStatus').value  = 'pending';
  document.getElementById('awDate').value    = new Date().toISOString().split('T')[0];
  document.getElementById('awNotes').value   = '';
}
function openEditAward(id) {
  fetch('scholarships.php?fetch_award='+id).then(r=>r.json()).then(d=>{
    if(!d.id) return;
    document.getElementById('awTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Award';
    document.getElementById('awId').value        = d.id;
    document.getElementById('awStudent').value   = d.student_id||'';
    document.getElementById('awScheme').value    = d.scheme_id||'';
    document.getElementById('awAmount').value    = d.amount_awarded||'';
    document.getElementById('awDisbursed').value = d.disbursed||'0';
    document.getElementById('awYear').value      = d.academic_year||'';
    document.getElementById('awDate').value      = d.awarded_date||'';
    document.getElementById('awStatus').value    = d.status||'pending';
    document.getElementById('awNotes').value     = d.notes||'';
    new bootstrap.Modal(document.getElementById('awardModal')).show();
  });
}
function prefillAmount(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  const vt  = opt.getAttribute('data-value-type');
  const val = opt.getAttribute('data-value');
  if (vt === 'fixed') document.getElementById('awAmount').value = parseFloat(val).toFixed(2);
}
function delAward(id,name){
  if(confirm('Remove award for "'+name+'"?')){
    document.getElementById('delAwId').value=id; document.getElementById('delAwardForm').submit();
  }
}

// ── Disburse modal ──────────────────────────────────────────────────────
function openDisburse(id, name, balance, currency) {
  document.getElementById('disId').value = id;
  document.getElementById('disStudentName').textContent = name;
  document.getElementById('disBalance').textContent = '(Balance: '+currency+' '+parseFloat(balance).toFixed(2)+')';
  document.getElementById('disAmount').value = parseFloat(balance).toFixed(2);
  document.getElementById('disAmount').max   = balance;
  new bootstrap.Modal(document.getElementById('disburseModal')).show();
}
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
