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

    if ($action === 'save_penalty') {
        $memberId     = (int)$_POST['member_id'];
        $loanId       = (int)($_POST['loan_id'] ?? 0);
        $penaltyType  = sanitize($_POST['penalty_type'] ?? 'late_payment');
        $amount       = (float)$_POST['amount'];
        $penaltyDate  = sanitize($_POST['penalty_date'] ?? date('Y-m-d'));
        $status       = in_array($_POST['status'] ?? '', ['unpaid','paid','waived']) ? $_POST['status'] : 'unpaid';
        $notes        = sanitize($_POST['notes'] ?? '');

        if (!$memberId || $amount <= 0) {
            setFlash('danger', 'Member and penalty amount are required.');
        } else {
            $id = (int)($_POST['edit_id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE sacco_penalties SET member_id=?, loan_id=?, penalty_type=?, amount=?, penalty_date=?, status=?, notes=? WHERE id=? AND org_id=?")
                    ->execute([$memberId, $loanId ?: null, $penaltyType, $amount, $penaltyDate, $status, $notes, $id, $orgId]);
                setFlash('success', 'Penalty record updated.');
            } else {
                $pdo->prepare("INSERT INTO sacco_penalties (org_id,member_id,loan_id,penalty_type,amount,penalty_date,status,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $memberId, $loanId ?: null, $penaltyType, $amount, $penaltyDate, $status, $notes, $user['id']]);
                setFlash('success', 'Penalty recorded successfully.');
            }
        }
        redirect(APP_URL . '/modules/sacco/penalties.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)$_POST['penalty_id'];
        $pdo->prepare("UPDATE sacco_penalties SET status='paid' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Penalty marked as paid.');
        redirect(APP_URL . '/modules/sacco/penalties.php');
    }

    if ($action === 'waive') {
        $id = (int)$_POST['penalty_id'];
        $pdo->prepare("UPDATE sacco_penalties SET status='waived' WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Penalty waived.');
        redirect(APP_URL . '/modules/sacco/penalties.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

$activeLoans = [];
try {
    $stmt = $pdo->prepare("SELECT l.id, l.loan_no, CONCAT(m.first_name,' ',m.last_name) AS borrower FROM sacco_loans l JOIN sacco_members m ON l.member_id=m.id WHERE l.org_id=? AND l.status='active' ORDER BY l.loan_no");
    $stmt->execute([$orgId]);
    $activeLoans = $stmt->fetchAll();
} catch (Exception $e) {}

$penalties = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no, l.loan_no
                           FROM sacco_penalties p
                           JOIN sacco_members m ON p.member_id=m.id
                           LEFT JOIN sacco_loans l ON p.loan_id=l.id
                           WHERE p.org_id=? ORDER BY p.penalty_date DESC, p.id DESC");
    $stmt->execute([$orgId]);
    $penalties = $stmt->fetchAll();
} catch (Exception $e) {}

$totalUnpaid  = array_sum(array_column(array_filter($penalties, fn($p) => $p['status'] === 'unpaid'), 'amount'));
$totalPaid    = array_sum(array_column(array_filter($penalties, fn($p) => $p['status'] === 'paid'), 'amount'));
$totalWaived  = array_sum(array_column(array_filter($penalties, fn($p) => $p['status'] === 'waived'), 'amount'));

$penaltyTypes = ['late_payment','missed_payment','early_settlement','insufficient_funds','violation','other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-exclamation-circle me-2" style="color:<?= $moduleColor ?>"></i>Loan Penalties</h4>
    <p class="text-muted mb-0">Track and manage penalties imposed on members for loan defaults or violations</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#penaltyModal">
    <i class="fas fa-plus me-2"></i>Record Penalty
  </button>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e74c3c20;color:#e74c3c"><i class="fas fa-exclamation-triangle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalUnpaid) ?></div><div class="stat-label">Unpaid Penalties</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#27ae6020;color:#27ae60"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Penalties Collected</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#95a5a620;color:#95a5a6"><i class="fas fa-ban"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalWaived) ?></div><div class="stat-label">Penalties Waived</div></div>
    </div>
  </div>
</div>

<!-- Penalties Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-semibold">Penalty Records</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="penaltiesTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Member</th>
            <th>Loan Ref</th>
            <th>Type</th>
            <th class="text-end">Amount</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($penalties)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-exclamation-circle fa-3x mb-3 d-block opacity-25"></i>No penalties recorded.</td></tr>
          <?php else: foreach ($penalties as $p):
            $sMap = ['unpaid'=>'danger','paid'=>'success','waived'=>'secondary'];
            $sBadge = $sMap[$p['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="small"><?= formatDate($p['penalty_date']) ?></td>
            <td>
              <div class="fw-semibold small"><?= e($p['member_name']) ?></div>
              <small class="text-muted"><?= e($p['member_no']) ?></small>
            </td>
            <td><?= $p['loan_no'] ? '<span class="badge bg-primary">'.e($p['loan_no']).'</span>' : '<span class="text-muted small">—</span>' ?></td>
            <td><span class="badge bg-warning text-dark"><?= str_replace('_',' ',ucfirst($p['penalty_type'])) ?></span></td>
            <td class="text-end fw-bold text-danger"><?= formatCurrency($p['amount']) ?></td>
            <td><span class="badge bg-<?= $sBadge ?>"><?= ucfirst($p['status']) ?></span></td>
            <td class="text-end">
              <?php if ($p['status'] === 'unpaid'): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_paid">
                <input type="hidden" name="penalty_id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-success" title="Mark Paid"><i class="fas fa-check"></i></button>
              </form>
              <form method="POST" class="d-inline" onsubmit="return confirm('Waive this penalty?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="waive">
                <input type="hidden" name="penalty_id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-secondary" title="Waive"><i class="fas fa-ban"></i></button>
              </form>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-primary" onclick='editPenalty(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="penaltyModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="modalTitle"><i class="fas fa-exclamation-circle me-2"></i>Record Penalty</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="resetForm()"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_penalty">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
            <select name="member_id" id="fMember" class="form-select" required>
              <option value="">— Select member —</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Related Loan (optional)</label>
            <select name="loan_id" id="fLoan" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($activeLoans as $l): ?>
              <option value="<?= $l['id'] ?>"><?= e($l['loan_no']) ?> — <?= e($l['borrower']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Penalty Type</label>
            <select name="penalty_type" id="fType" class="form-select">
              <?php foreach ($penaltyTypes as $pt): ?>
              <option value="<?= $pt ?>"><?= str_replace('_',' ',ucfirst($pt)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="fStatus" class="form-select">
              <option value="unpaid">Unpaid</option>
              <option value="paid">Paid</option>
              <option value="waived">Waived</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount" id="fAmount" class="form-control" required placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Penalty Date</label>
            <input type="date" name="penalty_date" id="fDate" class="form-control" value="<?= date('Y-m-d') ?>">
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
$("#penaltiesTable").DataTable({pageLength:25,order:[[0,"desc"]]});

function editPenalty(p) {
  document.getElementById('editId').value = p.id;
  document.getElementById('fMember').value = p.member_id;
  document.getElementById('fLoan').value = p.loan_id || '';
  document.getElementById('fType').value = p.penalty_type;
  document.getElementById('fStatus').value = p.status;
  document.getElementById('fAmount').value = p.amount;
  document.getElementById('fDate').value = p.penalty_date;
  document.getElementById('fNotes').value = p.notes || '';
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Penalty';
  new bootstrap.Modal(document.getElementById('penaltyModal')).show();
}

function resetForm() {
  document.getElementById('editId').value = '';
  document.getElementById('fMember').value = '';
  document.getElementById('fLoan').value = '';
  document.getElementById('fType').value = 'late_payment';
  document.getElementById('fStatus').value = 'unpaid';
  document.getElementById('fAmount').value = '';
  document.getElementById('fDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('fNotes').value = '';
  document.getElementById('modalTitle').innerHTML = '<i class="fas fa-exclamation-circle me-2"></i>Record Penalty';
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
