<?php
// ── TOUR: Supplier Directory (hotels, airlines, transport, activities) ──
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    $types = ['hotel','airline','transport','activity','restaurant','insurance','visa','other'];

    if ($action === 'save') {
        $id             = (int)($_POST['id'] ?? 0);
        $name           = sanitize($_POST['name']           ?? '');
        $supplierType   = sanitize($_POST['supplier_type']  ?? 'other');
        $contactPerson  = sanitize($_POST['contact_person'] ?? '');
        $phone          = sanitize($_POST['phone']          ?? '');
        $email          = sanitize($_POST['email']          ?? '');
        $city           = sanitize($_POST['city']           ?? '');
        $country        = sanitize($_POST['country']        ?? '');
        $address        = sanitize($_POST['address']        ?? '');
        $paymentTerms   = sanitize($_POST['payment_terms']  ?? '');
        $accountDetails = sanitize($_POST['account_details']?? '');
        $rating         = max(0, min(5, (int)($_POST['rating'] ?? 0)));
        $notes          = sanitize($_POST['notes']          ?? '');
        $status         = sanitize($_POST['status']         ?? 'active');

        if ($name === '') {
            setFlash('danger', 'Supplier name is required.');
            redirect('suppliers.php');
        }
        if (!in_array($supplierType, $types, true)) $supplierType = 'other';

        if ($id === 0) {
            $stmt = $pdo->prepare("
                INSERT INTO tour_suppliers
                    (org_id, name, supplier_type, contact_person, phone, email, city, country,
                     address, payment_terms, account_details, rating, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $name, $supplierType, $contactPerson, $phone, $email, $city, $country,
                            $address, $paymentTerms, $accountDetails, $rating, $notes, $status]);
            setFlash('success', 'Supplier "' . $name . '" added to the directory.');
            logActivity('create', 'tour', "Added supplier '$name' ($supplierType)");
        } else {
            $stmt = $pdo->prepare("
                UPDATE tour_suppliers
                SET name=?, supplier_type=?, contact_person=?, phone=?, email=?, city=?, country=?,
                    address=?, payment_terms=?, account_details=?, rating=?, notes=?, status=?
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$name, $supplierType, $contactPerson, $phone, $email, $city, $country,
                            $address, $paymentTerms, $accountDetails, $rating, $notes, $status, $id, $orgId]);
            setFlash('success', 'Supplier updated.');
            logActivity('update', 'tour', "Updated supplier '$name' (#$id)");
        }
        redirect('suppliers.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $linked = countRows('tour_supplier_bookings', 'supplier_id = ? AND org_id = ?', [$id, $orgId]);
        if ($linked > 0) {
            setFlash('danger', 'Cannot delete this supplier — ' . $linked . ' service booking(s) reference it. Mark it inactive instead.');
        } else {
            $pdo->prepare("DELETE FROM tour_suppliers WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Supplier removed from the directory.');
            logActivity('delete', 'tour', "Deleted supplier #$id");
        }
        redirect('suppliers.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$types = ['hotel','airline','transport','activity','restaurant','insurance','visa','other'];
$typeIcons = [
    'hotel'      => 'fa-hotel',      'airline'   => 'fa-plane',
    'transport'  => 'fa-van-shuttle','activity'  => 'fa-person-hiking',
    'restaurant' => 'fa-utensils',   'insurance' => 'fa-shield-heart',
    'visa'       => 'fa-passport',   'other'     => 'fa-handshake',
];

$filterType = sanitize($_GET['type'] ?? '');
$where  = 's.org_id = ?';
$params = [$orgId];
if ($filterType !== '' && in_array($filterType, $types, true)) {
    $where .= ' AND s.supplier_type = ?';
    $params[] = $filterType;
}

// Suppliers with their committed spend and outstanding balance
$suppliers = [];
try {
    $stmt = $pdo->prepare("
        SELECT s.*,
               COALESCE(sb.jobs, 0)      AS jobs,
               COALESCE(sb.committed, 0) AS committed,
               COALESCE(sb.settled, 0)   AS settled
        FROM tour_suppliers s
        LEFT JOIN (
            SELECT supplier_id,
                   COUNT(*)          AS jobs,
                   SUM(cost)         AS committed,
                   SUM(amount_paid)  AS settled
            FROM tour_supplier_bookings
            WHERE org_id = ? AND status <> 'cancelled'
            GROUP BY supplier_id
        ) sb ON sb.supplier_id = s.id
        WHERE $where
        ORDER BY s.name
    ");
    $stmt->execute(array_merge([$orgId], $params));
    $suppliers = $stmt->fetchAll();
} catch (Throwable $e) {}

$totalSuppliers = count($suppliers);
$totalCommitted = 0.0;
$totalOwed      = 0.0;
$activeCount    = 0;
foreach ($suppliers as $s) {
    $totalCommitted += (float)$s['committed'];
    $totalOwed      += max(0, (float)$s['committed'] - (float)$s['settled']);
    if ($s['status'] === 'active') $activeCount++;
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-handshake me-2" style="color:<?= $moduleColor ?>"></i>Supplier Directory</h4>
    <p class="text-muted mb-0">Hotels, airlines, transport partners and activity providers you buy from</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openSupplier()">
    <i class="fas fa-plus me-1"></i>Add Supplier
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-address-book"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSuppliers ?></div><div class="stat-label">Suppliers Listed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-circle-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $activeCount ?></div><div class="stat-label">Active Partners</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-contract"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalCommitted) ?></div><div class="stat-label">Total Committed</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalOwed) ?></div><div class="stat-label">Outstanding To Pay</div></div>
    </div>
  </div>
</div>

<!-- Type filter chips -->
<div class="mb-3 d-flex flex-wrap gap-2">
  <a href="suppliers.php" class="btn btn-sm <?= $filterType === '' ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterType === '' ? 'background:' . $moduleColor : '' ?>">All Types</a>
  <?php foreach ($types as $t): ?>
  <a href="suppliers.php?type=<?= $t ?>" class="btn btn-sm <?= $filterType === $t ? 'text-white' : 'btn-outline-secondary' ?>"
     style="<?= $filterType === $t ? 'background:' . $moduleColor : '' ?>">
    <i class="fas <?= $typeIcons[$t] ?> me-1"></i><?= tourLabel($t) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="suppliersTable">
        <thead class="table-light">
          <tr>
            <th>Supplier</th>
            <th>Type</th>
            <th>Contact</th>
            <th>Location</th>
            <th class="text-center">Jobs</th>
            <th class="text-end">Committed</th>
            <th class="text-end">Owed</th>
            <th>Rating</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suppliers)): ?>
          <tr>
            <td colspan="10" class="text-center py-5 text-muted">
              <i class="fas fa-handshake-slash fa-3x mb-3 d-block"></i>No suppliers registered yet.
            </td>
          </tr>
          <?php else: foreach ($suppliers as $s):
              $owed = max(0, (float)$s['committed'] - (float)$s['settled']);
          ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($s['name']) ?></div>
              <?php if (!empty($s['payment_terms'])): ?>
              <div class="small text-muted"><i class="fas fa-file-signature me-1"></i><?= e($s['payment_terms']) ?></div>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-light text-dark border">
                <i class="fas <?= $typeIcons[$s['supplier_type']] ?? 'fa-handshake' ?> me-1"></i><?= tourLabel($s['supplier_type']) ?>
              </span>
            </td>
            <td class="small">
              <div><?= e($s['contact_person'] ?: '—') ?></div>
              <div class="text-muted"><i class="fas fa-phone me-1"></i><?= e($s['phone'] ?: '—') ?></div>
              <div class="text-muted"><i class="fas fa-envelope me-1"></i><?= e($s['email'] ?: '—') ?></div>
            </td>
            <td class="small"><?= e(implode(', ', array_filter([$s['city'], $s['country']])) ?: '—') ?></td>
            <td class="text-center"><?= (int)$s['jobs'] ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$s['committed']) ?></td>
            <td class="text-end fw-bold <?= $owed > 0 ? 'text-danger' : 'text-success' ?>"><?= formatCurrency($owed) ?></td>
            <td style="white-space:nowrap">
              <?php for ($i = 1; $i <= 5; $i++): ?>
              <i class="fa<?= $i <= (int)$s['rating'] ? 's' : 'r' ?> fa-star" style="font-size:.7rem;color:<?= $i <= (int)$s['rating'] ? '#f1c40f' : '#ced4da' ?>"></i>
              <?php endfor; ?>
            </td>
            <td><?= statusBadge($s['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <a href="expenses.php?supplier_id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Service bookings">
                <i class="fas fa-list-check"></i>
              </a>
              <button class="btn btn-sm btn-outline-secondary ms-1" onclick="editSupplier(<?= e(json_encode($s)) ?>)" title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this supplier permanently?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Supplier Modal -->
<div class="modal fade" id="supplierModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="supId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="supTitle"><i class="fas fa-plus me-2"></i>Add Supplier</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Supplier Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="supName" class="form-control" required placeholder="e.g. Serena Mountain Lodge">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Type</label>
              <select name="supplier_type" id="supType" class="form-select">
                <?php foreach ($types as $t): ?>
                <option value="<?= $t ?>"><?= tourLabel($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="supStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="blacklisted">Blacklisted</option>
              </select>
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Contact Person</label>
              <input type="text" name="contact_person" id="supContact" class="form-control" placeholder="e.g. Grace Wanjiru">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Phone</label>
              <input type="text" name="phone" id="supPhone" class="form-control" placeholder="e.g. +254 712 345678">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Email</label>
              <input type="email" name="email" id="supEmail" class="form-control" placeholder="e.g. reservations@lodge.com">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">City</label>
              <input type="text" name="city" id="supCity" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Country</label>
              <input type="text" name="country" id="supCountry" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Service Rating</label>
              <select name="rating" id="supRating" class="form-select">
                <option value="0">Not rated</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <option value="<?= $i ?>"><?= str_repeat('★', $i) ?> (<?= $i ?>/5)</option>
                <?php endfor; ?>
              </select>
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Address</label>
              <input type="text" name="address" id="supAddress" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Payment Terms</label>
              <input type="text" name="payment_terms" id="supTerms" class="form-control" placeholder="e.g. 30% deposit, balance on checkout">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Bank / Payment Details</label>
              <input type="text" name="account_details" id="supAccount" class="form-control" placeholder="e.g. KCB 1234567890 — Lodge Ltd">
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="supNotes" class="form-control" rows="2" placeholder="Contract references, rate agreements, blackout dates…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Supplier</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
let supModal;
$(document).ready(function(){
  $("#suppliersTable").DataTable({pageLength:15, order:[[0,"asc"]]});
  supModal = new bootstrap.Modal(document.getElementById("supplierModal"));
});

function openSupplier() {
  $("#supTitle").html("<i class=\"fas fa-plus me-2\"></i>Add Supplier");
  $("#supId,#supName,#supContact,#supPhone,#supEmail,#supCity,#supCountry,#supAddress,#supTerms,#supAccount,#supNotes").val("");
  $("#supType").val("other");
  $("#supStatus").val("active");
  $("#supRating").val("0");
  supModal.show();
}

function editSupplier(s) {
  $("#supTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Supplier");
  $("#supId").val(s.id);
  $("#supName").val(s.name || "");
  $("#supType").val(s.supplier_type || "other");
  $("#supStatus").val(s.status || "active");
  $("#supContact").val(s.contact_person || "");
  $("#supPhone").val(s.phone || "");
  $("#supEmail").val(s.email || "");
  $("#supCity").val(s.city || "");
  $("#supCountry").val(s.country || "");
  $("#supAddress").val(s.address || "");
  $("#supTerms").val(s.payment_terms || "");
  $("#supAccount").val(s.account_details || "");
  $("#supRating").val(s.rating || "0");
  $("#supNotes").val(s.notes || "");
  supModal.show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
