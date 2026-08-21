<?php
// ── TOUR: Traveller Portal Access ──────────────────────────────
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

    // ── Issue or reset a booking PIN ──────────────────────────
    if ($action === 'issue_pin') {
        $id  = (int)($_POST['id'] ?? 0);
        $pin = trim((string)($_POST['pin'] ?? ''));

        $stmt = $pdo->prepare("SELECT booking_no, customer_name FROM tour_bookings WHERE id=? AND org_id=? LIMIT 1");
        $stmt->execute([$id, $orgId]);
        $booking = $stmt->fetch();
        if (!$booking) {
            setFlash('danger', 'Booking not found.');
            redirect('portals.php');
        }

        if ($pin === '') {
            $pin = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } elseif (!ctype_digit($pin) || strlen($pin) < 4 || strlen($pin) > 8) {
            setFlash('danger', 'A traveller PIN must be 4 – 8 digits.');
            redirect('portals.php');
        }

        $pdo->prepare("UPDATE tour_bookings SET portal_pin=?, portal_enabled=1 WHERE id=? AND org_id=?")
            ->execute([password_hash($pin, PASSWORD_DEFAULT), $id, $orgId]);

        // Shown once, then it only exists as a hash
        $_SESSION['tour_issued_pin'] = ['booking_no' => $booking['booking_no'], 'name' => $booking['customer_name'], 'pin' => $pin];
        setFlash('success', 'Portal access issued for ' . $booking['booking_no'] . '.');
        logActivity('update', 'tour', "Issued traveller portal PIN for booking {$booking['booking_no']}");
        redirect('portals.php');
    }

    if ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE tour_bookings SET portal_enabled=0, portal_pin=NULL WHERE id=? AND org_id=?")
            ->execute([$id, $orgId]);
        setFlash('success', 'Portal access revoked.');
        logActivity('update', 'tour', "Revoked traveller portal access for booking #$id");
        redirect('portals.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$portalOn = tourConf($orgId, 't_portal_enabled') === '1';

// PIN handed back exactly once, straight after issuing
$issued = $_SESSION['tour_issued_pin'] ?? null;
unset($_SESSION['tour_issued_pin']);

$filter = sanitize($_GET['filter'] ?? 'upcoming');
$where  = 'b.org_id = ?';
$params = [$orgId];
if ($filter === 'upcoming') {
    $where .= " AND b.travel_date >= CURDATE() AND b.status <> 'cancelled'";
} elseif ($filter === 'enabled') {
    $where .= ' AND b.portal_enabled = 1';
} elseif ($filter === 'no_access') {
    $where .= " AND b.portal_enabled = 0 AND b.status <> 'cancelled'";
}

$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.id, b.booking_no, b.customer_name, b.customer_phone, b.customer_email,
               b.travel_date, b.status, b.total_amount, b.portal_enabled, b.portal_pin, b.portal_last_login,
               p.name AS package_name, d.departure_code
        FROM tour_bookings b
        LEFT JOIN tour_packages p   ON p.id = b.package_id
        LEFT JOIN tour_departures d ON d.id = b.departure_id
        WHERE $where
        ORDER BY b.travel_date DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {}

$statEnabled = $statUsed = 0;
foreach ($bookings as $b) {
    if ($b['portal_enabled']) $statEnabled++;
    if (!empty($b['portal_last_login'])) $statUsed++;
}

$org = [];
try {
    $stmt = $pdo->prepare("SELECT name, slug FROM organizations WHERE id=? LIMIT 1");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch() ?: [];
} catch (Throwable $e) {}
$portalUrl = APP_URL . '/traveller/login.php' . (!empty($org['slug']) ? '?org=' . rawurlencode($org['slug']) : '');
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-id-card me-2" style="color:<?= $moduleColor ?>"></i>Traveller Portal Access</h4>
    <p class="text-muted mb-0">Give clients a private sign-in to follow their itinerary, payments and balance</p>
  </div>
  <a href="settings.php" class="btn btn-outline-secondary"><i class="fas fa-cog me-1"></i>Portal Settings</a>
</div>

<?php if (!$portalOn): ?>
<div class="alert alert-warning d-flex align-items-center">
  <i class="fas fa-triangle-exclamation me-2"></i>
  <div>The traveller portal is currently switched off, so issued PINs will not work. Enable it in <a href="settings.php" class="alert-link">module settings</a>.</div>
</div>
<?php endif; ?>

<?php if ($issued): ?>
<div class="alert alert-success border-0 shadow-sm">
  <div class="d-flex align-items-start gap-3">
    <i class="fas fa-key fa-2x mt-1"></i>
    <div class="flex-grow-1">
      <div class="fw-bold mb-1">Portal PIN issued — copy it now</div>
      <div class="small mb-2">
        This PIN is stored hashed and cannot be shown again. Send it to
        <strong><?= e($issued['name']) ?></strong> along with booking reference <code><?= e($issued['booking_no']) ?></code>.
      </div>
      <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="px-3 py-2 bg-white border rounded fs-4 fw-bold letter-spacing" style="letter-spacing:4px"><?= e($issued['pin']) ?></div>
        <div class="small">
          <div class="text-muted">Sign-in link</div>
          <code><?= e($portalUrl) ?></code>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-suitcase-rolling"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($bookings) ?></div><div class="stat-label">Bookings In View</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-key"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statEnabled ?></div><div class="stat-label">Portal Access Issued</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-right-to-bracket"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statUsed ?></div><div class="stat-label">Travellers Signed In</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3 d-flex flex-wrap align-items-center gap-3">
    <div class="d-flex flex-wrap gap-2">
      <?php foreach (['upcoming' => 'Upcoming Travel', 'enabled' => 'Access Issued', 'no_access' => 'No Access Yet', 'all' => 'All Bookings'] as $key => $label): ?>
      <a href="portals.php?filter=<?= $key ?>" class="btn btn-sm <?= $filter === $key ? 'text-white' : 'btn-outline-secondary' ?>"
         style="<?= $filter === $key ? 'background:' . $moduleColor : '' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <div class="ms-auto d-flex align-items-center gap-2" style="min-width:320px">
      <span class="small text-muted text-nowrap">Portal link</span>
      <input type="text" class="form-control form-control-sm bg-light" id="portalUrl" value="<?= e($portalUrl) ?>" readonly>
      <button class="btn btn-sm btn-outline-secondary" onclick="copyPortalUrl()"><i class="fas fa-copy"></i></button>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="portalTable">
        <thead class="table-light">
          <tr>
            <th>Booking</th>
            <th>Traveller</th>
            <th>Trip</th>
            <th>Travel Date</th>
            <th>Portal Access</th>
            <th>Last Sign-in</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bookings)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-user-lock fa-3x mb-3 d-block"></i>No bookings match this view.</td></tr>
          <?php else: foreach ($bookings as $b): ?>
          <tr>
            <td>
              <code class="text-dark bg-light px-2 py-1 rounded"><?= e($b['booking_no']) ?></code>
              <div class="mt-1"><?= statusBadge($b['status']) ?></div>
            </td>
            <td>
              <div class="fw-semibold text-dark"><?= e($b['customer_name']) ?></div>
              <div class="small text-muted"><?= e($b['customer_phone'] ?: $b['customer_email'] ?: '—') ?></div>
            </td>
            <td class="small">
              <div><?= e($b['package_name'] ?: '—') ?></div>
              <?php if ($b['departure_code']): ?>
              <div class="text-muted"><i class="fas fa-plane-departure me-1"></i><?= e($b['departure_code']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= formatDate($b['travel_date']) ?></td>
            <td>
              <?php if ($b['portal_enabled'] && $b['portal_pin']): ?>
                <span class="badge bg-success"><i class="fas fa-key me-1"></i>Active</span>
              <?php elseif ($b['portal_enabled']): ?>
                <span class="badge bg-warning text-dark">Enabled, no PIN</span>
              <?php else: ?>
                <span class="badge bg-secondary">Not issued</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= $b['portal_last_login'] ? formatDateTime($b['portal_last_login']) : 'Never' ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary"
                      onclick="openPin(<?= (int)$b['id'] ?>, <?= e(json_encode($b['booking_no'])) ?>, <?= e(json_encode($b['customer_name'])) ?>, <?= $b['portal_enabled'] ? 'true' : 'false' ?>)">
                <i class="fas fa-key me-1"></i><?= $b['portal_enabled'] ? 'Reset PIN' : 'Issue PIN' ?>
              </button>
              <?php if ($b['portal_enabled']): ?>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Revoke portal access for this booking?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="revoke">
                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Revoke"><i class="fas fa-user-slash"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- PIN Modal -->
<div class="modal fade" id="pinModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="issue_pin">
        <input type="hidden" name="id" id="pinBookingId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-key me-2"></i>Traveller Portal PIN</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <p class="text-muted small mb-3">
            Issuing access for <strong id="pinCustomer"></strong> on booking <code id="pinBookingNo"></code>.
            The traveller signs in with that reference plus this PIN.
          </p>
          <div class="alert alert-warning small py-2" id="pinResetWarning" style="display:none">
            <i class="fas fa-triangle-exclamation me-1"></i>This booking already has a PIN. Saving replaces it — the old one stops working immediately.
          </div>
          <label class="form-label fw-semibold">PIN</label>
          <input type="text" name="pin" id="pinValue" class="form-control" inputmode="numeric" pattern="\d{4,8}" maxlength="8" placeholder="Leave blank to generate a 6-digit PIN">
          <div class="form-text">4 – 8 digits. It is stored hashed and shown to you only once.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-paper-plane me-1"></i>Issue Access</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
let pinModal;
$(document).ready(function(){
  $("#portalTable").DataTable({pageLength:15, order:[[3,"desc"]]});
  pinModal = new bootstrap.Modal(document.getElementById("pinModal"));
});

function openPin(id, bookingNo, customer, hasAccess) {
  $("#pinBookingId").val(id);
  $("#pinBookingNo").text(bookingNo);
  $("#pinCustomer").text(customer);
  $("#pinValue").val("");
  $("#pinResetWarning").toggle(!!hasAccess);
  pinModal.show();
}

function copyPortalUrl() {
  const el = document.getElementById("portalUrl");
  el.select();
  navigator.clipboard.writeText(el.value).then(function(){
    if (window.Swal) Swal.fire({icon:"success", title:"Link copied", timer:1400, showConfirmButton:false});
  });
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
