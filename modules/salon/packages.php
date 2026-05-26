<?php
// ── Salon: Service Packages ────────────────────────────────────
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_package') {
        $id          = (int)($_POST['id']          ?? 0);
        $name        = sanitize($_POST['name']       ?? '');
        $description = sanitize($_POST['description']?? '');
        $price       = (float)($_POST['price']       ?? 0);
        $sessions    = (int)($_POST['sessions']      ?? 1);
        $validDays   = (int)($_POST['valid_days']    ?? 90);
        $serviceIds  = $_POST['service_ids']          ?? [];
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($name) || $price <= 0) {
            setFlash('danger', 'Package name and price are required.');
            redirect('packages.php');
        }

        try {
            $pdo->beginTransaction();
            if ($id > 0) {
                $pdo->prepare("UPDATE salon_packages SET name=?, description=?, price=?, sessions=?, valid_days=?, is_active=? WHERE id=? AND org_id=?")
                    ->execute([$name, $description, $price, $sessions, $validDays, $isActive, $id, $orgId]);
                $pdo->prepare("DELETE FROM salon_package_services WHERE package_id=?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO salon_packages (org_id, name, description, price, sessions, valid_days, is_active) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$orgId, $name, $description, $price, $sessions, $validDays, $isActive]);
                $id = $pdo->lastInsertId();
            }
            $stmtSvc = $pdo->prepare("INSERT INTO salon_package_services (package_id, service_id) VALUES (?,?)");
            foreach ($serviceIds as $sid) {
                if ((int)$sid > 0) $stmtSvc->execute([$id, (int)$sid]);
            }
            $pdo->commit();
            setFlash('success', "Package '{$name}' saved.");
            logActivity('create', 'salon', "Saved package: {$name}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('packages.php');
    }

    if ($action === 'sell_package') {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $clientId  = (int)($_POST['client_id']  ?? 0);
        $purchDate = $_POST['purchase_date'] ?? date('Y-m-d');

        try {
            $stmt = $pdo->prepare("SELECT sessions, valid_days, price FROM salon_packages WHERE id=? AND org_id=? AND is_active=1");
            $stmt->execute([$packageId, $orgId]);
            $pkg = $stmt->fetch();
            if (!$pkg) throw new Exception("Package not found or inactive.");

            $expiryDate = date('Y-m-d', strtotime($purchDate . ' +' . (int)$pkg['valid_days'] . ' days'));

            $pdo->prepare("
                INSERT INTO salon_client_packages (org_id, client_id, package_id, purchase_date, expiry_date, sessions_remaining, amount_paid, status)
                VALUES (?,?,?,?,?,?,?,'active')
            ")->execute([$orgId, $clientId, $packageId, $purchDate, $expiryDate, (int)$pkg['sessions'], (float)$pkg['price']]);
            setFlash('success', 'Package sold to client. Sessions: ' . $pkg['sessions'] . ', Expires: ' . $expiryDate);
        } catch (Exception $e) {
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('packages.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$services = $packages = $clients = $clientPkgs = [];
try {
    $stmt = $pdo->prepare("SELECT id, service_name, price FROM salon_services WHERE org_id=? ORDER BY service_name");
    $stmt->execute([$orgId]); $services = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT p.*, COUNT(ps.service_id) AS svc_count FROM salon_packages p LEFT JOIN salon_package_services ps ON ps.package_id=p.id WHERE p.org_id=? GROUP BY p.id ORDER BY p.name");
    $stmt->execute([$orgId]); $packages = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM salon_clients WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]); $clients = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT cp.*, c.first_name, c.last_name, p.name AS package_name
        FROM salon_client_packages cp
        JOIN salon_clients c ON c.id = cp.client_id
        JOIN salon_packages p ON p.id = cp.package_id
        WHERE cp.org_id=? ORDER BY cp.purchase_date DESC
    ");
    $stmt->execute([$orgId]); $clientPkgs = $stmt->fetchAll();
} catch (Exception $e) {}

$activePkgs = count(array_filter($packages, fn($p) => $p['is_active']));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-gift me-2" style="color:<?= $moduleColor ?>"></i>Service Packages</h4>
    <p class="text-muted mb-0">Create bundles of services, sell to clients, track sessions</p>
  </div>
  <div class="btn-group">
    <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#pkgModal" onclick="resetPkgForm()">
      <i class="fas fa-plus-circle me-1"></i>New Package
    </button>
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#sellModal">
      <i class="fas fa-shopping-cart me-1"></i>Sell Package
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-gift"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($packages) ?></div><div class="stat-label">Total Packages</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-toggle-on"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activePkgs ?></div><div class="stat-label">Active Packages</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-id-card"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($clientPkgs) ?></div><div class="stat-label">Client Subscriptions</div></div>
    </div>
  </div>
</div>

<!-- Package Cards -->
<?php if (!empty($packages)): ?>
<div class="row g-3 mb-4">
  <?php foreach ($packages as $p): ?>
  <div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <h6 class="fw-bold mb-0"><?= e($p['name']) ?></h6>
          <span class="badge <?= $p['is_active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span>
        </div>
        <p class="text-muted small mb-2"><?= e($p['description'] ?: 'No description') ?></p>
        <div class="d-flex justify-content-between small text-muted mb-3">
          <span><i class="fas fa-concierge-bell me-1"></i><?= (int)$p['svc_count'] ?> service(s)</span>
          <span><i class="fas fa-ticket-alt me-1"></i><?= (int)$p['sessions'] ?> sessions</span>
          <span><i class="fas fa-calendar me-1"></i><?= (int)$p['valid_days'] ?> days</span>
        </div>
        <div class="d-flex align-items-center justify-content-between">
          <span class="fs-5 fw-bold" style="color:<?= $moduleColor ?>"><?= formatCurrency((float)$p['price']) ?></span>
          <button class="btn btn-sm btn-outline-primary" onclick='openPkgEdit(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i></button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Client Packages Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-id-card me-2" style="color:<?= $moduleColor ?>"></i>Client Package Subscriptions</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="clientPkgTable">
        <thead class="table-light"><tr><th>Client</th><th>Package</th><th>Purchased</th><th>Expires</th><th class="text-center">Sessions Left</th><th class="text-end">Paid</th><th class="text-center">Status</th></tr></thead>
        <tbody>
          <?php if (empty($clientPkgs)): ?>
          <tr><td colspan="7" class="text-center py-4 text-muted">No package subscriptions.</td></tr>
          <?php else: foreach ($clientPkgs as $cp):
            $expired = $cp['expiry_date'] < date('Y-m-d') && $cp['status'] !== 'expired';
          ?>
          <tr>
            <td class="fw-semibold"><?= e($cp['first_name'] . ' ' . $cp['last_name']) ?></td>
            <td><?= e($cp['package_name']) ?></td>
            <td><?= formatDate($cp['purchase_date']) ?></td>
            <td class="<?= $expired ? 'text-danger fw-semibold' : '' ?>"><?= formatDate($cp['expiry_date']) ?></td>
            <td class="text-center fw-bold"><?= (int)$cp['sessions_remaining'] ?></td>
            <td class="text-end"><?= formatCurrency((float)$cp['amount_paid']) ?></td>
            <td class="text-center">
              <?php
              $st = $expired ? 'expired' : $cp['status'];
              $badge = match($st) { 'active' => 'bg-success', 'expired' => 'bg-danger', 'completed' => 'bg-secondary', default => 'bg-light text-dark' };
              ?>
              <span class="badge <?= $badge ?>"><?= ucfirst($st) ?></span>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Package Modal -->
<div class="modal fade" id="pkgModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_package">
        <input type="hidden" name="id" id="pkgId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="pkgModalTitle"><i class="fas fa-gift me-2"></i>New Package</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Package Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="pkgName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Price (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="price" id="pkgPrice" class="form-control" required min="0.01">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Sessions Included</label>
              <input type="number" name="sessions" id="pkgSessions" class="form-control" min="1" value="5">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Valid (Days)</label>
              <input type="number" name="valid_days" id="pkgDays" class="form-control" min="1" value="90">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" id="pkgDesc" class="form-control" placeholder="Short description">
            </div>
            <div class="col-md-3 d-flex align-items-end">
              <div class="form-check form-switch">
                <input type="checkbox" name="is_active" id="pkgActive" class="form-check-input" checked>
                <label class="form-check-label fw-semibold" for="pkgActive">Active</label>
              </div>
            </div>
          </div>
          <label class="form-label fw-semibold">Included Services</label>
          <div class="row g-2">
            <?php foreach ($services as $svc): ?>
            <div class="col-md-4">
              <div class="form-check">
                <input type="checkbox" name="service_ids[]" value="<?= $svc['id'] ?>" class="form-check-input svc-check" id="svc<?= $svc['id'] ?>">
                <label class="form-check-label small" for="svc<?= $svc['id'] ?>"><?= e($svc['service_name']) ?></label>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Package</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Sell Package Modal -->
<div class="modal fade" id="sellModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="sell_package">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-shopping-cart me-2"></i>Sell Package to Client</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
            <select name="client_id" class="form-select" required>
              <option value="">-- Select Client --</option>
              <?php foreach ($clients as $c): ?>
              <option value="<?= $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Package <span class="text-danger">*</span></label>
            <select name="package_id" class="form-select" required>
              <option value="">-- Select Package --</option>
              <?php foreach (array_filter($packages, fn($p) => $p['is_active']) as $p): ?>
              <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — <?= formatCurrency((float)$p['price']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Purchase Date</label>
            <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-check me-1"></i>Confirm Sale</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#clientPkgTable").DataTable({pageLength:15, order:[[2,"desc"]]});
});
function resetPkgForm() {
    $("#pkgId").val(0);
    $("#pkgModalTitle").html('<i class="fas fa-gift me-2"></i>New Package');
    document.querySelector("#pkgModal form").reset();
    $(".svc-check").prop("checked", false);
    $("#pkgActive").prop("checked", true);
}
function openPkgEdit(p) {
    $("#pkgId").val(p.id);
    $("#pkgModalTitle").html('<i class="fas fa-edit me-2"></i>Edit Package');
    $("#pkgName").val(p.name);
    $("#pkgPrice").val(p.price);
    $("#pkgSessions").val(p.sessions);
    $("#pkgDays").val(p.valid_days);
    $("#pkgDesc").val(p.description);
    $("#pkgActive").prop("checked", p.is_active == 1);
    // Service checkboxes would need AJAX; for now just open modal
    $("#pkgModal").modal("show");
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
