<?php
$pageTitle = 'Client Management';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// ── Actions ───────────────────────────────────────────────────────
$action  = $_GET['action'] ?? '';
$viewId  = (int)($_GET['view']   ?? 0);
$editId  = (int)($_GET['edit']   ?? 0);
$deleteId= (int)($_GET['delete'] ?? 0);

if ($deleteId) {
    $pdo->prepare("UPDATE organizations SET status='inactive' WHERE id=?")->execute([$deleteId]);
    setFlash('success', 'Client deactivated successfully.');
    redirect(APP_URL . '/admin/clients.php');
}

// ── POST: save edit ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_org_id'])) {
    $id      = (int)$_POST['edit_org_id'];
    $name    = sanitize($_POST['org_name']    ?? '');
    $email   = sanitize($_POST['email']       ?? '');
    $phone   = sanitize($_POST['phone']       ?? '');
    $city    = sanitize($_POST['city']        ?? '');
    $country = sanitize($_POST['country']     ?? '');
    $status  = in_array($_POST['status'] ?? '', ['active','inactive','suspended']) ? $_POST['status'] : 'active';

    if (!$name || !$email) {
        setFlash('danger', 'Name and email are required.');
        redirect(APP_URL . '/admin/clients.php?edit=' . $id);
    } else {
        $pdo->prepare("UPDATE organizations SET name=?,email=?,phone=?,city=?,country=?,status=? WHERE id=?")
            ->execute([$name, $email, $phone, $city, $country, $status, $id]);
        logActivity('edit_client', 'admin', "Updated client: $name");
        setFlash('success', "Client '$name' updated.");
        redirect(APP_URL . '/admin/clients.php?view=' . $id);
    }
}

// ── View single client ────────────────────────────────────────────
if ($viewId) {
    $org = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
    $org->execute([$viewId]);
    $org = $org->fetch();

    if (!$org) { setFlash('danger', 'Client not found.'); redirect(APP_URL . '/admin/clients.php'); }

    // Subscription
    $sub = $pdo->prepare("
        SELECT s.*, p.name as plan_name
        FROM subscriptions s
        LEFT JOIN subscription_plans p ON s.plan_id = p.id
        WHERE s.org_id=? ORDER BY s.id DESC LIMIT 1
    ");
    $sub->execute([$viewId]);
    $sub = $sub->fetch();

    // Active modules
    $orgModules = [];
    if ($sub) {
        $mq = $pdo->prepare("
            SELECT m.name, m.icon, m.color, m.slug
            FROM subscription_modules sm
            JOIN modules m ON sm.module_id = m.id
            WHERE sm.subscription_id=? AND sm.status='active'
            ORDER BY m.sort_order
        ");
        $mq->execute([$sub['id']]);
        $orgModules = $mq->fetchAll();
    }

    // Users
    $orgUsers = $pdo->prepare("SELECT * FROM users WHERE org_id=? AND role!='super_admin' ORDER BY created_at DESC");
    $orgUsers->execute([$viewId]);
    $orgUsers = $orgUsers->fetchAll();

    // Recent invoices
    $orgInvoices = $pdo->prepare("SELECT id, invoice_number, CAST(amount AS DECIMAL(12,2)) AS amount, CAST(tax AS DECIMAL(12,2)) AS tax, CAST(total AS DECIMAL(12,2)) AS total, status, due_date, paid_at, notes, created_at FROM invoices WHERE org_id=? ORDER BY created_at DESC LIMIT 8");
    $orgInvoices->execute([$viewId]);
    $orgInvoices = $orgInvoices->fetchAll();

    // Activity log
    $orgActivity = $pdo->prepare("SELECT * FROM activity_log WHERE org_id=? ORDER BY created_at DESC LIMIT 10");
    $orgActivity->execute([$viewId]);
    $orgActivity = $orgActivity->fetchAll();

    require_once __DIR__ . '/../includes/header-admin.php';
    ?>

    <div class="page-header">
      <div>
        <h4><i class="fas fa-building me-2 text-green"></i><?= e($org['name']) ?></h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
          <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
          <li class="breadcrumb-item"><a href="clients.php">Clients</a></li>
          <li class="breadcrumb-item active"><?= e($org['name']) ?></li>
        </ol></nav>
      </div>
      <div class="d-flex gap-2">
        <a href="?edit=<?= $viewId ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-edit me-1"></i>Edit</a>
        <a href="subscriptions.php?org=<?= $viewId ?>" class="btn btn-outline-info btn-sm"><i class="fas fa-credit-card me-1"></i>Subscriptions</a>
        <a href="invoices.php" class="btn btn-outline-warning btn-sm"><i class="fas fa-file-invoice me-1"></i>Generate Invoice</a>
        <?php if ($org['status'] === 'active'): ?>
        <a href="?delete=<?= $viewId ?>" class="btn btn-outline-danger btn-sm"
           data-confirm="Deactivate <?= e($org['name']) ?>?"><i class="fas fa-ban me-1"></i>Deactivate</a>
        <?php else: ?>
        <button class="btn btn-outline-success btn-sm" onclick="activateOrg(<?= $viewId ?>)"><i class="fas fa-check me-1"></i>Activate</button>
        <?php endif; ?>
        <a href="clients.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
      </div>
    </div>

    <div class="row g-4">
      <!-- Left column -->
      <div class="col-lg-4">
        <!-- Profile card -->
        <div class="card mb-4">
          <div class="card-body text-center py-4">
            <div class="avatar-lg mx-auto mb-3" style="background:var(--navy);font-size:1.5rem;width:72px;height:72px;border-radius:16px">
              <?= strtoupper(substr($org['name'], 0, 2)) ?>
            </div>
            <h5 class="fw-700 mb-0"><?= e($org['name']) ?></h5>
            <div class="text-muted small mb-2"><?= e($org['email'] ?? '') ?></div>
            <?= statusBadge($org['status']) ?>
            <hr>
            <div class="text-start small">
              <?php if ($org['phone']): ?><div class="mb-2"><i class="fas fa-phone text-green me-2"></i><?= e($org['phone']) ?></div><?php endif; ?>
              <?php if ($org['city']): ?><div class="mb-2"><i class="fas fa-map-marker-alt text-green me-2"></i><?= e($org['city']) ?><?= $org['country'] ? ', ' . e($org['country']) : '' ?></div><?php endif; ?>
              <div class="mb-2"><i class="fas fa-calendar text-green me-2"></i>Joined <?= formatDate($org['created_at']) ?></div>
            </div>
          </div>
        </div>

        <!-- Login Portal card -->
        <?php
        $__orgSlug   = $org['slug'] ?? null;
        $__portalUrl = $__orgSlug ? APP_URL . '/auth/org-login.php?org=' . rawurlencode($__orgSlug) : null;
        ?>
        <div class="card mb-4" style="border-left:4px solid #1A8A4E">
          <div class="card-header d-flex align-items-center gap-2">
            <div style="width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#1A8A4E,#22a860);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.75rem;flex-shrink:0">
              <i class="fas fa-link"></i>
            </div>
            <span class="fw-semibold small">Client Login Portal</span>
          </div>
          <div class="card-body">
            <p class="text-muted small mb-3">Share this direct login link with the client's team members so they can access their workspace without needing the main login page.</p>
            <?php if ($__portalUrl): ?>
            <div class="d-flex align-items-center gap-2 mb-3 p-2 rounded" style="background:#f0fdf4;border:1px solid #bbf7d0;">
              <i class="fas fa-link text-green flex-shrink-0 small"></i>
              <code class="small flex-1 text-navy" id="adminPortalUrl" style="word-break:break-all;background:none;font-size:.72rem"><?= htmlspecialchars($__portalUrl, ENT_QUOTES) ?></code>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn btn-sm text-white fw-semibold" style="background:#1A8A4E" onclick="copyAdminPortalUrl()" id="adminPortalCopyBtn">
                <i class="fas fa-copy me-1" id="adminPortalCopyIcon"></i><span id="adminPortalCopyText">Copy Link</span>
              </button>
              <a href="<?= htmlspecialchars($__portalUrl, ENT_QUOTES) ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-external-link-alt me-1"></i>Open Portal
              </a>
              <a href="?edit=<?= $viewId ?>" class="btn btn-sm btn-link text-muted p-0 ms-auto align-self-center small">
                <i class="fas fa-info-circle me-1"></i>Slug: <?= htmlspecialchars($__orgSlug, ENT_QUOTES) ?>
              </a>
            </div>
            <?php else: ?>
            <div class="text-muted small text-center py-2">
              <i class="fas fa-exclamation-triangle text-warning me-1"></i>
              No portal URL — organization slug not set. <a href="?edit=<?= $viewId ?>">Edit client</a> to assign one.
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Subscription card -->
        <div class="card mb-4">
          <div class="card-header"><i class="fas fa-credit-card text-green me-2"></i>Subscription</div>
          <div class="card-body">
            <?php if ($sub): ?>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Plan</span>
              <span class="badge bg-info text-dark"><?= e($sub['plan_name'] ?? 'Custom') ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Status</span>
              <?= statusBadge($sub['status']) ?>
            </div>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Billing</span>
              <span class="small text-capitalize"><?= $sub['billing_cycle'] ?></span>
            </div>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Amount</span>
              <span class="fw-700"><?= formatCurrency((float)$sub['amount']) ?></span>
            </div>
            <?php if ($sub['trial_ends_at']): ?>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Trial ends</span>
              <span class="small text-warning"><?= formatDate($sub['trial_ends_at']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($sub['ends_at']): ?>
            <div class="mb-2 d-flex justify-content-between align-items-center">
              <span class="small text-muted">Expires</span>
              <span class="small <?= $sub['ends_at'] < date('Y-m-d') ? 'text-danger fw-600' : '' ?>"><?= formatDate($sub['ends_at']) ?></span>
            </div>
            <?php endif; ?>
            <a href="subscriptions.php?org=<?= $viewId ?>" class="btn btn-sm btn-outline-primary w-100 mt-2">
              <i class="fas fa-cog me-1"></i>Manage Subscription
            </a>
            <?php else: ?>
            <div class="text-muted small text-center py-3">No subscription yet.</div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Quick stats -->
        <div class="row g-2">
          <div class="col-6">
            <div class="card text-center py-3">
              <div class="fw-700 fs-4 text-navy"><?= count($orgUsers) ?></div>
              <div class="small text-muted">Users</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card text-center py-3">
              <div class="fw-700 fs-4 text-green"><?= count($orgModules) ?></div>
              <div class="small text-muted">Modules</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card text-center py-3">
              <div class="fw-700 fs-4 text-navy"><?= count($orgInvoices) ?></div>
              <div class="small text-muted">Invoices</div>
            </div>
          </div>
          <div class="col-6">
            <div class="card text-center py-3">
              <?php $totalPaid = array_sum(array_column(array_filter($orgInvoices, fn($i)=>$i['status']==='paid'), 'total')); ?>
              <div class="fw-700 fs-5 text-green"><?= 'KES ' . number_format($totalPaid) ?></div>
              <div class="small text-muted">Revenue</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Right column -->
      <div class="col-lg-8">
        <!-- Active modules -->
        <?php if (!empty($orgModules)): ?>
        <div class="card mb-4">
          <div class="card-header"><i class="fas fa-puzzle-piece text-green me-2"></i>Active Modules (<?= count($orgModules) ?>)</div>
          <div class="card-body">
            <div class="row g-2">
              <?php foreach ($orgModules as $mod): ?>
              <div class="col-6 col-md-4 col-lg-3">
                <div class="d-flex align-items-center gap-2 rounded p-2" style="background:var(--gray-50)">
                  <div style="width:30px;height:30px;border-radius:8px;background:<?= e($mod['color']) ?>1a;color:<?= e($mod['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem">
                    <i class="<?= e($mod['icon']) ?>"></i>
                  </div>
                  <span class="small fw-600"><?= e($mod['name']) ?></span>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Users -->
        <div class="card mb-4">
          <div class="card-header"><i class="fas fa-users text-green me-2"></i>Users (<?= count($orgUsers) ?>)</div>
          <div class="card-body p-0">
            <?php if (empty($orgUsers)): ?>
            <div class="text-center py-4 text-muted small">No users yet.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0 small">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th></tr></thead>
                <tbody>
                  <?php foreach ($orgUsers as $u): ?>
                  <tr>
                    <td class="fw-600"><?= e($u['name'] ?? $u['full_name'] ?? '—') ?></td>
                    <td class="text-muted"><?= e($u['email']) ?></td>
                    <td><span class="badge bg-secondary"><?= e($u['role']) ?></span></td>
                    <td><?= statusBadge($u['status'] ?? 'active') ?></td>
                    <td class="text-muted"><?= formatDate($u['created_at']) ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Invoices -->
        <div class="card mb-4">
          <div class="card-header">
            <i class="fas fa-file-invoice text-green me-2"></i>Recent Invoices
            <a href="invoices.php" class="ms-auto btn btn-xs btn-outline-primary">View All</a>
          </div>
          <div class="card-body p-0">
            <?php if (empty($orgInvoices)): ?>
            <div class="text-center py-4 text-muted small">No invoices yet.</div>
            <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover mb-0 small">
                <thead><tr><th>Invoice #</th><th>Total</th><th>Due Date</th><th>Status</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($orgInvoices as $inv): ?>
                  <tr>
                    <td class="fw-700 text-navy"><?= e($inv['invoice_number']) ?></td>
                    <td><?= formatCurrency($inv['total']) ?></td>
                    <td class="<?= ($inv['status']==='sent' && $inv['due_date'] < date('Y-m-d')) ? 'text-danger fw-600' : 'text-muted' ?>">
                      <?= formatDate($inv['due_date']) ?>
                    </td>
                    <td><?= statusBadge($inv['status']) ?></td>
                    <td>
                      <a href="invoice-pdf.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary" target="_blank"><i class="fas fa-file-pdf"></i></a>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Activity log -->
        <?php if (!empty($orgActivity)): ?>
        <div class="card">
          <div class="card-header"><i class="fas fa-history text-green me-2"></i>Recent Activity</div>
          <div class="card-body p-0">
            <ul class="list-group list-group-flush">
              <?php foreach ($orgActivity as $log): ?>
              <li class="list-group-item py-2">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <span class="badge bg-secondary me-2 small"><?= e($log['action'] ?? '') ?></span>
                    <span class="small"><?= e($log['description'] ?? '') ?></span>
                  </div>
                  <span class="text-muted" style="font-size:.75rem;white-space:nowrap"><?= formatDate($log['created_at']) ?></span>
                </div>
              </li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <?php
    $extraJs = '<script>
    function activateOrg(id) {
      if (!confirm("Activate this client?")) return;
      fetch("' . APP_URL . '/admin/ajax.php", {
        method: "POST",
        headers: {"Content-Type":"application/json"},
        body: JSON.stringify({action:"toggle_org_status", id, status:"active"})
      }).then(() => location.reload());
    }

    function copyAdminPortalUrl() {
      var url  = document.getElementById("adminPortalUrl").textContent.trim();
      var btn  = document.getElementById("adminPortalCopyBtn");
      var ico  = document.getElementById("adminPortalCopyIcon");
      var txt  = document.getElementById("adminPortalCopyText");
      navigator.clipboard.writeText(url).then(function() {
        var origBg = btn.style.background;
        btn.style.background = "#0B2D4E";
        ico.className = "fas fa-check me-1";
        txt.textContent = "Copied!";
        setTimeout(function() {
          btn.style.background = "#1A8A4E";
          ico.className = "fas fa-copy me-1";
          txt.textContent = "Copy Link";
        }, 2200);
      }).catch(function() {
        var ta = document.createElement("textarea");
        ta.value = url; ta.style.position = "fixed"; ta.style.opacity = "0";
        document.body.appendChild(ta); ta.select();
        try { document.execCommand("copy"); } catch(e) {}
        document.body.removeChild(ta);
        txt.textContent = "Copied!";
        setTimeout(function(){ txt.textContent = "Copy Link"; }, 2000);
      });
    }
    </script>';
    require_once __DIR__ . '/../includes/footer.php';
    return; // stop further execution — view mode complete
}

// ── Edit form ─────────────────────────────────────────────────────
if ($editId) {
    $org = $pdo->prepare("SELECT * FROM organizations WHERE id=?");
    $org->execute([$editId]);
    $org = $org->fetch();
    if (!$org) { setFlash('danger', 'Client not found.'); redirect(APP_URL . '/admin/clients.php'); }

    require_once __DIR__ . '/../includes/header-admin.php';
    ?>

    <div class="page-header">
      <div>
        <h4><i class="fas fa-edit me-2 text-green"></i>Edit Client</h4>
        <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
          <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
          <li class="breadcrumb-item"><a href="clients.php">Clients</a></li>
          <li class="breadcrumb-item"><a href="?view=<?= $editId ?>"><?= e($org['name']) ?></a></li>
          <li class="breadcrumb-item active">Edit</li>
        </ol></nav>
      </div>
      <a href="?view=<?= $editId ?>" class="btn btn-light btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
    </div>

    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card">
          <div class="card-header"><i class="fas fa-building text-green me-2"></i>Organization Details</div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="edit_org_id" value="<?= $org['id'] ?>">
              <div class="row g-3">
                <div class="col-md-6">
                  <label class="form-label">Organization Name *</label>
                  <input type="text" name="org_name" class="form-control" required value="<?= e($org['name']) ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Business Email *</label>
                  <input type="email" name="email" class="form-control" required value="<?= e($org['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Phone</label>
                  <input type="tel" name="phone" class="form-control" value="<?= e($org['phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                  <label class="form-label">City</label>
                  <input type="text" name="city" class="form-control" value="<?= e($org['city'] ?? '') ?>" placeholder="Nairobi">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Country</label>
                  <input type="text" name="country" class="form-control" value="<?= e($org['country'] ?? '') ?>" placeholder="Kenya">
                </div>
                <div class="col-md-6">
                  <label class="form-label">Status</label>
                  <select name="status" class="form-select">
                    <?php foreach (['active','inactive','suspended'] as $st): ?>
                    <option value="<?= $st ?>" <?= $org['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12 d-flex gap-2">
                  <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
                  <a href="?view=<?= $editId ?>" class="btn btn-secondary">Cancel</a>
                </div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <?php
    require_once __DIR__ . '/../includes/footer.php';
    return;
}

// ── List view (default) ────────────────────────────────────────────
$clients = $pdo->query("
    SELECT o.*, s.status as sub_status, s.trial_ends_at, s.ends_at,
           COUNT(DISTINCT u.id) as user_count,
           COUNT(DISTINCT sm.module_id) as module_count,
           p.name as plan_name
    FROM organizations o
    LEFT JOIN subscriptions s ON o.id = s.org_id AND s.id = (SELECT MAX(id) FROM subscriptions WHERE org_id = o.id)
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN users u ON o.id = u.org_id AND u.role != 'super_admin'
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id AND sm.status = 'active'
    GROUP BY o.id ORDER BY o.created_at DESC
")->fetchAll();

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';
    $headers = ['Organization', 'Email', 'Phone', 'City', 'Country', 'Status', 'Plan', 'Subscription Status', 'Created'];
    $rows = [];
    foreach ($clients as $c) {
        $rows[] = [$c['name']??'', $c['email']??'', $c['phone']??'', $c['city']??'', $c['country']??'', $c['status']??'', $c['plan_name']??'', $c['sub_status']??'', $c['created_at']??''];
    }
    exportCsv('clients-' . date('Y-m-d') . '.csv', $headers, $rows);
}

$modules = $pdo->query("SELECT * FROM modules WHERE status='active' ORDER BY sort_order")->fetchAll();
$plans   = $pdo->query("SELECT * FROM subscription_plans WHERE status='active'")->fetchAll();

require_once __DIR__ . '/../includes/header-admin.php';
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-building me-2 text-green"></i>Client Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Clients</li></ol></nav>
  </div>
  <div class="d-flex gap-2">
    <a href="?export=csv" class="btn btn-sm btn-outline-success"><i class="fas fa-file-csv me-1"></i>Export CSV</a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addClientModal">
      <i class="fas fa-plus me-2"></i>Add Client
    </button>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php
  $cStats = [
    ['Total Clients',  count($clients),                                                               'navy-bg','fas fa-building','navy'],
    ['Active',         count(array_filter($clients, fn($c)=>$c['status']==='active')),                'green-bg','fas fa-check','green'],
    ['On Trial',       count(array_filter($clients, fn($c)=>($c['sub_status']??'')==='trial')),       'warning-bg','fas fa-clock','warning'],
    ['Inactive',       count(array_filter($clients, fn($c)=>$c['status']==='inactive')),              'danger-bg','fas fa-times','danger'],
  ];
  foreach($cStats as $s): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $s[4] ?>">
      <div class="stat-icon <?= $s[2] ?>"><i class="<?= $s[3] ?>"></i></div>
      <div><div class="stat-value"><?= $s[1] ?></div><div class="stat-label"><?= $s[0] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-center">
      <div class="col-md-4">
        <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search clients...">
      </div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="statusFilter">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
          <option value="suspended">Suspended</option>
        </select>
      </div>
      <div class="col-md-2">
        <select class="form-select form-select-sm" id="subFilter">
          <option value="">All Subscriptions</option>
          <option value="active">Active</option>
          <option value="trial">Trial</option>
          <option value="expired">Expired</option>
        </select>
      </div>
    </div>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table" id="clientsTable">
        <thead>
          <tr><th>#</th><th>Organization</th><th>Plan</th><th>Modules</th><th>Users</th><th>Subscription</th><th>Expiry</th><th>Status</th><th>Joined</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($clients as $i => $c): ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="d-flex align-items-center gap-2">
                <div class="avatar-sm" style="background:var(--navy);font-size:.65rem"><?= strtoupper(substr($c['name'],0,2)) ?></div>
                <div>
                  <div class="fw-600 text-navy"><?= e($c['name']) ?></div>
                  <div class="text-muted small"><?= e($c['email'] ?? '') ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge bg-info text-dark"><?= e($c['plan_name'] ?? 'No Plan') ?></span></td>
            <td><span class="badge bg-primary"><?= $c['module_count'] ?></span></td>
            <td><span class="badge bg-secondary"><?= $c['user_count'] ?></span></td>
            <td><?= statusBadge($c['sub_status'] ?? 'none') ?></td>
            <td class="small">
              <?php if ($c['trial_ends_at']): ?>
                <span class="text-warning"><?= formatDate($c['trial_ends_at']) ?></span>
              <?php elseif ($c['ends_at']): ?>
                <?= formatDate($c['ends_at']) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td class="small text-muted"><?= formatDate($c['created_at']) ?></td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="?view=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="View details"><i class="fas fa-eye"></i></a>
                <a href="?edit=<?= $c['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                <?php if (!empty($c['slug'])): ?>
                <button class="btn btn-xs btn-outline-success" title="Copy login portal URL"
                        onclick="copyClientPortalUrl('<?= htmlspecialchars(APP_URL . '/auth/org-login.php?org=' . rawurlencode($c['slug']), ENT_QUOTES) ?>', this)">
                  <i class="fas fa-link"></i>
                </button>
                <?php endif; ?>
                <a href="subscriptions.php?org=<?= $c['id'] ?>" class="btn btn-xs btn-outline-info" title="Manage subscription"><i class="fas fa-credit-card"></i></a>
                <?php if($c['status'] === 'active'): ?>
                <a href="?delete=<?= $c['id'] ?>" class="btn btn-xs btn-outline-danger" data-confirm="Deactivate <?= e($c['name']) ?>?" title="Deactivate"><i class="fas fa-ban"></i></a>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($clients)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted">No clients yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Client Modal -->
<div class="modal fade" id="addClientModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Add New Client</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST" action="<?= APP_URL ?>/admin/clients-save.php">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Organization Name *</label>
              <input type="text" name="org_name" class="form-control" required placeholder="Business name">
            </div>
            <div class="col-md-6">
              <label class="form-label">Business Email *</label>
              <input type="email" name="email" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Phone</label>
              <input type="tel" name="phone" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">City</label>
              <input type="text" name="city" class="form-control" placeholder="Nairobi">
            </div>
            <div class="col-12"><hr class="my-1"><div class="fw-600 text-navy small">Admin Account</div></div>
            <div class="col-md-6">
              <label class="form-label">Admin Name *</label>
              <input type="text" name="admin_name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Admin Password *</label>
              <input type="password" name="admin_password" class="form-control" required placeholder="Min. 8 chars">
            </div>
            <div class="col-12"><hr class="my-1"><div class="fw-600 text-navy small">Subscription</div></div>
            <div class="col-md-6">
              <label class="form-label">Plan</label>
              <select name="plan_id" class="form-select">
                <option value="">No Plan (Trial)</option>
                <?php foreach($plans as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['name']) ?> — KES <?= number_format($p['price_monthly']) ?>/mo</option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Subscription Status</label>
              <select name="sub_status" class="form-select">
                <option value="trial">Trial (14 days)</option>
                <option value="active">Active</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Modules to Activate</label>
              <div class="row g-1">
                <?php foreach($modules as $m): ?>
                <div class="col-6 col-md-4">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="modules[]" value="<?= e($m['slug']) ?>" id="mod_<?= e($m['slug']) ?>">
                    <label class="form-check-label small" for="mod_<?= e($m['slug']) ?>"><?= e($m['name']) ?></label>
                  </div>
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Create Client</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function copyClientPortalUrl(url, btn) {
  navigator.clipboard.writeText(url).then(function() {
    var ico = btn.querySelector('i');
    var orig = ico.className;
    ico.className = 'fas fa-check';
    btn.classList.remove('btn-outline-success');
    btn.classList.add('btn-success');
    btn.title = 'Copied!';
    setTimeout(function() {
      ico.className = orig;
      btn.classList.remove('btn-success');
      btn.classList.add('btn-outline-success');
      btn.title = 'Copy login portal URL';
    }, 2000);
  }).catch(function() {
    var ta = document.createElement('textarea');
    ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch(e) {}
    document.body.removeChild(ta);
  });
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php'; ?>
