<?php
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',    'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',             'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',        'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',  'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',       'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',              'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',        'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',      'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',       'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle', 'label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',           'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',         'label' => 'Reports'],
];

// ── POST Handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_guarantor') {
        $loanId      = (int)$_POST['loan_id'];
        $memberId    = (int)$_POST['guarantor_member_id'];
        $liability   = (float)($_POST['liability_amount'] ?? 0);
        $relationship= sanitize($_POST['relationship'] ?? '');
        $notes       = sanitize($_POST['notes'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','released','defaulted']) ? $_POST['status'] : 'active';

        if (!$loanId || !$memberId) {
            setFlash('danger', 'Loan and guarantor member are required.');
        } else {
            // Check not guaranteeing own loan
            $check = $pdo->prepare("SELECT member_id FROM sacco_loans WHERE id=? AND org_id=?");
            $check->execute([$loanId, $orgId]);
            $loanMember = (int)$check->fetchColumn();
            if ($loanMember === $memberId) {
                setFlash('danger', 'A member cannot guarantee their own loan.');
            } else {
                $id = (int)($_POST['edit_id'] ?? 0);
                if ($id) {
                    $pdo->prepare("UPDATE sacco_guarantors SET loan_id=?, guarantor_member_id=?, liability_amount=?, relationship=?, notes=?, status=? WHERE id=? AND org_id=?")
                        ->execute([$loanId, $memberId, $liability, $relationship, $notes, $status, $id, $orgId]);
                    setFlash('success', 'Guarantor record updated.');
                } else {
                    $pdo->prepare("INSERT INTO sacco_guarantors (org_id,loan_id,guarantor_member_id,liability_amount,relationship,notes,status,recorded_by) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$orgId, $loanId, $memberId, $liability, $relationship, $notes, $status, $user['id']]);
                    setFlash('success', 'Guarantor added successfully.');
                }
            }
        }
        redirect(APP_URL . '/modules/sacco/guarantors.php');
    }

    if ($action === 'delete_guarantor') {
        $id = (int)$_POST['delete_id'];
        $pdo->prepare("DELETE FROM sacco_guarantors WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Guarantor record removed.');
        redirect(APP_URL . '/modules/sacco/guarantors.php');
    }
}

// ── AJAX fetch ────────────────────────────────────────────────────────────
if (isset($_GET['fetch'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId = (int)$user['org_id'];
    $stmt  = $pdo->prepare("SELECT * FROM sacco_guarantors WHERE id=? AND org_id=?");
    $stmt->execute([(int)$_GET['fetch'], $orgId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

// Active members
$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

// Active loans
$activeLoans = [];
try {
    $stmt = $pdo->prepare("SELECT l.id, l.loan_no, l.amount, l.outstanding_balance, CONCAT(m.first_name,' ',m.last_name) AS borrower
                           FROM sacco_loans l JOIN sacco_members m ON l.member_id=m.id
                           WHERE l.org_id=? AND l.status IN ('active','approved') ORDER BY l.loan_no");
    $stmt->execute([$orgId]);
    $activeLoans = $stmt->fetchAll();
} catch (Exception $e) {}

// Guarantors list
$guarantors = [];
try {
    $stmt = $pdo->prepare("SELECT g.*, l.loan_no, l.amount AS loan_amount,
                           CONCAT(b.first_name,' ',b.last_name) AS borrower_name,
                           CONCAT(gm.first_name,' ',gm.last_name) AS guarantor_name, gm.member_no AS guarantor_no
                           FROM sacco_guarantors g
                           JOIN sacco_loans l ON g.loan_id=l.id
                           JOIN sacco_members b ON l.member_id=b.id
                           JOIN sacco_members gm ON g.guarantor_member_id=gm.id
                           WHERE g.org_id=? ORDER BY g.created_at DESC");
    $stmt->execute([$orgId]);
    $guarantors = $stmt->fetchAll();
} catch (Exception $e) {}

// KPIs
$totalGuarantors = count($guarantors);
$activeCount     = count(array_filter($guarantors, fn($g) => $g['status'] === 'active'));
$totalLiability  = array_sum(array_column(array_filter($guarantors, fn($g) => $g['status'] === 'active'), 'liability_amount'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-user-shield me-2" style="color:<?= $moduleColor ?>"></i>Loan Guarantors</h4>
    <p class="text-muted mb-0">Manage members who guarantee loans for other SACCO members</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#guarantorModal">
    <i class="fas fa-plus me-2"></i>Add Guarantor
  </button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $moduleColor ?>20;color:<?= $moduleColor ?>"><i class="fas fa-user-shield"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalGuarantors ?></div><div class="stat-label">Total Guarantors</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#27ae6020;color:#27ae60"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Guarantors</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e74c3c20;color:#e74c3c"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalLiability) ?></div><div class="stat-label">Active Liability</div></div>
    </div>
  </div>
</div>

<!-- Guarantors Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-semibold">Guarantor Registry</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="guarantorsTable">
        <thead class="table-light">
          <tr>
            <th>Guarantor</th>
            <th>Loan Ref</th>
            <th>Borrower</th>
            <th>Relationship</th>
            <th class="text-end">Liability</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($guarantors)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-user-shield fa-3x mb-3 d-block opacity-25"></i>No guarantor records found.</td></tr>
          <?php else: foreach ($guarantors as $g):
            $statusMap = ['active'=>'success','released'=>'secondary','defaulted'=>'danger'];
            $sBadge = $statusMap[$g['status']] ?? 'secondary';
          ?>
          <tr>
            <td>
              <div class="fw-semibold"><?= e($g['guarantor_name']) ?></div>
              <small class="text-muted"><?= e($g['guarantor_no']) ?></small>
            </td>
            <td><span class="badge bg-primary"><?= e($g['loan_no']) ?></span></td>
            <td class="small"><?= e($g['borrower_name']) ?></td>
            <td class="small"><?= e($g['relationship'] ?: '—') ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency($g['liability_amount']) ?></td>
            <td><span class="badge bg-<?= $sBadge ?>"><?= ucfirst($g['status']) ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" onclick='fillForm(<?= json_encode($g) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Remove this guarantor record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_guarantor">
                <input type="hidden" name="delete_id" value="<?= $g['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="guarantorModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="modalTitle"><i class="fas fa-user-shield me-2"></i>Add Guarantor</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="resetForm()"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_guarantor">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Loan <span class="text-danger">*</span></label>
            <select name="loan_id" id="fLoan" class="form-select" required>
              <option value="">— Select active loan —</option>
              <?php foreach ($activeLoans as $l): ?>
              <option value="<?= $l['id'] ?>"><?= e($l['loan_no']) ?> — <?= e($l['borrower']) ?> (<?= formatCurrency($l['outstanding_balance']) ?> outstanding)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Guarantor Member <span class="text-danger">*</span></label>
            <select name="guarantor_member_id" id="fMember" class="form-select" required>
              <option value="">— Select guarantor —</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Liability Amount</label>
            <input type="number" step="0.01" min="0" name="liability_amount" id="fLiability" class="form-control" placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="fStatus" class="form-select">
              <option value="active">Active</option>
              <option value="released">Released</option>
              <option value="defaulted">Defaulted</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Relationship to Borrower</label>
            <input type="text" name="relationship" id="fRelationship" class="form-control" maxlength="100" placeholder="e.g. Spouse, Colleague, Friend">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="resetForm()">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
$("#guarantorsTable").DataTable({pageLength:25,order:[[0,"asc"]]});

function fillForm(g) {
  document.getElementById('editId').value = g.id;
  document.getElementById('fLoan').value = g.loan_id;
  document.getElementById('fMember').value = g.guarantor_member_id;
  document.getElementById('fLiability').value = g.liability_amount;
  document.getElementById('fStatus').value = g.status;
  document.getElementById('fRelationship').value = g.relationship || '';
  document.getElementById('fNotes').value = g.notes || '';
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Guarantor';
  new bootstrap.Modal(document.getElementById('guarantorModal')).show();
}

function resetForm() {
  document.getElementById('editId').value = '';
  document.getElementById('fLoan').value = '';
  document.getElementById('fMember').value = '';
  document.getElementById('fLiability').value = '';
  document.getElementById('fStatus').value = 'active';
  document.getElementById('fRelationship').value = '';
  document.getElementById('fNotes').value = '';
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-shield me-2"></i>Add Guarantor';
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
