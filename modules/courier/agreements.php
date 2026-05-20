<?php
$moduleSlug  = 'courier';
$moduleName  = 'Courier Management';
$moduleIcon  = 'fas fa-shipping-fast';
$moduleColor = '#1565c0';
$moduleNav   = [
    ['url' => 'index.php',      'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'couriers.php',   'icon' => 'fas fa-box',             'label' => 'Couriers'],
    ['url' => 'tracking.php',   'icon' => 'fas fa-map-marker-alt',  'label' => 'Tracking'],
    ['url' => 'payments.php',   'icon' => 'fas fa-credit-card',     'label' => 'Payments'],
    ['url' => 'agents.php',     'icon' => 'fas fa-user-tie',        'label' => 'Agents'],
    ['url' => 'agreements.php', 'icon' => 'fas fa-file-contract',   'label' => 'Agreements'],
    ['url' => 'setup.php',      'icon' => 'fas fa-cog',             'label' => 'Setup'],
    ['url' => 'reports.php',    'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id               = (int)($_POST['id'] ?? 0);
        $clientName       = sanitize($_POST['client_name'] ?? '');
        $clientEmail      = sanitize($_POST['client_email'] ?? '');
        $clientPhone      = sanitize($_POST['client_phone'] ?? '');
        $clientCompany    = sanitize($_POST['client_company'] ?? '');
        $serviceLevel     = sanitize($_POST['service_level'] ?? '');
        $startDate        = $_POST['start_date'] ?: null;
        $endDate          = $_POST['end_date'] ?: null;
        $deliveryTimeframe= sanitize($_POST['delivery_timeframe'] ?? '');
        $qualityStandards = sanitize($_POST['quality_standards'] ?? '');
        $contractDetails  = $_POST['contract_details'] ?? '';
        $status           = in_array($_POST['status'] ?? '', ['active','expired','terminated','draft']) ? $_POST['status'] : 'active';

        if ($id > 0) {
            $pdo->prepare("UPDATE courier_agreements SET client_name=?, client_email=?, client_phone=?, client_company=?,
                service_level=?, start_date=?, end_date=?, delivery_timeframe=?, quality_standards=?,
                contract_details=?, status=? WHERE id=? AND org_id=?")->execute([
                $clientName, $clientEmail, $clientPhone, $clientCompany,
                $serviceLevel, $startDate, $endDate, $deliveryTimeframe, $qualityStandards,
                $contractDetails, $status, $id, $orgId
            ]);
            setFlash('success', 'Service agreement updated.');
        } else {
            $pdo->prepare("INSERT INTO courier_agreements (org_id, client_name, client_email, client_phone, client_company,
                service_level, start_date, end_date, delivery_timeframe, quality_standards, contract_details, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
                $orgId, $clientName, $clientEmail, $clientPhone, $clientCompany,
                $serviceLevel, $startDate, $endDate, $deliveryTimeframe, $qualityStandards,
                $contractDetails, $status
            ]);
            setFlash('success', "Agreement with '$clientName' created.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'courier', "Agreement: $clientName ($clientCompany)");
        redirect('agreements.php');
    }

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM courier_agreements WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Agreement deleted.');
        redirect('agreements.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$where   = 'org_id = ?';
$params  = [$orgId];
if ($fStatus !== '') { $where .= ' AND status = ?'; $params[] = $fStatus; }

$agreementsList = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM courier_agreements WHERE $where ORDER BY start_date DESC");
    $stmt->execute($params);
    $agreementsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Auto-expire agreements past end date
$today = date('Y-m-d');

if (isset($_GET['fetch_agreement'])) {
    $aid  = (int)$_GET['fetch_agreement'];
    $stmt = $pdo->prepare("SELECT * FROM courier_agreements WHERE id=? AND org_id=?");
    $stmt->execute([$aid, $orgId]);
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) { header('Content-Type: application/json'); echo json_encode($row); exit; }
}

$totalActive    = countRows('courier_agreements', "org_id=? AND status='active'", [$orgId]);
$totalExpired   = countRows('courier_agreements', "org_id=? AND status='expired'", [$orgId]);
$totalDraft     = countRows('courier_agreements', "org_id=? AND status='draft'", [$orgId]);
$statusColors   = ['active' => 'success', 'expired' => 'secondary', 'terminated' => 'danger', 'draft' => 'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-contract me-2" style="color:<?= $moduleColor ?>"></i>Service Agreements</h4>
    <p class="text-muted mb-0">Manage courier service contracts, delivery timeframes, and client commitments</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#agreementModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>New Agreement</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-file-contract"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active Agreements</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-file-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalDraft ?></div><div class="stat-label">Draft Agreements</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#f5f5f5;color:#6c757d"><i class="fas fa-archive"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalExpired ?></div><div class="stat-label">Expired / Terminated</div></div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['active','draft','expired','terminated'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="agreements.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-file-contract me-2 text-primary"></i>Service Agreements (<?= count($agreementsList) ?>)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Client</th>
            <th>Company</th>
            <th>Service Level</th>
            <th>Delivery Timeframe</th>
            <th>Contract Period</th>
            <th>Days Remaining</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($agreementsList)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-file-contract fa-2x mb-2 d-block"></i>No service agreements found.</td></tr>
          <?php else: foreach ($agreementsList as $ag):
            $sc = $statusColors[$ag['status']] ?? 'secondary';
            $daysLeft = null;
            $daysClass = '';
            if ($ag['end_date'] && $ag['status'] === 'active') {
                $daysLeft = (int)floor((strtotime($ag['end_date']) - strtotime($today)) / 86400);
                $daysClass = $daysLeft < 0 ? 'text-danger fw-bold' : ($daysLeft <= 30 ? 'text-warning fw-bold' : 'text-success');
            }
          ?>
          <tr>
            <td>
              <div class="fw-bold text-dark"><?= e($ag['client_name']) ?></div>
              <small class="text-muted"><?= e($ag['client_email']) ?></small>
            </td>
            <td><?= e($ag['client_company'] ?: '—') ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($ag['service_level'] ?: '—') ?></span></td>
            <td class="small"><?= e($ag['delivery_timeframe'] ?: '—') ?></td>
            <td class="small">
              <?php if ($ag['start_date']): ?>
              <div><?= formatDate($ag['start_date']) ?></div>
              <small class="text-muted">to <?= $ag['end_date'] ? formatDate($ag['end_date']) : 'Open-ended' ?></small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td class="<?= $daysClass ?>">
              <?php if ($daysLeft !== null): ?>
                <?= $daysLeft < 0 ? 'Expired ' . abs($daysLeft) . 'd ago' : $daysLeft . ' days' ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper($ag['status']) ?></span></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $ag['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delAgreement(<?= $ag['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Agreement Modal -->
<div class="modal fade" id="agreementModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="agreementId" value="0">
  <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
    <h5 class="modal-title" id="agreementModalTitle"><i class="fas fa-file-contract me-2"></i>New Service Agreement</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body text-dark">
    <div class="row g-3">
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2" style="color:<?= $moduleColor ?>"><i class="fas fa-user me-2"></i>Client Information</h6></div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Client Name <span class="text-danger">*</span></label>
        <input type="text" name="client_name" id="agreeClientName" class="form-control" required placeholder="Full name">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Company</label>
        <input type="text" name="client_company" id="agreeClientCompany" class="form-control" placeholder="Company name">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Contact Email</label>
        <input type="email" name="client_email" id="agreeClientEmail" class="form-control" placeholder="client@company.com">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Phone</label>
        <input type="text" name="client_phone" id="agreeClientPhone" class="form-control" placeholder="+263...">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Service Level</label>
        <input type="text" name="service_level" id="agreeServiceLevel" class="form-control" placeholder="e.g. Premium, Standard, Express">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Status</label>
        <select name="status" id="agreeStatus" class="form-select">
          <option value="active">Active</option>
          <option value="draft">Draft</option>
          <option value="expired">Expired</option>
          <option value="terminated">Terminated</option>
        </select>
      </div>
      <div class="col-12"><h6 class="fw-bold border-bottom pb-2 mt-2" style="color:<?= $moduleColor ?>"><i class="fas fa-calendar me-2"></i>Contract Period & Terms</h6></div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Start Date</label>
        <input type="date" name="start_date" id="agreeStartDate" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">End Date</label>
        <input type="date" name="end_date" id="agreeEndDate" class="form-control">
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Delivery Timeframe</label>
        <input type="text" name="delivery_timeframe" id="agreeTimeframe" class="form-control" placeholder="e.g. Same-day within city, 2-3 days nationwide">
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Quality Standards & SLA</label>
        <textarea name="quality_standards" id="agreeQuality" class="form-control" rows="2" placeholder="e.g. 99% on-time delivery, damage rate below 0.5%, 24hr claims resolution..."></textarea>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Full Contract Details</label>
        <textarea name="contract_details" id="agreeContract" class="form-control" rows="6" placeholder="Full contract terms, clauses, obligations, fees, and conditions..."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Agreement</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delAgreementForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delAgreementId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('agreementModalTitle').innerHTML = '<i class="fas fa-file-contract me-2"></i>New Service Agreement';
  document.getElementById('agreementId').value = '0';
  ['agreeClientName','agreeClientCompany','agreeClientEmail','agreeClientPhone','agreeServiceLevel',
   'agreeTimeframe','agreeQuality','agreeContract'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('agreeStatus').value = 'active';
  document.getElementById('agreeStartDate').value = new Date().toISOString().slice(0,10);
  document.getElementById('agreeEndDate').value = '';
}
function openEdit(id) {
  fetch('agreements.php?fetch_agreement=' + id)
    .then(r => r.json())
    .then(d => {
      document.getElementById('agreementModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Agreement — ' + d.client_name;
      document.getElementById('agreementId').value = d.id;
      document.getElementById('agreeClientName').value = d.client_name || '';
      document.getElementById('agreeClientCompany').value = d.client_company || '';
      document.getElementById('agreeClientEmail').value = d.client_email || '';
      document.getElementById('agreeClientPhone').value = d.client_phone || '';
      document.getElementById('agreeServiceLevel').value = d.service_level || '';
      document.getElementById('agreeStatus').value = d.status || 'active';
      document.getElementById('agreeStartDate').value = d.start_date || '';
      document.getElementById('agreeEndDate').value = d.end_date || '';
      document.getElementById('agreeTimeframe').value = d.delivery_timeframe || '';
      document.getElementById('agreeQuality').value = d.quality_standards || '';
      document.getElementById('agreeContract').value = d.contract_details || '';
      new bootstrap.Modal(document.getElementById('agreementModal')).show();
    });
}
function delAgreement(id) {
  Swal.fire({
    title: 'Delete Agreement?', text: 'This service agreement will be permanently removed.',
    icon: 'warning', showCancelButton: true,
    confirmButtonColor: '#dc3545', confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delAgreementId').value = id;
      document.getElementById('delAgreementForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
