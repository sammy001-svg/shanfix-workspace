<?php
/**
 * Subscription Expired / Suspended wall page.
 * Shown when an org's trial or subscription has ended.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// If they actually have an active/trial subscription, send them to dashboard
$sub = getOrgSubscription($orgId);
if ($sub && in_array($sub['status'], ['active', 'trial'])) {
    redirect(APP_URL . '/client/index.php');
}

$reason = sanitize($_GET['reason'] ?? 'expired');

$reasonMap = [
    'trial'     => ['icon' => 'fas fa-hourglass-end',  'color' => '#f59e0b', 'title' => 'Free Trial Ended',         'msg' => 'Your 14-day free trial has ended. Upgrade to a paid plan to continue using your workspace.'],
    'expired'   => ['icon' => 'fas fa-calendar-times', 'color' => '#ef4444', 'title' => 'Subscription Expired',      'msg' => 'Your subscription has expired. Renew now to restore access to all your modules and data.'],
    'cancelled' => ['icon' => 'fas fa-ban',            'color' => '#6b7280', 'title' => 'Subscription Cancelled',    'msg' => 'Your subscription was cancelled. Contact support or choose a new plan to reactivate your workspace.'],
    'suspended' => ['icon' => 'fas fa-pause-circle',   'color' => '#ef4444', 'title' => 'Account Suspended',         'msg' => 'Your account has been suspended. Please contact our support team to resolve this.'],
];
$info = $reasonMap[$reason] ?? $reasonMap['expired'];

$plans = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll();
$cfg   = getSettings(['support_email', 'company_website', 'mpesa_paybill', 'mpesa_account_ref']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Subscription Required — <?= APP_NAME ?></title>
<link rel="icon" type="image/svg+xml" href="<?= APP_URL ?>/assets/images/favicon.svg">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
  :root { --navy:#0B2D4E; --green:#1A8A4E; }
  body { background:#f0f4f8; font-family:'Segoe UI',sans-serif; }
  .lock-card { background:white; border-radius:16px; box-shadow:0 4px 24px rgba(0,0,0,.08); max-width:720px; margin:60px auto; overflow:hidden; }
  .lock-header { background:var(--navy); color:white; padding:28px 36px; display:flex; align-items:center; gap:16px; }
  .lock-header .brand { font-size:1.25rem; font-weight:700; }
  .lock-header .brand small { display:block; font-size:.75rem; font-weight:400; opacity:.7; }
  .lock-body { padding:40px 36px; }
  .lock-icon { width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.75rem; margin:0 auto 20px; }
  .plan-card { border:1.5px solid #e2e8f0; border-radius:12px; padding:20px; transition:.15s; cursor:pointer; }
  .plan-card:hover { border-color:var(--green); box-shadow:0 2px 12px rgba(26,138,78,.12); }
  .plan-card.popular { border-color:#3b82f6; }
  .price-big { font-size:2rem; font-weight:800; color:var(--green); }
  .btn-upgrade { background:var(--green); color:white; border:none; border-radius:8px; padding:12px 28px; font-weight:600; font-size:1rem; text-decoration:none; display:inline-block; transition:.15s; }
  .btn-upgrade:hover { background:#147a3f; color:white; }
  .check-list li { padding:4px 0; font-size:.9rem; }
  .check-list li i { color:var(--green); width:18px; }
</style>
</head>
<body>

<div class="lock-card">
  <!-- Header -->
  <div class="lock-header">
    <i class="fas fa-cubes fa-2x opacity-75"></i>
    <div>
      <div class="brand"><?= APP_NAME ?>
        <small>Workspace Platform</small>
      </div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-sm btn-light">
        <i class="fas fa-credit-card me-1"></i>Billing
      </a>
      <a href="<?= APP_URL ?>/auth/logout.php" class="btn btn-sm btn-outline-light">
        <i class="fas fa-sign-out-alt me-1"></i>Logout
      </a>
    </div>
  </div>

  <!-- Lock message -->
  <div class="lock-body">
    <div class="text-center mb-4">
      <div class="lock-icon mb-3" style="background:<?= $info['color'] ?>1a;color:<?= $info['color'] ?>">
        <i class="<?= $info['icon'] ?>"></i>
      </div>
      <h4 class="fw-800 mb-2" style="color:var(--navy)"><?= $info['title'] ?></h4>
      <p class="text-muted mb-0" style="max-width:480px;margin:auto"><?= $info['msg'] ?></p>
    </div>

    <?php if ($reason !== 'suspended'): ?>

    <!-- Plan cards -->
    <h6 class="fw-700 text-center mb-3" style="color:var(--navy)">Choose a Plan to Continue</h6>
    <div class="row g-3 mb-4">
      <?php foreach ($plans as $p):
        $isFeatured = (bool)$p['is_popular'];
      ?>
      <div class="col-md-4">
        <div class="plan-card <?= $isFeatured ? 'popular' : '' ?> text-center h-100">
          <?php if ($isFeatured): ?>
          <div class="badge bg-primary mb-2">Most Popular</div>
          <?php endif; ?>
          <div class="fw-700 mb-1" style="color:var(--navy)"><?= e($p['name']) ?></div>
          <div class="price-big"><?= formatCurrency((float)$p['price_monthly']) ?></div>
          <div class="text-muted small mb-3">/month</div>
          <ul class="list-unstyled check-list text-start mb-3">
            <li><i class="fas fa-check me-2"></i><?= $p['max_users'] ?> team members</li>
            <li><i class="fas fa-check me-2"></i><?= $p['max_modules'] ?> modules</li>
            <li><i class="fas fa-check me-2"></i>All core features</li>
            <?php if ($isFeatured): ?>
            <li><i class="fas fa-check me-2"></i>Priority support</li>
            <?php endif; ?>
          </ul>
          <form method="POST" action="<?= APP_URL ?>/client/billing.php">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="request_upgrade">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="billing_cycle" value="monthly">
            <button type="submit" class="btn btn-upgrade w-100" style="<?= $isFeatured ? '' : 'background:var(--navy)' ?>">
              <i class="fas fa-arrow-up me-1"></i>Choose <?= e($p['name']) ?>
            </button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php endif; ?>

    <!-- Support + billing links -->
    <div class="text-center border-top pt-4">
      <p class="text-muted small mb-2">Already paid? Your access will be restored once payment is confirmed.</p>
      <div class="d-flex gap-3 justify-content-center flex-wrap">
        <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-outline-primary btn-sm">
          <i class="fas fa-file-invoice-dollar me-1"></i>View Invoices &amp; Pay
        </a>
        <a href="<?= APP_URL ?>/client/support.php" class="btn btn-outline-secondary btn-sm">
          <i class="fas fa-headset me-1"></i>Contact Support
        </a>
        <?php if (!empty($cfg['support_email'])): ?>
        <a href="mailto:<?= e($cfg['support_email']) ?>" class="btn btn-outline-secondary btn-sm">
          <i class="fas fa-envelope me-1"></i><?= e($cfg['support_email']) ?>
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
