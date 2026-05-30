<?php
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'pledges.php',   'icon' => 'fas fa-handshake',          'label' => 'Pledges'],
    ['url' => 'projects.php',  'icon' => 'fas fa-project-diagram',    'label' => 'Projects'],
    ['url' => 'notices.php',   'icon' => 'fas fa-bell',               'label' => 'Notices'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_pledge') {
        $memberId    = (int)$_POST['member_id'];
        $pledgeType  = sanitize($_POST['pledge_type'] ?? 'building');
        $amount      = (float)$_POST['amount'];
        $paidAmount  = (float)($_POST['paid_amount'] ?? 0);
        $pledgeDate  = sanitize($_POST['pledge_date'] ?? date('Y-m-d'));
        $dueDate     = sanitize($_POST['due_date'] ?? '');
        $status      = in_array($_POST['status'] ?? '', ['active','fulfilled','defaulted','cancelled']) ? $_POST['status'] : 'active';
        $notes       = sanitize($_POST['notes'] ?? '');

        if (!$memberId || $amount <= 0) {
            setFlash('danger', 'Member and pledge amount are required.');
        } else {
            $id = (int)($_POST['edit_id'] ?? 0);
            if ($id) {
                $pdo->prepare("UPDATE church_pledges SET member_id=?,pledge_type=?,amount=?,paid_amount=?,pledge_date=?,due_date=?,status=?,notes=? WHERE id=? AND org_id=?")
                    ->execute([$memberId, $pledgeType, $amount, $paidAmount, $pledgeDate, $dueDate ?: null, $status, $notes, $id, $orgId]);
                setFlash('success', 'Pledge updated.');
            } else {
                $pdo->prepare("INSERT INTO church_pledges (org_id,member_id,pledge_type,amount,paid_amount,pledge_date,due_date,status,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)")
                    ->execute([$orgId, $memberId, $pledgeType, $amount, $paidAmount, $pledgeDate, $dueDate ?: null, $status, $notes, $user['id']]);
                setFlash('success', 'Pledge recorded.');
            }
        }
        redirect(APP_URL . '/modules/church/pledges.php');
    }

    if ($action === 'record_payment') {
        $id      = (int)$_POST['pledge_id'];
        $payment = (float)$_POST['payment'];
        $stmt = $pdo->prepare("SELECT amount, paid_amount FROM church_pledges WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $row = $stmt->fetch();
        if ($row) {
            $newPaid = $row['paid_amount'] + $payment;
            $status  = $newPaid >= $row['amount'] ? 'fulfilled' : 'active';
            $pdo->prepare("UPDATE church_pledges SET paid_amount=?, status=? WHERE id=? AND org_id=?")->execute([$newPaid, $status, $id, $orgId]);
            setFlash('success', 'Payment of ' . formatCurrency($payment) . ' recorded.');
        }
        redirect(APP_URL . '/modules/church/pledges.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name FROM church_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

$pledges = [];
try {
    $stmt = $pdo->prepare("SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
                           FROM church_pledges p JOIN church_members m ON p.member_id=m.id
                           WHERE p.org_id=? ORDER BY p.pledge_date DESC");
    $stmt->execute([$orgId]);
    $pledges = $stmt->fetchAll();
} catch (Exception $e) {}

$totalPledged   = array_sum(array_column($pledges, 'amount'));
$totalCollected = array_sum(array_column($pledges, 'paid_amount'));
$totalBalance   = $totalPledged - $totalCollected;
$fulfilled      = count(array_filter($pledges, fn($p) => $p['status'] === 'fulfilled'));

$pledgeTypes = ['building','welfare','missions','equipment','land','tithe','special','other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-handshake me-2" style="color:<?= $moduleColor ?>"></i>Member Pledges</h4>
    <p class="text-muted mb-0">Track financial pledges and their fulfillment status</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#pledgeModal">
    <i class="fas fa-plus me-2"></i>Record Pledge
  </button>
</div>

<div class="row g-3 mb-4">
  <?php foreach ([
    ['Total Pledged',    formatCurrency($totalPledged),   $moduleColor,  'fas fa-handshake'],
    ['Collected',        formatCurrency($totalCollected),  '#27ae60',     'fas fa-check-circle'],
    ['Outstanding',      formatCurrency($totalBalance),    '#e74c3c',     'fas fa-exclamation-circle'],
    ['Fulfilled',        $fulfilled . ' pledges',           '#3498db',     'fas fa-trophy'],
  ] as [$label, $val, $color, $icon]): ?>
  <div class="col-sm-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $color ?>20;color:<?= $color ?>"><i class="<?= $icon ?>"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $val ?></div><div class="stat-label"><?= $label ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-semibold">Pledge Registry</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="pledgesTable">
        <thead class="table-light">
          <tr><th>Member</th><th>Type</th><th>Date</th><th class="text-end">Pledged</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($pledges)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-handshake fa-3x d-block mb-3 opacity-25"></i>No pledges recorded.</td></tr>
          <?php else: foreach ($pledges as $p):
            $bal = $p['amount'] - $p['paid_amount'];
            $pct = $p['amount'] > 0 ? min(100, round($p['paid_amount']/$p['amount']*100)) : 0;
            $sMap = ['active'=>'primary','fulfilled'=>'success','defaulted'=>'danger','cancelled'=>'secondary'];
          ?>
          <tr>
            <td class="fw-semibold small"><?= e($p['member_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= str_replace('_',' ',ucfirst($p['pledge_type'])) ?></span></td>
            <td class="small"><?= formatDate($p['pledge_date']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency($p['amount']) ?></td>
            <td class="text-end text-success"><?= formatCurrency($p['paid_amount']) ?></td>
            <td class="text-end <?= $bal > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($bal) ?></td>
            <td>
              <div class="d-flex flex-column gap-1">
                <span class="badge bg-<?= $sMap[$p['status']] ?? 'secondary' ?>"><?= ucfirst($p['status']) ?></span>
                <div class="progress" style="height:4px;width:60px">
                  <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                </div>
              </div>
            </td>
            <td class="text-end">
              <?php if ($p['status'] === 'active' && $bal > 0): ?>
              <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#payModal"
                      onclick="document.getElementById('payPledgeId').value=<?= $p['id'] ?>;document.getElementById('payMax').value=<?= $bal ?>">
                <i class="fas fa-plus"></i>
              </button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-secondary" onclick='fillPledge(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Pledge Modal -->
<div class="modal fade" id="pledgeModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="pledgeModalTitle"><i class="fas fa-handshake me-2"></i>Record Pledge</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="resetPledge()"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_pledge">
        <input type="hidden" name="edit_id" id="editId" value="">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
            <select name="member_id" id="fMember" class="form-select" required>
              <option value="">— Select member —</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Pledge Type</label>
            <select name="pledge_type" id="fType" class="form-select">
              <?php foreach ($pledgeTypes as $pt): ?>
              <option value="<?= $pt ?>"><?= ucfirst($pt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="fStatus" class="form-select">
              <option value="active">Active</option>
              <option value="fulfilled">Fulfilled</option>
              <option value="defaulted">Defaulted</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Pledged Amount <span class="text-danger">*</span></label>
            <input type="number" step="0.01" min="0.01" name="amount" id="fAmount" class="form-control" required placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Amount Paid</label>
            <input type="number" step="0.01" min="0" name="paid_amount" id="fPaid" class="form-control" value="0" placeholder="0.00">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Pledge Date</label>
            <input type="date" name="pledge_date" id="fDate" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Due Date</label>
            <input type="date" name="due_date" id="fDue" class="form-control">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" id="fNotes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="resetPledge()">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Pay Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content">
      <div class="modal-header"><h6 class="modal-title">Record Payment</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="record_payment">
        <input type="hidden" name="pledge_id" id="payPledgeId">
        <div class="modal-body">
          <label class="form-label fw-semibold">Payment Amount</label>
          <input type="number" step="0.01" min="0.01" name="payment" id="payMax" class="form-control" required placeholder="0.00">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Record</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
$("#pledgesTable").DataTable({pageLength:25,order:[[2,"desc"]]});
function fillPledge(p) {
  document.getElementById('editId').value = p.id;
  document.getElementById('fMember').value = p.member_id;
  document.getElementById('fType').value = p.pledge_type;
  document.getElementById('fStatus').value = p.status;
  document.getElementById('fAmount').value = p.amount;
  document.getElementById('fPaid').value = p.paid_amount;
  document.getElementById('fDate').value = p.pledge_date;
  document.getElementById('fDue').value = p.due_date || '';
  document.getElementById('fNotes').value = p.notes || '';
  document.getElementById('pledgeModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Pledge';
  new bootstrap.Modal(document.getElementById('pledgeModal')).show();
}
function resetPledge() {
  document.getElementById('editId').value = '';
  document.getElementById('pledgeModalTitle').innerHTML = '<i class="fas fa-handshake me-2"></i>Record Pledge';
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
