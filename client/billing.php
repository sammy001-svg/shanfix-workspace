<?php
// ── Bootstrap (no HTML yet) ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireClientAdmin();

$user  = currentUser();
$orgId = (int)$user['org_id'];
$sub   = getOrgSubscription($orgId);

// ── POST: Record payment & activate module ───────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_payment') {
    verifyCsrf();
    $invoiceId = (int)$_POST['invoice_id'];
    $method    = sanitize($_POST['payment_method'] ?? 'cash');
    $reference = sanitize($_POST['reference'] ?? '');

    $inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ? AND org_id = ?");
    $inv->execute([$invoiceId, $orgId]);
    $inv = $inv->fetch();

    if ($inv && in_array($inv['status'], ['draft','sent','overdue'])) {
        // Mark invoice paid
        $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")
            ->execute([$invoiceId]);

        // Activate the module linked to this invoice
        if (!empty($inv['module_id'])) {
            $modId = (int)$inv['module_id'];
            $exists = $pdo->prepare("SELECT id FROM subscription_modules WHERE subscription_id=? AND module_id=?");
            $exists->execute([$sub['id'], $modId]);
            if ($exists->fetch()) {
                $pdo->prepare("UPDATE subscription_modules SET status='active' WHERE subscription_id=? AND module_id=?")
                    ->execute([$sub['id'], $modId]);
            } else {
                $pdo->prepare("INSERT INTO subscription_modules (subscription_id, module_id, status) VALUES (?,?,'active')")
                    ->execute([$sub['id'], $modId]);
            }
            // Get module name for flash message
            $modName = $pdo->prepare("SELECT name FROM modules WHERE id=?");
            $modName->execute([$modId]);
            $modName = $modName->fetchColumn() ?: 'Module';
            setFlash('success', "Payment confirmed! <strong>{$modName}</strong> is now active on your workspace. <a href='" . APP_URL . "/client/modules.php' class='fw-bold'>Open Modules →</a>");
        } else {
            setFlash('success', "Payment recorded for invoice <strong>{$inv['invoice_number']}</strong>.");
        }

        logActivity('payment', 'billing', "Invoice {$inv['invoice_number']} paid — {$method}" . ($reference ? ", ref: {$reference}" : ''));
    } else {
        setFlash('error', 'Invoice not found or already paid.');
    }
    redirect(APP_URL . '/client/billing.php?tab=invoices');
}

// ── POST: Request plan upgrade (generates invoice) ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_upgrade') {
    verifyCsrf();
    $planId = (int)$_POST['plan_id'];
    $cycle  = ($_POST['billing_cycle'] ?? 'monthly') === 'annual' ? 'annual' : 'monthly';
    $plan   = $pdo->prepare("SELECT * FROM subscription_plans WHERE id=? AND status='active'");
    $plan->execute([$planId]);
    $plan = $plan->fetch();
    if ($plan) {
        $amount    = $cycle === 'annual' ? (float)$plan['price_annual'] : (float)$plan['price_monthly'];
        $tax       = round($amount * 0.16, 2);
        $total     = $amount + $tax;
        $invoiceNo = 'INV-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 8));
        $dueDate   = date('Y-m-d', strtotime('+7 days'));
        $pdo->prepare("INSERT INTO invoices (org_id, subscription_id, invoice_number, amount, tax, total, status, due_date, notes)
            VALUES (?,?,?,?,?,?,'sent',?,?)")->execute([
            $orgId, $sub['id'] ?? null, $invoiceNo, $amount, $tax, $total, $dueDate,
            "Plan: {$plan['name']} ({$cycle})"
        ]);
        $invoiceId = (int)$pdo->lastInsertId();
        logActivity('create', 'billing', "Plan upgrade invoice: {$plan['name']} — {$invoiceNo}");
        setFlash('success', "Invoice <strong>{$invoiceNo}</strong> generated for <strong>{$plan['name']}</strong> plan.");
        redirect(APP_URL . '/client/billing.php?tab=pay&inv=' . $invoiceId);
    }
    redirect(APP_URL . '/client/billing.php?tab=plans');
}

// ── Render page (HTML starts here) ──────────────────────────────
$pageTitle = 'Billing & Subscription';
require_once __DIR__ . '/../includes/header-client.php';

$plans = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll();

// ── Data ─────────────────────────────────────────────────────────
$activeTab    = $_GET['tab'] ?? 'overview';
$highlightInv = (int)($_GET['inv'] ?? 0);

$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.*, m.name AS module_name, m.icon AS module_icon, m.color AS module_color
        FROM invoices i
        LEFT JOIN modules m ON i.module_id = m.id
        WHERE i.org_id = ?
        ORDER BY i.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    $invoices = $stmt->fetchAll();
} catch (Exception $e) {}

$unpaidInvoices  = array_filter($invoices, fn($i) => in_array($i['status'], ['draft','sent','overdue']));
$totalPaid       = array_sum(array_map(fn($i) => $i['status'] === 'paid' ? (float)$i['total'] : 0, $invoices));
$totalPending    = array_sum(array_map(fn($i) => in_array($i['status'],['draft','sent','overdue']) ? (float)$i['total'] : 0, $invoices));
$statusColors    = ['draft'=>'secondary','sent'=>'info','paid'=>'success','overdue'=>'danger','cancelled'=>'dark'];

// Focus invoice for pay tab
$focusInv = null;
if ($highlightInv) {
    foreach ($invoices as $i) { if ($i['id'] === $highlightInv) { $focusInv = $i; break; } }
}
if (!$focusInv && $activeTab === 'pay' && !empty($unpaidInvoices)) {
    $focusInv = reset($unpaidInvoices);
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-file-invoice-dollar me-2 text-green"></i>Billing & Subscription</h4>
    <p class="text-muted mb-0">Manage your plan, pay invoices, and activate modules</p>
  </div>
  <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-outline-primary btn-sm">
    <i class="fas fa-puzzle-piece me-1"></i>Browse Modules
  </a>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
  <li class="nav-item">
    <a href="?tab=overview" class="nav-link <?= $activeTab==='overview' ? 'active' : '' ?>">
      <i class="fas fa-home me-1"></i>Overview
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=invoices" class="nav-link <?= $activeTab==='invoices' ? 'active' : '' ?>">
      <i class="fas fa-file-invoice me-1"></i>Invoices
      <?php if (count($unpaidInvoices)): ?>
      <span class="badge bg-warning text-dark ms-1"><?= count($unpaidInvoices) ?></span>
      <?php endif; ?>
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=pay" class="nav-link <?= $activeTab==='pay' ? 'active' : '' ?>">
      <i class="fas fa-credit-card me-1"></i>Make Payment
    </a>
  </li>
  <li class="nav-item">
    <a href="?tab=plans" class="nav-link <?= $activeTab==='plans' ? 'active' : '' ?>">
      <i class="fas fa-layer-group me-1"></i>Plans
    </a>
  </li>
</ul>

<!-- ═══════════════ OVERVIEW ═══════════════ -->
<?php if ($activeTab === 'overview'): ?>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Total Paid</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending Payment</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
      <div><div class="stat-value"><?= count($invoices) ?></div><div class="stat-label">Total Invoices</div></div>
    </div>
  </div>
</div>

<?php if (count($unpaidInvoices) > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
  <i class="fas fa-exclamation-circle fa-lg flex-shrink-0"></i>
  <div>
    You have <strong><?= count($unpaidInvoices) ?> unpaid invoice<?= count($unpaidInvoices)>1?'s':'' ?></strong>
    totalling <strong><?= formatCurrency($totalPending) ?></strong>.
    <a href="?tab=pay" class="fw-bold ms-2">Pay Now →</a>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header fw-bold"><i class="fas fa-layer-group text-green me-2"></i>Current Subscription</div>
      <div class="card-body">
        <?php if ($sub): ?>
        <div class="text-center mb-3">
          <div class="display-6 fw-800 text-navy mb-1"><?= e($sub['plan_name'] ?? 'Custom') ?></div>
          <?= statusBadge($sub['status']) ?>
        </div>
        <table class="table table-sm table-borderless mb-3">
          <tr><td class="text-muted small">Billing</td><td class="fw-bold text-capitalize small"><?= $sub['billing_cycle'] ?></td></tr>
          <tr><td class="text-muted small">Amount</td><td class="fw-bold text-green small"><?= formatCurrency((float)$sub['amount']) ?>/mo</td></tr>
          <?php if ($sub['trial_ends_at']): ?>
          <tr><td class="text-muted small">Trial Ends</td><td class="fw-bold text-warning small"><?= formatDate($sub['trial_ends_at']) ?></td></tr>
          <?php endif; ?>
          <?php if ($sub['ends_at']): ?>
          <tr><td class="text-muted small">Renews</td><td class="small"><?= formatDate($sub['ends_at']) ?></td></tr>
          <?php endif; ?>
        </table>
        <a href="?tab=plans" class="btn btn-sm btn-outline-primary w-100"><i class="fas fa-arrow-up me-1"></i>Upgrade Plan</a>
        <?php else: ?>
        <div class="text-center py-4 text-muted">
          <i class="fas fa-layer-group fa-3x mb-2 d-block"></i>
          No subscription yet.
          <div class="mt-3"><a href="?tab=plans" class="btn btn-primary btn-sm">Choose a Plan</a></div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-history text-green me-2"></i>Recent Invoices</span>
        <a href="?tab=invoices" class="btn btn-xs btn-outline-primary btn-sm">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($invoices)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-file-invoice fa-2x mb-2 d-block"></i>
          No invoices yet. <a href="<?= APP_URL ?>/client/modules.php">Add a module</a> to get started.
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
              <tr><th>Invoice</th><th>Module / Plan</th><th>Total</th><th>Due</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach (array_slice($invoices, 0, 8) as $inv):
                $sc = $statusColors[$inv['status']] ?? 'secondary';
              ?>
              <tr>
                <td class="fw-bold small"><?= e($inv['invoice_number']) ?></td>
                <td>
                  <?php if ($inv['module_name']): ?>
                  <span class="badge text-white" style="background:<?= e($inv['module_color'] ?? '#666') ?>">
                    <i class="<?= e($inv['module_icon'] ?? 'fas fa-cube') ?> me-1"></i><?= e($inv['module_name']) ?>
                  </span>
                  <?php else: ?>
                  <span class="text-muted small"><?= e(substr($inv['notes'] ?? 'Subscription', 0, 35)) ?></span>
                  <?php endif; ?>
                </td>
                <td class="fw-bold"><?= formatCurrency((float)$inv['total']) ?></td>
                <td class="small"><?= formatDate($inv['due_date']) ?></td>
                <td><span class="badge bg-<?= $sc ?>"><?= strtoupper($inv['status']) ?></span></td>
                <td>
                  <?php if (in_array($inv['status'], ['draft','sent','overdue'])): ?>
                  <a href="?tab=pay&inv=<?= $inv['id'] ?>" class="btn btn-xs btn-success btn-sm px-2 py-0">Pay</a>
                  <?php endif; ?>
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

<!-- ═══════════════ INVOICES ═══════════════ -->
<?php elseif ($activeTab === 'invoices'): ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between fw-bold">
    <span><i class="fas fa-file-invoice text-green me-2"></i>All Invoices (<?= count($invoices) ?>)</span>
    <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-sm btn-outline-primary">
      <i class="fas fa-plus me-1"></i>Add Module
    </a>
  </div>
  <div class="card-body p-0">
    <?php if (empty($invoices)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-file-invoice fa-3x mb-2 d-block"></i>
      No invoices yet. <a href="<?= APP_URL ?>/client/modules.php">Add a module</a> to generate your first invoice.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Module / Description</th>
            <th>Subtotal</th>
            <th>VAT</th>
            <th>Total</th>
            <th>Due Date</th>
            <th>Paid Date</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv):
            $sc = $statusColors[$inv['status']] ?? 'secondary';
            $isHighlighted = ($inv['id'] === $highlightInv);
          ?>
          <tr <?= $isHighlighted ? 'class="table-warning"' : '' ?>>
            <td class="fw-bold"><?= e($inv['invoice_number']) ?></td>
            <td>
              <?php if ($inv['module_name']): ?>
              <span class="badge text-white" style="background:<?= e($inv['module_color'] ?? '#555') ?>">
                <i class="<?= e($inv['module_icon'] ?? 'fas fa-cube') ?> me-1"></i><?= e($inv['module_name']) ?>
              </span>
              <?php else: ?>
              <span class="text-muted small"><?= e(substr($inv['notes'] ?? '—', 0, 45)) ?></span>
              <?php endif; ?>
            </td>
            <td><?= formatCurrency((float)$inv['amount']) ?></td>
            <td class="text-muted small"><?= formatCurrency((float)$inv['tax']) ?></td>
            <td class="fw-bold"><?= formatCurrency((float)$inv['total']) ?></td>
            <td class="small <?= $inv['status']==='overdue' ? 'text-danger fw-bold' : '' ?>">
              <?= formatDate($inv['due_date']) ?>
            </td>
            <td class="small text-muted"><?= $inv['paid_at'] ? formatDate($inv['paid_at']) : '—' ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= strtoupper($inv['status']) ?></span></td>
            <td class="text-center">
              <div class="d-flex gap-1 justify-content-center">
                <?php if (in_array($inv['status'], ['draft','sent','overdue'])): ?>
                <a href="?tab=pay&inv=<?= $inv['id'] ?>" class="btn btn-xs btn-success btn-sm">
                  <i class="fas fa-credit-card me-1"></i>Pay
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/client/invoice-pdf.php?id=<?= $inv['id'] ?>" target="_blank"
                   class="btn btn-xs btn-outline-secondary btn-sm"><i class="fas fa-download"></i></a>
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

<!-- ═══════════════ MAKE PAYMENT ═══════════════ -->
<?php elseif ($activeTab === 'pay'): ?>

<div class="row g-4">
  <!-- Left: Invoice selector -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-file-invoice text-green me-2"></i>Unpaid Invoices</div>
      <div class="card-body p-0">
        <?php if (empty($unpaidInvoices)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-check-circle fa-3x text-success mb-2 d-block"></i>
          <strong>All invoices are paid!</strong>
          <div class="mt-2"><a href="<?= APP_URL ?>/client/modules.php" class="btn btn-sm btn-outline-primary">Browse Modules</a></div>
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($unpaidInvoices as $inv):
            $isSelected = ($focusInv && $focusInv['id'] === $inv['id']);
            $sc = $statusColors[$inv['status']] ?? 'secondary';
          ?>
          <a href="?tab=pay&inv=<?= $inv['id'] ?>"
             class="list-group-item list-group-item-action <?= $isSelected ? 'active' : '' ?>">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <div class="fw-bold small"><?= e($inv['invoice_number']) ?></div>
                <?php if ($inv['module_name']): ?>
                <span class="badge text-white mt-1" style="background:<?= $isSelected?'rgba(255,255,255,0.25)':e($inv['module_color']??'#555') ?>">
                  <i class="<?= e($inv['module_icon']??'fas fa-cube') ?> me-1"></i><?= e($inv['module_name']) ?>
                </span>
                <?php else: ?>
                <small class="<?= $isSelected?'text-white-50':'text-muted' ?>"><?= e(substr($inv['notes']??'',0,40)) ?></small>
                <?php endif; ?>
              </div>
              <div class="text-end ms-2">
                <div class="fw-800"><?= formatCurrency((float)$inv['total']) ?></div>
                <small class="<?= $isSelected?'text-white-50':'text-danger' ?>">Due <?= formatDate($inv['due_date']) ?></small>
              </div>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Payment form -->
  <div class="col-lg-7">
    <?php if ($focusInv): ?>

    <!-- Invoice summary card -->
    <div class="card mb-3">
      <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="fas fa-receipt me-2 text-green"></i>Invoice <?= e($focusInv['invoice_number']) ?></h6>
        <?php if ($focusInv['module_name']): ?>
        <div class="d-flex align-items-center gap-2 p-2 rounded mb-3" style="background:<?= e($focusInv['module_color']??'#555') ?>1a">
          <div class="rounded-circle d-flex align-items-center justify-content-center text-white" style="width:36px;height:36px;background:<?= e($focusInv['module_color']??'#555') ?>;flex-shrink:0">
            <i class="<?= e($focusInv['module_icon']??'fas fa-cube') ?> small"></i>
          </div>
          <div>
            <div class="fw-bold"><?= e($focusInv['module_name']) ?></div>
            <small class="text-muted">Monthly subscription · activates immediately on payment</small>
          </div>
        </div>
        <?php else: ?>
        <p class="text-muted small mb-3"><?= e($focusInv['notes'] ?? '') ?></p>
        <?php endif; ?>

        <table class="table table-sm table-borderless mb-0">
          <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= formatCurrency((float)$focusInv['amount']) ?></td></tr>
          <tr><td class="text-muted">VAT (16%)</td><td class="text-end"><?= formatCurrency((float)$focusInv['tax']) ?></td></tr>
          <tr class="border-top"><td class="fw-bold">Total Due</td><td class="text-end fw-800 text-green fs-5"><?= formatCurrency((float)$focusInv['total']) ?></td></tr>
        </table>
      </div>
    </div>

    <!-- Payment options -->
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-credit-card text-green me-2"></i>Choose Payment Method</div>
      <div class="card-body">

        <!-- M-Pesa -->
        <div class="border rounded p-3 mb-3">
          <div class="fw-bold mb-2"><i class="fas fa-mobile-alt text-success me-2"></i>M-Pesa STK Push</div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">M-Pesa Phone Number</label>
            <input type="tel" id="mpesaPhone" class="form-control"
                   placeholder="+254 700 000 000"
                   value="<?= e(preg_replace('/[^0-9+]/', '', $user['phone'] ?? '')) ?>">
          </div>
          <button class="btn btn-success w-100"
                  onclick="initiateMpesa(<?= $focusInv['id'] ?>, <?= $focusInv['total'] ?>)">
            <i class="fas fa-mobile-alt me-2"></i>Send M-Pesa STK Push — <?= formatCurrency((float)$focusInv['total']) ?>
          </button>
          <p class="text-muted small text-center mt-2 mb-0">
            Paybill: <strong>123456</strong> · Account: <strong><?= e($user['email']) ?></strong>
          </p>
        </div>

        <!-- Cash / Bank Transfer -->
        <div class="border rounded p-3">
          <div class="fw-bold mb-2"><i class="fas fa-money-bill-wave text-primary me-2"></i>Cash / Bank Transfer</div>
          <p class="text-muted small mb-3">Have you already paid? Record the payment here and your module will be activated immediately.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="invoice_id" value="<?= $focusInv['id'] ?>">
            <div class="row g-2 mb-3">
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Payment Method</label>
                <select name="payment_method" class="form-select form-select-sm">
                  <option value="cash">Cash</option>
                  <option value="bank_transfer">Bank Transfer</option>
                  <option value="mobile_money">Mobile Money</option>
                  <option value="cheque">Cheque</option>
                  <option value="card">Card</option>
                  <option value="other">Other</option>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label small fw-semibold">Reference / Receipt No.</label>
                <input type="text" name="reference" class="form-control form-control-sm"
                       placeholder="e.g. TXN-0012345">
              </div>
            </div>
            <button type="submit" class="btn btn-primary w-100"
                    onclick="return confirm('Confirm payment of <?= formatCurrency((float)$focusInv['total']) ?>?\n\n<?= $focusInv['module_name'] ? 'This will immediately activate ' . addslashes($focusInv['module_name']) . ' on your workspace.' : 'This will mark the invoice as paid.' ?>')">
              <i class="fas fa-check-circle me-2"></i>Confirm Payment<?= $focusInv['module_name'] ? ' & Activate ' . e($focusInv['module_name']) : '' ?>
            </button>
          </form>
        </div>
      </div>
    </div>

    <?php else: ?>
    <div class="card">
      <div class="card-body text-center py-5 text-muted">
        <i class="fas fa-hand-point-left fa-2x mb-2 d-block"></i>
        Select an invoice from the left to pay it.
        <?php if (empty($unpaidInvoices)): ?>
        <div class="mt-3">
          <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-puzzle-piece me-1"></i>Browse Modules
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ═══════════════ PLANS ═══════════════ -->
<?php elseif ($activeTab === 'plans'): ?>

<div class="row g-3 justify-content-center">
  <?php foreach ($plans as $p):
    $isCurrent = ($sub && $sub['plan_id'] == $p['id']);
  ?>
  <div class="col-md-4">
    <div class="card h-100 <?= $isCurrent ? 'border-success border-2' : ($p['is_popular'] ? 'border-primary border-2 shadow' : '') ?>">
      <?php if ($isCurrent): ?>
      <div class="text-center py-1 bg-success text-white" style="font-size:.75rem;font-weight:700;border-radius:.375rem .375rem 0 0">✓ YOUR CURRENT PLAN</div>
      <?php elseif ($p['is_popular']): ?>
      <div class="text-center py-1 bg-primary text-white" style="font-size:.75rem;font-weight:700;border-radius:.375rem .375rem 0 0">⭐ MOST POPULAR</div>
      <?php endif; ?>
      <div class="card-body text-center">
        <h5 class="fw-800 text-navy mb-1"><?= e($p['name']) ?></h5>
        <p class="text-muted small mb-3"><?= e($p['description']) ?></p>
        <div class="mb-1">
          <span class="display-5 fw-800 text-green"><?= formatCurrency((float)$p['price_monthly']) ?></span>
          <span class="text-muted">/mo</span>
        </div>
        <div class="text-muted small mb-3">
          or <?= formatCurrency((float)$p['price_annual']) ?>/yr
          <span class="badge bg-success ms-1">Save ~17%</span>
        </div>
        <ul class="list-unstyled text-start mb-4 small">
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i><?= $p['max_users'] ?> team members</li>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i><?= $p['max_modules'] ?> modules</li>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>All core features</li>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Email & chat support</li>
          <?php if ($p['is_popular']): ?>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Priority support</li>
          <li class="mb-1"><i class="fas fa-check text-success me-2"></i>Advanced analytics</li>
          <?php endif; ?>
        </ul>
        <?php if ($isCurrent): ?>
        <button class="btn btn-outline-success w-100" disabled><i class="fas fa-check me-1"></i>Active Plan</button>
        <?php else: ?>
        <div class="d-flex gap-2">
          <form method="POST" class="flex-grow-1">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="request_upgrade">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="billing_cycle" value="monthly">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100">Monthly</button>
          </form>
          <form method="POST" class="flex-grow-1">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="request_upgrade">
            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
            <input type="hidden" name="billing_cycle" value="annual">
            <button type="submit" class="btn btn-primary btn-sm w-100">Annual</button>
          </form>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php
$extraJs = '<script>
function initiateMpesa(invoiceId, amount) {
  const phone = document.getElementById("mpesaPhone").value.trim();
  if (!phone) {
    Swal.fire({ icon: "warning", title: "Phone Required", text: "Please enter your M-Pesa phone number." });
    return;
  }
  const btn = event.currentTarget;
  btn.disabled = true;
  btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Sending...\';
  fetch("' . APP_URL . '/api/mpesa-stk.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `phone=${encodeURIComponent(phone)}&amount=${encodeURIComponent(amount)}&invoice_id=${invoiceId}`
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Send M-Pesa STK Push\';
    if (data.success) {
      Swal.fire({ icon: "success", title: "STK Push Sent!", text: data.message, confirmButtonColor: "#1A8A4E" });
    } else {
      Swal.fire({ icon: "error", title: "Failed", text: data.message });
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Send M-Pesa STK Push\';
    Swal.fire({ icon: "error", title: "Network Error", text: "Could not reach payment server." });
  });
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
