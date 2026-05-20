<?php
$pageTitle = 'My Dashboard';
require_once __DIR__ . '/../includes/header-client.php';

$orgId = (int)$user['org_id'];

// Subscription info
$sub = getOrgSubscription($orgId);
$activeModules = getOrgModules($orgId);

// Counts
$userCount = countRows('users', 'org_id = ? AND role != ?', [$orgId, 'super_admin']);

// Trial days left
$trialDaysLeft = null;
if ($sub && $sub['status'] === 'trial' && $sub['trial_ends_at']) {
    $trialDaysLeft = max(0, ceil((strtotime($sub['trial_ends_at']) - time()) / 86400));
}
?>

<?php if ($trialDaysLeft !== null): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
  <i class="fas fa-clock fa-lg"></i>
  <div>
    <strong>Trial Period:</strong> <?= $trialDaysLeft ?> day<?= $trialDaysLeft != 1 ? 's' : '' ?> remaining.
    <a href="<?= APP_URL ?>/client/billing.php" class="fw-600">Upgrade now</a> to keep access.
  </div>
</div>
<?php endif; ?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-home me-2 text-green"></i>Welcome, <?= e($user['name']) ?></h4>
    <p class="text-muted mb-0"><?= e($user['org_name']) ?> — <?= formatDate(date('Y-m-d')) ?></p>
  </div>
  <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-primary">
    <i class="fas fa-plus me-2"></i>Add Module
  </a>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-puzzle-piece"></i></div>
      <div>
        <div class="stat-value"><?= count($activeModules) ?></div>
        <div class="stat-label">Active Modules</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div>
        <div class="stat-value"><?= $userCount ?></div>
        <div class="stat-label">Team Members</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-layer-group"></i></div>
      <div>
        <div class="stat-value"><?= e($sub['plan_name'] ?? 'Trial') ?></div>
        <div class="stat-label">Current Plan</div>
      </div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= ($sub && $sub['status']==='active') ? 'green' : 'warning' ?>">
      <div class="stat-icon <?= ($sub && $sub['status']==='active') ? 'green' : 'warning' ?>-bg"><i class="fas fa-certificate"></i></div>
      <div>
        <div class="stat-value"><?= ucfirst($sub['status'] ?? 'trial') ?></div>
        <div class="stat-label">Subscription</div>
      </div>
    </div>
  </div>
</div>

<!-- Active Modules Grid -->
<div class="card mb-4">
  <div class="card-header">
    <i class="fas fa-th text-green me-2"></i>Your Active Modules
    <a href="<?= APP_URL ?>/client/modules.php" class="ms-auto btn btn-sm btn-outline-primary">Manage Modules</a>
  </div>
  <div class="card-body">
    <?php if (empty($activeModules)): ?>
    <div class="text-center py-5">
      <i class="fas fa-puzzle-piece fa-3x text-muted mb-3"></i>
      <h5 class="text-muted">No modules selected yet</h5>
      <p class="text-muted">Choose the modules your business needs to get started.</p>
      <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-primary">Browse Modules</a>
    </div>
    <?php else: ?>
    <div class="row g-3">
      <?php foreach($activeModules as $m): ?>
      <div class="col-6 col-md-4 col-lg-3">
        <a href="<?= APP_URL ?>/modules/<?= e($m['slug']) ?>/index.php" class="text-decoration-none">
          <div class="module-card subscribed">
            <div class="module-icon" style="background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>">
              <i class="<?= e($m['icon']) ?>"></i>
            </div>
            <h6><?= e($m['name']) ?></h6>
            <p><?= e($m['category']) ?></p>
          </div>
        </a>
      </div>
      <?php endforeach; ?>
      <!-- Add more card -->
      <div class="col-6 col-md-4 col-lg-3">
        <a href="<?= APP_URL ?>/client/modules.php" class="text-decoration-none">
          <div class="module-card" style="border:2px dashed var(--gray-200)">
            <div class="module-icon" style="background:var(--gray-100);color:var(--gray-400)">
              <i class="fas fa-plus"></i>
            </div>
            <h6 class="text-muted">Add Module</h6>
            <p>Expand your workspace</p>
          </div>
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Bottom row -->
<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-file-invoice text-green me-2"></i>Billing Summary</div>
      <div class="card-body">
        <?php if ($sub): ?>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Plan</span>
          <strong><?= e($sub['plan_name'] ?? 'Custom') ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Status</span>
          <?= statusBadge($sub['status']) ?>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Billing Cycle</span>
          <strong class="text-capitalize"><?= $sub['billing_cycle'] ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Amount</span>
          <strong class="text-green"><?= formatCurrency((float)$sub['amount']) ?>/mo</strong>
        </div>
        <?php if ($sub['trial_ends_at']): ?>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Trial Ends</span>
          <strong class="text-warning"><?= formatDate($sub['trial_ends_at']) ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($sub['ends_at']): ?>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Next Renewal</span>
          <strong><?= formatDate($sub['ends_at']) ?></strong>
        </div>
        <?php endif; ?>
        <hr>
        <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-primary btn-sm w-100">
          <i class="fas fa-credit-card me-2"></i>Manage Billing
        </a>
        <?php else: ?>
        <div class="text-center py-3 text-muted">No subscription found.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-info-circle text-green me-2"></i>Account Information</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Organization</span>
          <strong><?= e($user['org_name']) ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Your Name</span>
          <strong><?= e($user['name']) ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Email</span>
          <strong><?= e($user['email']) ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Role</span>
          <span class="badge bg-navy"><?= ucfirst(str_replace('_',' ',$user['role'])) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-2">
          <span class="text-muted">Team Members</span>
          <strong><?= $userCount ?></strong>
        </div>
        <hr>
        <a href="<?= APP_URL ?>/client/profile.php" class="btn btn-outline-primary btn-sm w-100">
          <i class="fas fa-user-edit me-2"></i>Edit Profile
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
