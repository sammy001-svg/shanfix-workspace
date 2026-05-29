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
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0); // 0 means anonymous
        $type = in_array($_POST['type'] ?? '', ['tithe','offering','first_fruit','building_fund','mission','welfare','other']) ? $_POST['type'] : 'offering';
        $amount = (float)$_POST['amount'];
        $date = $_POST['date'] ?? date('Y-m-d');
        $paymentMethod = sanitize($_POST['payment_method'] ?? 'cash');
        $reference = sanitize($_POST['reference'] ?? '');
        $notes = sanitize($_POST['notes'] ?? '');
        $receivedBy = (int)$user['id'];

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE church_offerings SET member_id = ?, type = ?, amount = ?, date = ?, payment_method = ?, reference = ?, notes = ?, received_by = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$memberId > 0 ? $memberId : null, $type, $amount, $date, $paymentMethod, $reference, $notes, $receivedBy, $id, $orgId]);
            setFlash('success', 'Church contribution record updated.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO church_offerings (org_id, member_id, type, amount, date, payment_method, reference, notes, received_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $memberId > 0 ? $memberId : null, $type, $amount, $date, $paymentMethod, $reference, $notes, $receivedBy]);
            
            // Auto log into central accounts ledgers
            try {
                $donorName = 'Anonymous Donor';
                if ($memberId > 0) {
                    $st = $pdo->prepare("SELECT CONCAT(first_name,' ',last_name) FROM church_members WHERE id = ?");
                    $st->execute([$memberId]);
                    $donorName = $st->fetchColumn() ?: 'Anonymous Donor';
                }

                $pdo->prepare("INSERT INTO accounts_ledgers (org_id, category, title, description, amount, type, tx_date, reference, method) 
                               VALUES (?, 'revenue', ?, ?, ?, 'credit', ?, ?, ?)")
                    ->execute([$orgId, "Church: ".ucfirst($type), "Church contribution from $donorName", $amount, $date, $reference, $paymentMethod]);
            } catch (Exception $ex) {}

            setFlash('success', 'Contribution record successfully registered in accounts ledger.');
        }
        logActivity($id > 0 ? 'update' : 'create', 'church', "Contribution logged: $type, Amount: $amount, Ref: $reference");
        redirect('offerings.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM church_offerings WHERE id = ? AND org_id = ?")->execute([$id, $orgId]);
        setFlash('success', 'Contribution record removed.');
        redirect('offerings.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fType = $_GET['type'] ?? '';
$where = 'o.org_id = ?';
$params = [$orgId];

if ($fType !== '') {
    $where .= ' AND o.type = ?';
    $params[] = $fType;
}

$offeringsList = [];
try {
    $stmt = $pdo->prepare("SELECT o.*, 
                                  CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.member_no,
                                  u.name AS receiver_name
                           FROM church_offerings o
                           LEFT JOIN church_members m ON o.member_id = m.id
                           LEFT JOIN users u ON o.received_by = u.id
                           WHERE $where
                           ORDER BY o.date DESC");
    $stmt->execute($params);
    $offeringsList = $stmt->fetchAll();
} catch (Exception $e) {}

$membersList = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS member_name, member_no 
                           FROM church_members 
                           WHERE org_id = ? AND status = 'active'
                           ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $membersList = $stmt->fetchAll();
} catch (Exception $e) {}

// Detail mapping for AJAX edit
if (isset($_GET['fetch_details'])) {
    $oid = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM church_offerings WHERE id = ? AND org_id = ?");
        $stmt->execute([$oid, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}

// Receipt generation view
$printId = (int)($_GET['print'] ?? 0);
$receiptData = null;
if ($printId > 0) {
    try {
        $stmt = $pdo->prepare("SELECT o.*, 
                                      CONCAT(m.first_name, ' ', m.last_name) AS member_name, m.member_no, m.phone,
                                      u.name AS receiver_name
                               FROM church_offerings o
                               LEFT JOIN church_members m ON o.member_id = m.id
                               LEFT JOIN users u ON o.received_by = u.id
                               WHERE o.id = ? AND o.org_id = ?");
        $stmt->execute([$printId, $orgId]);
        $receiptData = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
}
?>

<?php if ($receiptData): ?>
<!-- RECEIPT PRINT CARD -->
<div class="row justify-content-center">
  <div class="col-md-6">
    <div class="card shadow-lg border-0" id="receiptPrintArea">
      <div class="card-body p-4 text-dark text-center">
        <div class="mb-4">
          <i class="fas fa-church fa-3x mb-2 text-primary" style="color:<?= $moduleColor ?> !important"></i>
          <h4 class="fw-bold mb-1 text-uppercase">CHURCH WORSHIP CENTER</h4>
          <p class="text-muted small mb-0">Faith, Hope, and Community Fellowship</p>
          <hr class="my-3">
          <h5 class="fw-bold text-dark mb-0">OFFICIAL CONTRIBUTION SLIP</h5>
          <small class="text-muted">Ref No: <strong><?= e($receiptData['reference'] ?: 'C-TX-' . $receiptData['id']) ?></strong></small>
        </div>

        <table class="table table-borderless text-start small mb-4 mx-auto" style="max-width:85%;">
          <tr>
            <td class="text-muted py-1" style="width:40%;">Member Name:</td>
            <td class="fw-bold text-dark py-1"><?= e($receiptData['member_name'] ?: 'Anonymous Giver') ?></td>
          </tr>
          <?php if ($receiptData['member_no']): ?>
          <tr>
            <td class="text-muted py-1">Member Number:</td>
            <td class="fw-semibold py-1"><?= e($receiptData['member_no']) ?></td>
          </tr>
          <?php endif; ?>
          <tr>
            <td class="text-muted py-1">Contribution Type:</td>
            <td class="fw-bold text-dark py-1"><span class="badge bg-light text-dark border text-uppercase"><?= str_replace('_', ' ', $receiptData['type']) ?></span></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Worship Service Date:</td>
            <td class="fw-semibold py-1"><?= formatDate($receiptData['date']) ?></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Payment Method:</td>
            <td class="py-1"><span class="badge bg-secondary"><?= ucfirst($receiptData['payment_method']) ?></span></td>
          </tr>
          <tr>
            <td class="text-muted py-1">Recorded By:</td>
            <td class="py-1"><?= e($receiptData['receiver_name'] ?: 'Church Admin') ?></td>
          </tr>
        </table>

        <div class="bg-light p-3 rounded mb-4 text-center mx-auto" style="max-width:85%;">
          <div class="text-muted small mb-1">CONTRIBUTED AMOUNT</div>
          <h2 class="fw-bold text-dark mb-0"><?= formatCurrency($receiptData['amount']) ?></h2>
          <small class="text-muted text-uppercase">God bless you for your generous giving!</small>
        </div>

        <?php if ($receiptData['notes']): ?>
        <p class="small text-muted mb-4"><em>"<?= e($receiptData['notes']) ?>"</em></p>
        <?php endif; ?>

        <div class="row small text-muted text-center pt-3 border-top mx-auto" style="max-width:85%;">
          <div class="col-12">
            <div class="border-bottom mx-auto mb-2" style="width:40%;height:30px;"></div>
            <div>Treasurer / Clerk Stamp</div>
          </div>
        </div>

        <div class="text-center mt-4 pt-2 small text-muted">
          <em>"Give, and it will be given to you." - Luke 6:38</em><br>
          Slip Generated on <?= date('Y-m-d H:i') ?>
        </div>
      </div>
    </div>
    <div class="text-center mt-3 mb-5">
      <button class="btn btn-primary" onclick="printReceipt()"><i class="fas fa-print me-2"></i>Print Slip</button>
      <a href="offerings.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-arrow-left me-2"></i>Back to Offerings</a>
    </div>
  </div>
</div>
<script>
function printReceipt() {
  var printContents = document.getElementById('receiptPrintArea').innerHTML;
  var originalContents = document.body.innerHTML;
  document.body.innerHTML = printContents;
  window.print();
  document.body.innerHTML = originalContents;
  window.location.reload();
}
</script>
<?php else: ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-hand-holding-heart me-2" style="color:<?= $moduleColor ?>"></i>Tithes & Offerings</h4>
    <p class="text-muted mb-0">Record worship offerings, tithes collections, building fund drives, and print thank-you slips</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#offModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Record Contribution</button>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Contribution Category</label>
        <select name="type" class="form-select form-select-sm">
          <option value="">All Categories</option>
          <option value="tithe" <?= $fType === 'tithe' ? 'selected' : '' ?>>Tithe</option>
          <option value="offering" <?= $fType === 'offering' ? 'selected' : '' ?>>Offering</option>
          <option value="first_fruit" <?= $fType === 'first_fruit' ? 'selected' : '' ?>>First Fruit</option>
          <option value="building_fund" <?= $fType === 'building_fund' ? 'selected' : '' ?>>Building Fund</option>
          <option value="mission" <?= $fType === 'mission' ? 'selected' : '' ?>>Mission / Evangelism</option>
          <option value="welfare" <?= $fType === 'welfare' ? 'selected' : '' ?>>Welfare / Benevolence</option>
          <option value="other" <?= $fType === 'other' ? 'selected' : '' ?>>Other / Special Seed</option>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="offerings.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 text-dark fw-bold"><i class="fas fa-hand-holding-heart me-2 text-primary"></i>Contributions Ledger</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Member / Giver</th>
            <th>Contribution Category</th>
            <th>Payment Method</th>
            <th>Tx Reference</th>
            <th class="text-end">Amount Paid</th>
            <th>Recorder</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($offeringsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-coins fa-2x mb-2 d-block"></i>No offerings logged yet.</td></tr>
          <?php else: foreach ($offeringsList as $o): ?>
          <tr>
            <td><?= formatDate($o['date']) ?></td>
            <td>
              <?php if ($o['member_id']): ?>
              <div class="fw-bold text-dark"><?= e($o['member_name']) ?></div>
              <small class="text-muted">No: <?= e($o['member_no']) ?></small>
              <?php else: ?>
              <span class="text-muted italic"><i class="fas fa-user-secret me-1"></i>Anonymous Donor</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-light text-dark border text-uppercase"><?= str_replace('_', ' ', $o['type']) ?></span></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($o['payment_method']) ?></span></td>
            <td><span class="badge bg-secondary"><?= e($o['reference'] ?: 'Cash-Tx') ?></span></td>
            <td class="text-end fw-bold text-dark fs-6"><?= formatCurrency($o['amount']) ?></td>
            <td><?= e($o['receiver_name'] ?: 'System') ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <a href="offerings.php?print=<?= $o['id'] ?>" class="btn btn-outline-info" title="Slip"><i class="fas fa-print"></i></a>
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $o['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delOff(<?= $o['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="offModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="offId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="offTitle"><i class="fas fa-plus me-2"></i>Record Contribution</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label fw-semibold">Select Member (Leave blank for Anonymous)</label>
        <select name="member_id" id="offMemberId" class="form-select select2-enable" style="width:100%;">
          <option value="">-- Anonymous Donor --</option>
          <?php foreach ($membersList as $m): ?>
          <option value="<?= $m['id'] ?>"><?= e($m['member_name']) ?> (<?= e($m['member_no']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Contribution Category <span class="text-danger">*</span></label>
        <select name="type" id="offType" class="form-select" required>
          <option value="offering">Offering</option>
          <option value="tithe">Tithe</option>
          <option value="first_fruit">First Fruit</option>
          <option value="building_fund">Building Fund</option>
          <option value="mission">Mission / Evangelism</option>
          <option value="welfare">Welfare / Benevolence</option>
          <option value="other">Other / Special Seed</option>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Amount <span class="text-danger">*</span></label>
        <input type="number" name="amount" id="offAmount" class="form-control" required min="0.1" placeholder="e.g. 5000">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Date Received <span class="text-danger">*</span></label>
        <input type="date" name="date" id="offDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-6">
        <label class="form-label fw-semibold">Payment Method <span class="text-danger">*</span></label>
        <select name="payment_method" id="offMethod" class="form-select" required>
          <option value="cash">Cash</option>
          <option value="mpesa">M-Pesa</option>
          <option value="bank">Bank Transfer</option>
          <option value="cheque">Cheque</option>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Transaction Reference / Code</label>
        <input type="text" name="reference" id="offRef" class="form-control" placeholder="e.g. MPESA-RQL7X9T1B4, CHQ-0821">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Clerk Notes / Remarks</label>
        <textarea name="notes" id="offNotes" class="form-control" rows="2" placeholder="e.g. Family thanksgiving, building seed"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Record Offering</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delOffForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delOffId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('offTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Record Contribution';
  document.getElementById('offId').value = '0';
  document.getElementById('offMemberId').value = '';
  document.getElementById('offType').value = 'offering';
  document.getElementById('offAmount').value = '';
  document.getElementById('offDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('offMethod').value = 'cash';
  document.getElementById('offRef').value = '';
  document.getElementById('offNotes').value = '';
}
function openEdit(id) {
  fetch('offerings.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('offTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Contribution Record';
      document.getElementById('offId').value = data.id;
      document.getElementById('offMemberId').value = data.member_id || '';
      document.getElementById('offType').value = data.type;
      document.getElementById('offAmount').value = data.amount;
      document.getElementById('offDate').value = data.date;
      document.getElementById('offMethod').value = data.payment_method;
      document.getElementById('offRef').value = data.reference || '';
      document.getElementById('offNotes').value = data.notes || '';
      
      new bootstrap.Modal(document.getElementById('offModal')).show();
    });
}
function delOff(id) {
  Swal.fire({
    title: 'Delete Contribution Entry?',
    text: 'Remove this offering/tithe record? Central ledger logs will not be altered.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delOffId').value = id;
      document.getElementById('delOffForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
endif;
?>
