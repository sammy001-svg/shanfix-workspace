<?php
$pageTitle = 'Security';
require_once __DIR__ . '/../includes/header-admin.php';

// ── POST actions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'unlock_user') {
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE users SET failed_logins=0, locked_until=NULL WHERE id=?")->execute([$id]);
            logActivity('unlock_user', 'security', "Unlocked user #$id");
            setFlash('success', 'User account unlocked.');
        }
        redirect(APP_URL . '/admin/security.php');
    }

    if ($act === 'force_logout') {
        // Clear remember_token — user will be logged out on next page load
        $id = (int)($_POST['user_id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE users SET remember_token=NULL WHERE id=?")->execute([$id]);
            logActivity('force_logout', 'security', "Forced logout for user #$id");
            setFlash('success', 'User session tokens cleared. They will be logged out.');
        }
        redirect(APP_URL . '/admin/security.php');
    }

    if ($act === 'clear_attempts') {
        $email = $_POST['email'] ?? '';
        if ($email) {
            $pdo->prepare("DELETE FROM login_attempts WHERE email=?")->execute([$email]);
            setFlash('success', "Login attempts cleared for $email.");
        }
        redirect(APP_URL . '/admin/security.php');
    }

    if ($act === 'purge_attempts') {
        $pdo->query("DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
        setFlash('success', 'Login attempts older than 30 days purged.');
        redirect(APP_URL . '/admin/security.php');
    }
}

// ── Stats ─────────────────────────────────────────────────────────
try {
    $totalAttempts   = $pdo->query("SELECT COUNT(*) FROM login_attempts")->fetchColumn();
    $failedToday     = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND DATE(created_at)=CURDATE()")->fetchColumn();
    $failedThisWeek  = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE success=0 AND created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetchColumn();
    $lockedAccounts  = $pdo->query("SELECT COUNT(*) FROM users WHERE locked_until > NOW()")->fetchColumn();
    $totpUsers       = $pdo->query("SELECT COUNT(*) FROM users WHERE totp_enabled=1")->fetchColumn();
    $totalUsers      = $pdo->query("SELECT COUNT(*) FROM users WHERE role!='super_admin'")->fetchColumn();
} catch (Exception $e) {
    $totalAttempts = $failedToday = $failedThisWeek = $lockedAccounts = $totpUsers = $totalUsers = 0;
}

// ── Locked accounts ───────────────────────────────────────────────
try {
    $lockedUsers = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.failed_logins, u.locked_until,
               o.name as org_name
        FROM users u
        LEFT JOIN organizations o ON u.org_id=o.id
        WHERE u.locked_until > NOW()
        ORDER BY u.locked_until DESC
    ")->fetchAll();
} catch (Exception $e) { $lockedUsers = []; }

// ── Recent failed logins ──────────────────────────────────────────
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;
$fType  = $_GET['filter'] ?? 'failed';

try {
    $successFilter = $fType === 'all' ? '' : ($fType === 'success' ? 'AND success=1' : 'AND success=0');
    $totalAttemptRows = $pdo->query("SELECT COUNT(*) FROM login_attempts WHERE 1=1 $successFilter")->fetchColumn();
    $attempts = $pdo->query("
        SELECT * FROM login_attempts
        WHERE 1=1 $successFilter
        ORDER BY created_at DESC LIMIT $limit OFFSET $offset
    ")->fetchAll();
} catch (Exception $e) { $attempts = []; $totalAttemptRows = 0; }

// ── Top offending IPs / emails ────────────────────────────────────
try {
    $topIps = $pdo->query("
        SELECT ip, COUNT(*) as attempts, SUM(success=0) as failures
        FROM login_attempts
        WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
        GROUP BY ip ORDER BY failures DESC LIMIT 10
    ")->fetchAll();
    $topEmails = $pdo->query("
        SELECT email, COUNT(*) as attempts, SUM(success=0) as failures
        FROM login_attempts
        WHERE created_at >= DATE_SUB(NOW(),INTERVAL 7 DAY)
        GROUP BY email ORDER BY failures DESC LIMIT 10
    ")->fetchAll();
} catch (Exception $e) { $topIps = []; $topEmails = []; }

// ── All users (for session management) ───────────────────────────
try {
    $allUsers = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.last_login, u.failed_logins,
               u.locked_until, u.totp_enabled, o.name as org_name
        FROM users u LEFT JOIN organizations o ON u.org_id=o.id
        WHERE u.role != 'super_admin'
        ORDER BY u.last_login DESC NULLS LAST LIMIT 50
    ")->fetchAll();
} catch (Exception $e) {
    $allUsers = $pdo->query("
        SELECT u.id, u.name, u.email, u.role, u.last_login, u.failed_logins,
               u.locked_until, u.totp_enabled, o.name as org_name
        FROM users u LEFT JOIN organizations o ON u.org_id=o.id
        WHERE u.role != 'super_admin'
        ORDER BY u.last_login DESC LIMIT 50
    ")->fetchAll();
}

$totpPct = $totalUsers > 0 ? round($totpUsers / $totalUsers * 100) : 0;
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-shield-alt me-2 text-green"></i>Security</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Security</li>
    </ol></nav>
  </div>
  <div class="d-flex gap-2">
    <form method="POST" class="d-inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="purge_attempts">
      <button type="submit" class="btn btn-sm btn-outline-warning"
              data-confirm="Purge login attempts older than 30 days?">
        <i class="fas fa-broom me-1"></i>Purge Old Attempts
      </button>
    </form>
    <a href="settings.php#security" class="btn btn-sm btn-outline-secondary">
      <i class="fas fa-cog me-1"></i>Security Settings
    </a>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card danger"><div class="stat-icon danger-bg"><i class="fas fa-ban"></i></div>
      <div><div class="stat-value"><?= $lockedAccounts ?></div><div class="stat-label">Locked Accounts</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning"><div class="stat-icon warning-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div><div class="stat-value"><?= $failedToday ?></div><div class="stat-label">Failed Today</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon navy-bg"><i class="fas fa-history"></i></div>
      <div><div class="stat-value"><?= $failedThisWeek ?></div><div class="stat-label">Failed This Week</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon green-bg"><i class="fas fa-mobile-alt"></i></div>
      <div>
        <div class="stat-value"><?= $totpPct ?>%</div>
        <div class="stat-label">2FA Adoption (<?= $totpUsers ?>/<?= $totalUsers ?>)</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Locked accounts -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">
        <i class="fas fa-ban text-danger me-2"></i>Locked Accounts
        <?php if (!empty($lockedUsers)): ?>
        <span class="badge bg-danger ms-1"><?= count($lockedUsers) ?></span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($lockedUsers)): ?>
        <div class="text-center py-4 text-muted small"><i class="fas fa-check-circle text-green d-block fa-2x mb-2"></i>No locked accounts</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0 small">
            <thead><tr><th>User</th><th>Fails</th><th>Locked Until</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($lockedUsers as $lu): ?>
              <tr>
                <td>
                  <div class="fw-600"><?= e($lu['name']) ?></div>
                  <div class="text-muted"><?= e($lu['email']) ?></div>
                </td>
                <td><span class="badge bg-danger"><?= $lu['failed_logins'] ?></span></td>
                <td class="text-danger fw-600"><?= formatDateTime($lu['locked_until']) ?></td>
                <td>
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="unlock_user">
                    <input type="hidden" name="user_id" value="<?= $lu['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-success" title="Unlock">
                      <i class="fas fa-unlock"></i>
                    </button>
                  </form>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Top offending IPs -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="fas fa-map-marker-alt text-warning me-2"></i>Top Offending IPs (7 days)</div>
      <div class="card-body p-0">
        <?php if (empty($topIps)): ?>
        <div class="text-center py-4 text-muted small">No data yet</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0 small">
            <thead><tr><th>IP Address</th><th>Total</th><th>Failures</th><th>Rate</th></tr></thead>
            <tbody>
              <?php foreach ($topIps as $ip): ?>
              <?php $rate = $ip['attempts'] > 0 ? round($ip['failures'] / $ip['attempts'] * 100) : 0; ?>
              <tr>
                <td class="font-monospace"><?= e($ip['ip']) ?></td>
                <td><?= $ip['attempts'] ?></td>
                <td><span class="badge <?= $ip['failures'] > 5 ? 'bg-danger' : 'bg-warning text-dark' ?>"><?= $ip['failures'] ?></span></td>
                <td>
                  <div class="progress" style="height:6px;width:80px">
                    <div class="progress-bar <?= $rate > 70 ? 'bg-danger' : 'bg-warning' ?>" style="width:<?= $rate ?>%"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- User Session Management -->
<div class="card mb-4">
  <div class="card-header"><i class="fas fa-users text-green me-2"></i>User Security Overview</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small data-table">
        <thead>
          <tr><th>User</th><th>Organization</th><th>Role</th><th>Last Login</th><th>2FA</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($allUsers as $u): ?>
          <?php $isLocked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
          <tr class="<?= $isLocked ? 'table-danger' : '' ?>">
            <td>
              <div class="fw-600"><?= e($u['name']) ?></div>
              <div class="text-muted"><?= e($u['email']) ?></div>
            </td>
            <td><?= e($u['org_name'] ?? '—') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst(str_replace('_',' ',$u['role'])) ?></span></td>
            <td class="text-muted"><?= $u['last_login'] ? timeAgo($u['last_login']) : 'Never' ?></td>
            <td>
              <?php if ($u['totp_enabled']): ?>
              <span class="badge bg-success"><i class="fas fa-shield-alt me-1"></i>On</span>
              <?php else: ?>
              <span class="badge bg-light text-muted border">Off</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($isLocked): ?>
              <span class="badge bg-danger">Locked</span>
              <?php elseif ($u['failed_logins'] > 0): ?>
              <span class="badge bg-warning text-dark"><?= $u['failed_logins'] ?> fails</span>
              <?php else: ?>
              <span class="badge bg-success">OK</span>
              <?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1">
                <?php if ($isLocked): ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="unlock_user">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-success" title="Unlock account">
                    <i class="fas fa-unlock"></i>
                  </button>
                </form>
                <?php endif; ?>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="force_logout">
                  <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-warning" title="Force logout (clear tokens)"
                          data-confirm="Force logout <?= e($u['name']) ?>? They will be logged out next page load.">
                    <i class="fas fa-sign-out-alt"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Login Attempts Log -->
<div class="card">
  <div class="card-header">
    <i class="fas fa-history text-green me-2"></i>Login Attempt Log
    <div class="ms-auto d-flex gap-2 align-items-center">
      <select class="form-select form-select-sm" style="width:auto" onchange="window.location='?filter='+this.value">
        <option value="failed"  <?= $fType==='failed'  ?'selected':'' ?>>Failed Only</option>
        <option value="success" <?= $fType==='success' ?'selected':'' ?>>Successful Only</option>
        <option value="all"     <?= $fType==='all'     ?'selected':'' ?>>All</option>
      </select>
    </div>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 small">
        <thead>
          <tr><th>Email</th><th>IP</th><th>Result</th><th>Time</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($attempts as $a): ?>
          <tr class="<?= !$a['success'] ? 'table-danger-subtle' : '' ?>">
            <td class="font-monospace"><?= e($a['email']) ?></td>
            <td class="font-monospace text-muted"><?= e($a['ip']) ?></td>
            <td>
              <?php if ($a['success']): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>Success</span>
              <?php else: ?>
              <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Failed</span>
              <?php endif; ?>
            </td>
            <td class="text-muted"><?= timeAgo($a['created_at']) ?> <span class="d-none d-md-inline">— <?= formatDateTime($a['created_at']) ?></span></td>
            <td>
              <?php if (!$a['success']): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="clear_attempts">
                <input type="hidden" name="email" value="<?= e($a['email']) ?>">
                <button type="submit" class="btn btn-xs btn-outline-secondary" title="Clear all attempts for this email">
                  <i class="fas fa-eraser"></i>
                </button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($attempts)): ?>
          <tr><td colspan="5" class="text-center py-4 text-muted">No login attempts recorded yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($totalAttemptRows > $limit): ?>
  <div class="card-footer d-flex justify-content-between py-2">
    <small class="text-muted">Showing <?= count($attempts) ?> of <?= $totalAttemptRows ?></small>
    <nav><?= paginate($totalAttemptRows, $limit, $page, '?filter=' . $fType . '&page=') ?></nav>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
