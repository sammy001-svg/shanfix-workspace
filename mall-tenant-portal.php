<?php
/**
 * Shopping Mall — Tenant Self-Service Portal
 * Accessible without staff login. Tenants authenticate with Lease No. + Last Name.
 */
session_start();
require_once __DIR__ . '/config/database.php';

$appName = defined('APP_NAME') ? APP_NAME : 'OrbitDesk';
$error   = '';
$flash   = '';

// Idempotent: ensure maintenance requests table exists
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mall_maintenance_requests (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        org_id        INT NOT NULL,
        tenant_id     INT NOT NULL,
        lease_id      INT DEFAULT NULL,
        shop_id       INT DEFAULT NULL,
        request_type  VARCHAR(100) DEFAULT 'general',
        description   TEXT NOT NULL,
        priority      ENUM('low','normal','high','urgent') DEFAULT 'normal',
        status        ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        submitted_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        resolved_at   DATETIME DEFAULT NULL,
        staff_notes   TEXT DEFAULT NULL,
        INDEX idx_org_tenant (org_id, tenant_id),
        INDEX idx_org_status  (org_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {}

// Session key for this portal
define('SESS_KEY', 'mall_tenant_portal');

// ── Logout ────────────────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'logout') {
    unset($_SESSION[SESS_KEY]);
    header('Location: mall-tenant-portal.php');
    exit;
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Login
    if ($action === 'login') {
        $leaseNo  = strtoupper(trim($_POST['lease_no'] ?? ''));
        $lastName = strtolower(trim($_POST['last_name'] ?? ''));

        if (!$leaseNo || !$lastName) {
            $error = 'Please enter both your Lease Number and Last Name.';
        } else {
            try {
                $st = $pdo->prepare("
                    SELECT l.id AS lease_id, l.tenant_id, l.shop_id, l.org_id,
                           l.lease_no, l.monthly_rent, l.start_date, l.end_date, l.status AS lease_status,
                           l.deposit_amount,
                           t.first_name, t.last_name, t.email, t.phone,
                           s.shop_no, s.floor, s.area_sqm, s.shop_type
                    FROM mall_leases l
                    JOIN mall_tenants t ON t.id = l.tenant_id
                    JOIN mall_shops s   ON s.id = l.shop_id
                    WHERE UPPER(l.lease_no) = ?
                      AND LOWER(t.last_name) = ?
                      AND l.status IN ('active','expired')
                    ORDER BY l.start_date DESC
                    LIMIT 1
                ");
                $st->execute([$leaseNo, $lastName]);
                $row = $st->fetch(PDO::FETCH_ASSOC);

                if ($row) {
                    $_SESSION[SESS_KEY] = $row;
                    header('Location: mall-tenant-portal.php');
                    exit;
                } else {
                    $error = 'No active lease found. Please check your Lease Number and Last Name.';
                }
            } catch (Throwable $e) {
                $error = 'Login service temporarily unavailable. Please try again.';
            }
        }
    }

    // Submit maintenance request
    if ($action === 'submit_maintenance' && isset($_SESSION[SESS_KEY])) {
        $sess    = $_SESSION[SESS_KEY];
        $type    = htmlspecialchars(trim($_POST['request_type'] ?? 'general'), ENT_QUOTES);
        $desc    = htmlspecialchars(trim($_POST['description'] ?? ''), ENT_QUOTES);
        $priority= in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';

        if (!$desc) {
            $flash = 'error:Please describe the maintenance issue.';
        } else {
            try {
                $pdo->prepare("
                    INSERT INTO mall_maintenance_requests
                        (org_id, tenant_id, lease_id, shop_id, request_type, description, priority)
                    VALUES (?,?,?,?,?,?,?)
                ")->execute([
                    $sess['org_id'], $sess['tenant_id'], $sess['lease_id'],
                    $sess['shop_id'], $type, $desc, $priority,
                ]);
                $flash = 'success:Your maintenance request has been submitted. Our team will be in touch.';
            } catch (Throwable $e) {
                $flash = 'error:Failed to submit request. Please try again.';
            }
        }
        header('Location: mall-tenant-portal.php');
        exit;
    }
}

// ── Authenticated: load dashboard data ────────────────────────────────────────
$sess = $_SESSION[SESS_KEY] ?? null;
$payments = $maintenance = $orgName = null;

if ($sess) {
    try {
        // Rent payment history (last 24 months)
        $stP = $pdo->prepare("
            SELECT rp.*, CONCAT(u.name) AS recorded_by
            FROM mall_rent_payments rp
            LEFT JOIN users u ON u.id = rp.created_by
            WHERE rp.org_id=? AND rp.lease_id=?
            ORDER BY rp.payment_date DESC
            LIMIT 24
        ");
        $stP->execute([$sess['org_id'], $sess['lease_id']]);
        $payments = $stP->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $payments = []; }

    try {
        // Maintenance requests for this tenant
        $stM = $pdo->prepare("
            SELECT * FROM mall_maintenance_requests
            WHERE org_id=? AND tenant_id=?
            ORDER BY submitted_at DESC
            LIMIT 20
        ");
        $stM->execute([$sess['org_id'], $sess['tenant_id']]);
        $maintenance = $stM->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $maintenance = []; }

    try {
        $stO = $pdo->prepare("SELECT name FROM organizations WHERE id=?");
        $stO->execute([$sess['org_id']]);
        $orgName = $stO->fetchColumn() ?: $appName;
    } catch (Throwable $e) { $orgName = $appName; }
}

// Flash message from redirect
if (empty($flash) && !empty($_SESSION['tp_flash'])) {
    $flash = $_SESSION['tp_flash'];
    unset($_SESSION['tp_flash']);
}
if (!empty($_SESSION['tp_flash_set'])) {
    $_SESSION['tp_flash'] = $_SESSION['tp_flash_set'];
    unset($_SESSION['tp_flash_set']);
}

[$flashType, $flashMsg] = $flash ? explode(':', $flash, 2) : ['', ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tenant Portal — <?= htmlspecialchars($appName, ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    body { background: #f4f6fb; font-family: 'Segoe UI', sans-serif; }
    .portal-hero {
      background: linear-gradient(135deg, #8e44ad 0%, #6c3483 100%);
      color: #fff;
      padding: 2.5rem 1rem 3.5rem;
    }
    .portal-hero h1 { font-size: 1.9rem; font-weight: 700; }
    .main-card {
      background: #fff;
      border-radius: 18px;
      box-shadow: 0 8px 32px rgba(0,0,0,.1);
      margin-top: -2rem;
    }
    .nav-tabs .nav-link { color: #6c3483; font-weight: 500; }
    .nav-tabs .nav-link.active { color: #6c3483; border-bottom: 3px solid #8e44ad; font-weight: 700; }
    .lease-badge { background: #f3e5f5; color: #6c3483; border-radius: 10px; padding: .4rem .9rem; font-size: .82rem; font-weight: 600; }
    .stat-pill { background: #fff; border-radius: 12px; padding: .7rem 1.2rem; box-shadow: 0 2px 12px rgba(0,0,0,.07); text-align: center; }
    .stat-pill .num { font-size: 1.5rem; font-weight: 700; color: #6c3483; }
    .stat-pill .lbl { font-size: .75rem; color: #888; }
    .timeline-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; margin-right: 6px; }
    footer { text-align: center; color: #bbb; font-size: .78rem; padding: 2rem 0; }
  </style>
</head>
<body>

<!-- Hero -->
<div class="portal-hero text-center">
  <div class="container">
    <i class="fas fa-store fa-2x mb-2 opacity-75"></i>
    <h1>Tenant Portal</h1>
    <p class="mb-0 opacity-75">
      <?php if ($sess): ?>
        Welcome, <strong><?= htmlspecialchars($sess['first_name'] . ' ' . $sess['last_name'], ENT_QUOTES) ?></strong>
        &mdash; <?= htmlspecialchars($orgName ?? $appName, ENT_QUOTES) ?>
      <?php else: ?>
        Manage your lease, payments, and maintenance requests
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="container" style="max-width:780px;padding-bottom:2rem">

<?php if ($flashMsg): ?>
<div class="alert alert-<?= $flashType === 'success' ? 'success' : 'danger' ?> alert-dismissible mt-3 mb-0 shadow-sm" role="alert">
  <i class="fas fa-<?= $flashType === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
  <?= htmlspecialchars($flashMsg, ENT_QUOTES) ?>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!$sess): ?>
<!-- ══════════ LOGIN FORM ══════════════════════════════════════════════════ -->
<div class="main-card p-4">
  <div class="text-center mb-4">
    <div class="mb-3"><i class="fas fa-key fa-2x" style="color:#8e44ad"></i></div>
    <h5 class="fw-bold">Sign In to Your Tenant Account</h5>
    <p class="text-muted small">Use your Lease Number and Last Name to access your account.</p>
  </div>

  <?php if ($error): ?>
  <div class="alert alert-danger py-2 small"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <form method="POST" action="mall-tenant-portal.php">
    <input type="hidden" name="action" value="login">
    <div class="mb-3">
      <label class="form-label fw-semibold">Lease Number <span class="text-danger">*</span></label>
      <input type="text" name="lease_no" class="form-control form-control-lg text-uppercase font-monospace"
             placeholder="e.g. LSE-2024-0001" required autofocus
             value="<?= htmlspecialchars(strtoupper($_POST['lease_no'] ?? ''), ENT_QUOTES) ?>">
      <div class="form-text">Found on your lease agreement or welcome letter.</div>
    </div>
    <div class="mb-4">
      <label class="form-label fw-semibold">Last Name <span class="text-danger">*</span></label>
      <input type="text" name="last_name" class="form-control form-control-lg"
             placeholder="Your registered last name" required
             value="<?= htmlspecialchars($_POST['last_name'] ?? '', ENT_QUOTES) ?>">
    </div>
    <button type="submit" class="btn btn-lg w-100 text-white fw-bold" style="background:#8e44ad">
      <i class="fas fa-sign-in-alt me-2"></i>Sign In
    </button>
  </form>

  <hr class="my-4">
  <p class="text-center text-muted small">
    <i class="fas fa-info-circle me-1"></i>
    Don't know your lease number? Contact your mall management office.
  </p>
</div>

<?php else: ?>
<!-- ══════════ DASHBOARD ═══════════════════════════════════════════════════ -->
<?php
  $leaseActive  = $sess['lease_status'] === 'active';
  $leaseExpired = $sess['lease_status'] === 'expired';
  $daysLeft     = $sess['end_date'] ? (int)((strtotime($sess['end_date']) - time()) / 86400) : 0;
  $totalPaid    = array_sum(array_column($payments ?? [], 'amount'));
  $openMaint    = count(array_filter($maintenance ?? [], fn($m) => $m['status'] === 'open'));
?>

<!-- Lease Banner -->
<div class="main-card p-4 mt-0 mb-3">
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
    <div>
      <div class="d-flex align-items-center gap-2 mb-1">
        <span class="lease-badge"><i class="fas fa-file-contract me-1"></i><?= htmlspecialchars($sess['lease_no'], ENT_QUOTES) ?></span>
        <?php if ($leaseActive): ?>
          <span class="badge bg-success">Active</span>
        <?php else: ?>
          <span class="badge bg-secondary">Expired</span>
        <?php endif; ?>
      </div>
      <h5 class="fw-bold mb-0">Shop <?= htmlspecialchars($sess['shop_no'], ENT_QUOTES) ?>
        <?php if ($sess['floor']): ?><small class="text-muted fw-normal"> — Floor <?= htmlspecialchars($sess['floor'], ENT_QUOTES) ?></small><?php endif; ?>
      </h5>
      <small class="text-muted">
        <?= htmlspecialchars($sess['shop_type'] ?? 'Shop', ENT_QUOTES) ?>
        <?php if ($sess['area_sqm']): ?> &middot; <?= $sess['area_sqm'] ?> m²<?php endif; ?>
      </small>
    </div>
    <div class="text-end">
      <div class="text-muted small">Monthly Rent</div>
      <div class="fw-bold fs-5" style="color:#8e44ad">KES <?= number_format((float)$sess['monthly_rent'], 2) ?></div>
      <?php if ($sess['deposit_amount']): ?>
      <div class="text-muted small">Deposit: KES <?= number_format((float)$sess['deposit_amount'], 2) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <hr class="my-3">
  <div class="row g-3">
    <div class="col-6 col-md-3">
      <div class="stat-pill">
        <div class="num"><?= $sess['start_date'] ? date('M Y', strtotime($sess['start_date'])) : '—' ?></div>
        <div class="lbl">Lease Start</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-pill">
        <div class="num"><?= $sess['end_date'] ? date('M Y', strtotime($sess['end_date'])) : '—' ?></div>
        <div class="lbl">Lease End</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-pill">
        <div class="num <?= $daysLeft < 60 && $leaseActive ? 'text-danger' : '' ?>"><?= $leaseActive ? max(0, $daysLeft) . 'd' : '—' ?></div>
        <div class="lbl">Days Remaining</div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="stat-pill">
        <div class="num text-success"><?= count($payments ?? []) ?></div>
        <div class="lbl">Payments Made</div>
      </div>
    </div>
  </div>

  <?php if ($leaseActive && $daysLeft <= 60 && $daysLeft > 0): ?>
  <div class="alert alert-warning py-2 mt-3 small mb-0">
    <i class="fas fa-exclamation-triangle me-2"></i>
    Your lease expires in <strong><?= $daysLeft ?> days</strong>. Please contact management to discuss renewal.
  </div>
  <?php endif; ?>
  <?php if ($leaseExpired): ?>
  <div class="alert alert-danger py-2 mt-3 small mb-0">
    <i class="fas fa-times-circle me-2"></i>
    Your lease has expired. Please contact management to renew or vacate.
  </div>
  <?php endif; ?>
</div>

<!-- Tabs -->
<div class="main-card">
  <ul class="nav nav-tabs px-3 pt-3" id="portalTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabPayments"><i class="fas fa-receipt me-1"></i>Payments</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabMaint">
      <i class="fas fa-tools me-1"></i>Maintenance
      <?php if ($openMaint > 0): ?><span class="badge bg-danger ms-1"><?= $openMaint ?></span><?php endif; ?>
    </a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabNew"><i class="fas fa-plus-circle me-1"></i>New Request</a></li>
  </ul>

  <div class="tab-content p-3 p-md-4">

    <!-- ── TAB: Payments ──────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="tabPayments">
      <h6 class="fw-bold mb-3"><i class="fas fa-receipt me-2" style="color:#8e44ad"></i>Rent Payment History</h6>
      <?php if (empty($payments)): ?>
      <div class="text-center text-muted py-4">
        <i class="fas fa-file-invoice fa-2x mb-2 d-block opacity-25"></i>No payment records found.
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle">
          <thead class="table-light">
            <tr><th>Date</th><th>Reference</th><th>Method</th><th class="text-end">Amount (KES)</th><th>Status</th></tr>
          </thead>
          <tbody>
          <?php foreach ($payments as $p):
            $statusColor = match($p['status'] ?? 'paid') {
                'paid'    => 'success',
                'partial' => 'warning',
                'pending' => 'secondary',
                default   => 'light',
            };
          ?>
          <tr>
            <td class="small"><?= $p['payment_date'] ? date('d M Y', strtotime($p['payment_date'])) : '—' ?></td>
            <td class="font-monospace small"><?= htmlspecialchars($p['receipt_no'] ?? $p['id'], ENT_QUOTES) ?></td>
            <td class="small"><?= htmlspecialchars(ucfirst(str_replace('_',' ', $p['payment_method'] ?? '—')), ENT_QUOTES) ?></td>
            <td class="text-end fw-semibold"><?= number_format((float)($p['amount'] ?? 0), 2) ?></td>
            <td><span class="badge bg-<?= $statusColor ?>"><?= htmlspecialchars(ucfirst($p['status'] ?? 'paid'), ENT_QUOTES) ?></span></td>
          </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot class="table-light">
            <tr>
              <td colspan="3" class="fw-bold text-end">Total Paid:</td>
              <td class="text-end fw-bold text-success">KES <?= number_format($totalPaid, 2) ?></td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── TAB: Maintenance Requests ─────────────────────────────── -->
    <div class="tab-pane fade" id="tabMaint">
      <h6 class="fw-bold mb-3"><i class="fas fa-tools me-2" style="color:#8e44ad"></i>My Maintenance Requests</h6>
      <?php if (empty($maintenance)): ?>
      <div class="text-center text-muted py-4">
        <i class="fas fa-tools fa-2x mb-2 d-block opacity-25"></i>
        No maintenance requests yet. Use the <strong>New Request</strong> tab to submit one.
      </div>
      <?php else: ?>
      <?php foreach ($maintenance as $m):
        $mColor = match($m['status']) {
            'open'        => ['badge' => 'warning text-dark', 'dot' => '#f39c12'],
            'in_progress' => ['badge' => 'primary',            'dot' => '#3498db'],
            'resolved'    => ['badge' => 'success',            'dot' => '#27ae60'],
            'closed'      => ['badge' => 'secondary',          'dot' => '#95a5a6'],
            default       => ['badge' => 'light text-dark',    'dot' => '#ccc'],
        };
        $priColor = match($m['priority']) {
            'urgent' => 'danger', 'high' => 'warning text-dark',
            'normal' => 'info text-dark', default => 'secondary',
        };
      ?>
      <div class="card border mb-2 shadow-sm" style="border-radius:12px">
        <div class="card-body py-2 px-3">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
            <div>
              <span class="timeline-dot" style="background:<?= $mColor['dot'] ?>"></span>
              <strong style="font-size:.9rem"><?= htmlspecialchars(ucwords(str_replace('_',' ',$m['request_type'])), ENT_QUOTES) ?></strong>
              <span class="badge bg-<?= $priColor ?> ms-1" style="font-size:.68rem"><?= ucfirst($m['priority']) ?></span>
            </div>
            <span class="badge bg-<?= $mColor['badge'] ?>"><?= ucfirst(str_replace('_',' ',$m['status'])) ?></span>
          </div>
          <p class="mb-1 mt-1 small text-dark"><?= htmlspecialchars($m['description'], ENT_QUOTES) ?></p>
          <div class="d-flex gap-3 flex-wrap">
            <small class="text-muted"><i class="fas fa-calendar me-1"></i><?= date('d M Y', strtotime($m['submitted_at'])) ?></small>
            <?php if ($m['resolved_at']): ?>
            <small class="text-success"><i class="fas fa-check me-1"></i>Resolved <?= date('d M Y', strtotime($m['resolved_at'])) ?></small>
            <?php endif; ?>
          </div>
          <?php if ($m['staff_notes']): ?>
          <div class="alert alert-light border py-1 px-2 mt-2 small mb-0">
            <i class="fas fa-comment-alt me-1 text-muted"></i><?= htmlspecialchars($m['staff_notes'], ENT_QUOTES) ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- ── TAB: New Maintenance Request ──────────────────────────── -->
    <div class="tab-pane fade" id="tabNew">
      <h6 class="fw-bold mb-3"><i class="fas fa-plus-circle me-2" style="color:#8e44ad"></i>Submit a Maintenance Request</h6>
      <form method="POST" action="mall-tenant-portal.php">
        <input type="hidden" name="action" value="submit_maintenance">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Issue Type <span class="text-danger">*</span></label>
            <select name="request_type" class="form-select">
              <option value="electrical">Electrical</option>
              <option value="plumbing">Plumbing</option>
              <option value="air_conditioning">Air Conditioning</option>
              <option value="structural">Structural / Civil</option>
              <option value="security">Security / Locks</option>
              <option value="cleaning">Cleaning / Hygiene</option>
              <option value="internet_cable">Internet / Cable</option>
              <option value="fire_safety">Fire Safety</option>
              <option value="general" selected>General</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <option value="low">Low — Not urgent</option>
              <option value="normal" selected>Normal — Within a week</option>
              <option value="high">High — Within 48 hrs</option>
              <option value="urgent">Urgent — Immediate attention needed</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
            <textarea name="description" class="form-control" rows="4" required
                      placeholder="Please describe the issue in detail — location, what's wrong, when it started…"></textarea>
          </div>
          <div class="col-12">
            <button type="submit" class="btn text-white fw-bold" style="background:#8e44ad">
              <i class="fas fa-paper-plane me-2"></i>Submit Request
            </button>
          </div>
        </div>
      </form>
    </div>

  </div><!-- /tab-content -->
</div><!-- /main-card -->

<!-- Logout -->
<div class="text-center mt-3">
  <a href="mall-tenant-portal.php?action=logout" class="btn btn-outline-secondary btn-sm">
    <i class="fas fa-sign-out-alt me-1"></i>Sign Out
  </a>
</div>

<?php endif; ?>

  <footer><?= htmlspecialchars($appName, ENT_QUOTES) ?> &copy; <?= date('Y') ?> &middot; Tenant Self-Service Portal</footer>
</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
