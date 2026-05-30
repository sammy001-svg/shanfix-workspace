<?php
// ── Accounting: Tax Rate Management ───────────────────────────
$moduleSlug  = 'accounting';
$moduleName  = 'Accounting & Bookkeeping';
$moduleIcon  = 'fas fa-calculator';
$moduleColor = '#1A8A4E';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',  'label' => 'Dashboard'],
    ['url' => 'accounts.php',     'icon' => 'fas fa-list',            'label' => 'Chart of Accounts'],
    ['url' => 'transactions.php', 'icon' => 'fas fa-exchange-alt',    'label' => 'Journal Entries'],
    ['url' => 'invoices.php',     'icon' => 'fas fa-file-invoice',    'label' => 'Invoices'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',         'label' => 'Expenses'],
    ['url' => 'bills.php',        'icon' => 'fas fa-file-import',     'label' => 'Vendor Bills'],
    ['url' => 'budgets.php',      'icon' => 'fas fa-bullseye',        'label' => 'Budgets'],
    ['url' => 'taxes.php',        'icon' => 'fas fa-percentage',      'label' => 'Tax Rates'],
    ['url' => 'assets.php',        'icon' => 'fas fa-building',        'label' => 'Fixed Assets'],
    ['url' => 'payroll-journal.php','icon'=> 'fas fa-file-alt',        'label' => 'Payroll Journal'],
    ['url' => 'audit.php',         'icon' => 'fas fa-history',         'label' => 'Audit Trail'],
    ['url' => 'reports.php',       'icon' => 'fas fa-chart-bar',       'label' => 'Reports'],
];

// ── Action Handling ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = sanitize($_POST['name'] ?? '');
        $rate       = (float)($_POST['rate'] ?? 0);
        $type       = in_array($_POST['type'] ?? '', ['exclusive','inclusive']) ? $_POST['type'] : 'exclusive';
        $isDefault  = isset($_POST['is_default']) ? 1 : 0;
        $status     = ($_POST['status'] ?? 'active') === 'active' ? 'active' : 'inactive';

        try {
            // Only one default per org
            if ($isDefault) {
                $pdo->prepare("UPDATE acc_taxes SET is_default=0 WHERE org_id=?")->execute([$orgId]);
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE acc_taxes SET name=?, rate=?, type=?, is_default=?, status=? WHERE id=? AND org_id=?")
                    ->execute([$name, $rate, $type, $isDefault, $status, $id, $orgId]);
                setFlash('success', 'Tax rate updated.');
                logActivity('update', 'accounting', "Updated tax rate: $name ($rate%)");
            } else {
                $pdo->prepare("INSERT INTO acc_taxes (org_id, name, rate, type, is_default, status) VALUES (?,?,?,?,?,?)")
                    ->execute([$orgId, $name, $rate, $type, $isDefault, $status]);
                setFlash('success', 'Tax rate created.');
                logActivity('create', 'accounting', "Created tax rate: $name ($rate%)");
            }
        } catch (Exception $e) {
            setFlash('danger', 'Failed to save tax rate.');
        }
        redirect('taxes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $pdo->prepare("DELETE FROM acc_taxes WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Tax rate deleted.');
            logActivity('delete', 'accounting', "Deleted tax rate #$id");
        } catch (Exception $e) {
            setFlash('danger', 'Failed to delete tax rate.');
        }
        redirect('taxes.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$taxes = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM acc_taxes WHERE org_id=? ORDER BY is_default DESC, name ASC");
    $stmt->execute([$orgId]);
    $taxes = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-percentage me-2" style="color:<?= $moduleColor ?>"></i>Tax Rates</h4>
    <p class="text-muted mb-0">Define VAT and other tax rates applied to invoices and bills</p>
  </div>
  <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#taxModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Add Tax Rate
  </button>
</div>

<?php if (empty($taxes)): ?>
<div class="alert alert-info">
  <i class="fas fa-info-circle me-2"></i>
  No tax rates defined yet. Add your first tax rate (e.g. VAT 16%) to use it when creating invoices.
</div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-percentage me-2 text-success"></i>Defined Tax Rates</h6>
    <span class="badge bg-secondary"><?= count($taxes) ?> rates</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Tax Name</th>
            <th class="text-center">Rate (%)</th>
            <th class="text-center">Type</th>
            <th class="text-center">Default</th>
            <th class="text-center">Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($taxes)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5">
            <i class="fas fa-percentage fa-2x mb-2 d-block"></i>No tax rates yet. Add VAT, GST, or custom rates.
          </td></tr>
          <?php else: foreach ($taxes as $tax): ?>
          <tr>
            <td class="ps-3 fw-semibold">
              <?= e($tax['name']) ?>
              <?php if ($tax['is_default']): ?>
              <span class="badge bg-success ms-1">Default</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge bg-primary fs-6"><?= number_format((float)$tax['rate'], 2) ?>%</span>
            </td>
            <td class="text-center">
              <span class="badge <?= $tax['type'] === 'exclusive' ? 'bg-warning text-dark' : 'bg-info text-dark' ?>">
                <?= ucfirst($tax['type']) ?>
              </span>
            </td>
            <td class="text-center">
              <?php if ($tax['is_default']): ?>
              <i class="fas fa-check-circle text-success fs-5"></i>
              <?php else: ?>
              <i class="fas fa-circle text-muted small"></i>
              <?php endif; ?>
            </td>
            <td class="text-center"><?= statusBadge($tax['status']) ?></td>
            <td class="text-center">
              <button class="btn btn-sm btn-outline-primary"
                onclick='openEditModal(<?= htmlspecialchars(json_encode($tax), ENT_QUOTES) ?>)'
                title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1"
                onclick="deleteTax(<?= $tax['id'] ?>, '<?= e($tax['name']) ?>')"
                title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="card mt-4 border-0 bg-light">
  <div class="card-body">
    <h6 class="fw-semibold mb-2"><i class="fas fa-info-circle me-2 text-primary"></i>Tax Type Explained</h6>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="d-flex gap-2">
          <span class="badge bg-warning text-dark mt-1">Exclusive</span>
          <p class="small text-muted mb-0">Tax is added on top of the price. E.g. item = KES 100, VAT 16% → total = KES 116. <strong>Most common in Kenya (VAT).</strong></p>
        </div>
      </div>
      <div class="col-md-6">
        <div class="d-flex gap-2">
          <span class="badge bg-info text-dark mt-1">Inclusive</span>
          <p class="small text-muted mb-0">Tax is already included in the stated price. E.g. item = KES 116 inclusive of VAT 16%, so tax portion = KES 16.</p>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="taxModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="taxId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="taxModalTitle"><i class="fas fa-plus me-2"></i>Add Tax Rate</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Tax Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="taxName" class="form-control" placeholder="e.g. VAT 16%, GST, Withholding Tax" required maxlength="100">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Rate (%) <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" name="rate" id="taxRate" class="form-control" step="0.01" min="0" max="100" placeholder="16.00" required>
                <span class="input-group-text">%</span>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tax Type</label>
              <select name="type" id="taxType" class="form-select">
                <option value="exclusive">Exclusive (added on top)</option>
                <option value="inclusive">Inclusive (already in price)</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="taxStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="is_default" id="taxDefault">
                <label class="form-check-label fw-semibold" for="taxDefault">
                  Set as default rate
                </label>
                <div class="small text-muted">Auto-applied to new invoices</div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Tax Rate</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Delete Form -->
<form method="POST" id="deleteTaxForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="deleteTaxId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAddModal() {
  document.getElementById('taxModalTitle').innerHTML = '<i class="fas fa-plus me-2"></i>Add Tax Rate';
  document.getElementById('taxId').value      = 0;
  document.getElementById('taxName').value    = '';
  document.getElementById('taxRate').value    = '';
  document.getElementById('taxType').value    = 'exclusive';
  document.getElementById('taxStatus').value  = 'active';
  document.getElementById('taxDefault').checked = false;
}

function openEditModal(tax) {
  document.getElementById('taxModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Tax Rate';
  document.getElementById('taxId').value      = tax.id;
  document.getElementById('taxName').value    = tax.name   || '';
  document.getElementById('taxRate').value    = tax.rate   || '';
  document.getElementById('taxType').value    = tax.type   || 'exclusive';
  document.getElementById('taxStatus').value  = tax.status || 'active';
  document.getElementById('taxDefault').checked = tax.is_default == 1;
  var modal = new bootstrap.Modal(document.getElementById('taxModal'));
  modal.show();
}

function deleteTax(id, name) {
  Swal.fire({
    title: 'Delete Tax Rate?',
    text: '"' + name + '" will be permanently removed.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(function(r) {
    if (r.isConfirmed) {
      document.getElementById('deleteTaxId').value = id;
      document.getElementById('deleteTaxForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
