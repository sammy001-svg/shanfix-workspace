<?php
// ── Bootstrap (no HTML yet) ──────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mailer.php';
requireClientAdmin();

// Staff members have no access to billing
$__billingUser = currentUser();
if (($__billingUser['role'] ?? '') === 'staff') {
    setFlash('danger', 'Access denied. Billing is managed by your organisation administrator.');
    redirect(APP_URL . '/client/index.php');
}

// ── Payment confirmation email helper ────────────────────────────
function sendPaymentConfirmation(PDO $pdo, int $orgId, array $inv, string $method = '', string $reference = ''): void {
    try {
        $adminRow = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='client_admin' LIMIT 1");
        $adminRow->execute([$orgId]);
        $admin = $adminRow->fetch();
        if (!$admin || !$admin['email']) return;

        $amount      = number_format((float)$inv['total'], 2);
        $methodLabel = $method ? ucwords(str_replace('_', ' ', $method)) : 'Online Payment';
        $refLine     = $reference ? "<p style='color:#666;font-size:.85rem'>Reference: <strong>{$reference}</strong></p>" : '';

        $body = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <div style='text-align:center;margin-bottom:24px'>
              <div style='display:inline-flex;align-items:center;justify-content:center;width:56px;height:56px;background:#E6F5EE;border-radius:50%;margin-bottom:10px'>
                <span style='font-size:1.6rem'>✅</span>
              </div>
              <h2 style='color:#1A8A4E;margin:0'>Payment Received</h2>
            </div>
            <p>Dear <strong>" . htmlspecialchars($admin['name']) . "</strong>,</p>
            <p>We have successfully received your payment. Here are the details:</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Invoice #</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>" . htmlspecialchars($inv['invoice_number']) . "</td></tr>
              <tr style='background:#f0f9f4'><td style='padding:8px;border:1px solid #eee;font-weight:700'>Amount Paid</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>KES {$amount}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Payment Method</td><td style='padding:8px;border:1px solid #eee'>{$methodLabel}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Date</td><td style='padding:8px;border:1px solid #eee'>" . date('d M Y, h:i A') . "</td></tr>
            </table>
            {$refLine}
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/client/billing.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                View Billing Portal →
              </a>
            </div>
            <p style='color:#64748b;font-size:.82rem'>Thank you for your prompt payment. Your services will remain active without interruption.</p>
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='color:#999;font-size:.8rem;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
          </div>
        </div>";

        mailer()->send($admin['email'], 'Payment Confirmed — Invoice ' . $inv['invoice_number'] . ' — ' . APP_NAME, $body);
    } catch (Exception $e) {
        error_log('[billing] Payment confirmation email failed: ' . $e->getMessage());
    }
}

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
        sendPaymentConfirmation($pdo, $orgId, $inv, $method, $reference);
    } else {
        setFlash('error', 'Invoice not found or already paid.');
    }
    redirect(APP_URL . '/client/billing.php?tab=invoices');
}

// ── POST: Pay invoice from wallet ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pay_from_wallet') {
    verifyCsrf();
    $invoiceId = (int)$_POST['invoice_id'];

    $inv = $pdo->prepare("SELECT * FROM invoices WHERE id=? AND org_id=? AND status IN ('draft','sent','overdue')");
    $inv->execute([$invoiceId, $orgId]);
    $inv = $inv->fetch();

    if (!$inv) {
        setFlash('danger', 'Invoice not found or already paid.');
        redirect(APP_URL . '/client/billing.php?tab=pay');
    }

    $total = (float)$inv['total'];

    $pdo->beginTransaction();
    try {
        $wb = $pdo->prepare("SELECT wallet_balance FROM organizations WHERE id=? FOR UPDATE");
        $wb->execute([$orgId]);
        $balance = (float)($wb->fetchColumn() ?: 0);

        if ($balance < $total) {
            $pdo->rollBack();
            setFlash('danger', 'Insufficient wallet balance. Please top up first.');
            redirect(APP_URL . '/client/billing.php?tab=wallet');
        }

        $newBal = $balance - $total;
        $pdo->prepare("UPDATE organizations SET wallet_balance=? WHERE id=?")->execute([$newBal, $orgId]);
        $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$invoiceId]);

        $pdo->prepare("INSERT INTO wallet_transactions (org_id,type,amount,balance_after,description,invoice_id,status) VALUES (?,?,?,?,?,?,'completed')")
            ->execute([$orgId, 'deduction', $total, $newBal, 'Invoice ' . $inv['invoice_number'], $invoiceId]);

        // Activate module if linked
        if (!empty($inv['module_id'])) {
            $subRow = $pdo->prepare("SELECT id FROM subscriptions WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
            $subRow->execute([$orgId]);
            $subId = $subRow->fetchColumn();
            if ($subId) {
                $pdo->prepare("INSERT INTO subscription_modules (subscription_id,module_id,status) VALUES (?,?,'active') ON DUPLICATE KEY UPDATE status='active'")
                    ->execute([$subId, $inv['module_id']]);
            }
            $mn = $pdo->prepare("SELECT name FROM modules WHERE id=?");
            $mn->execute([$inv['module_id']]);
            $modName = $mn->fetchColumn() ?: 'Module';
        }

        $pdo->commit();
        logActivity('wallet_payment', 'billing', "Invoice {$inv['invoice_number']} paid from wallet — KES {$total}. Balance: {$newBal}");
        sendPaymentConfirmation($pdo, $orgId, $inv, 'wallet');
        $msg = "Invoice <strong>{$inv['invoice_number']}</strong> paid from wallet.";
        if (!empty($modName)) $msg .= " <strong>{$modName}</strong> is now active.";
        setFlash('success', $msg);

    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', 'Payment failed. Please try again.');
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
        // Guard: do not create a duplicate if a pending upgrade invoice already exists for this plan+cycle
        $dupNotes = "Plan: {$plan['name']} ({$cycle})";
        $dupStmt  = $pdo->prepare("SELECT id FROM invoices WHERE org_id=? AND status IN ('draft','sent','overdue') AND notes=? LIMIT 1");
        $dupStmt->execute([$orgId, $dupNotes]);
        if ($dupRow = $dupStmt->fetch()) {
            setFlash('warning', "You already have a pending invoice for the <strong>{$plan['name']}</strong> ({$cycle}) plan. <a href='?tab=pay&inv={$dupRow['id']}' class='fw-bold'>Pay it now →</a>");
            redirect(APP_URL . '/client/billing.php?tab=plans');
        }

        $amount    = $cycle === 'annual' ? (float)$plan['price_annual'] : (float)$plan['price_monthly'];
        $tax       = round($amount * 0.16, 2);
        $total     = $amount + $tax;
        $invoiceNo = 'INV-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 8));
        $dueDate   = date('Y-m-d', strtotime('+7 days'));
        $pdo->prepare("INSERT INTO invoices (org_id, subscription_id, invoice_number, amount, tax, total, status, due_date, notes)
            VALUES (?,?,?,?,?,?,'sent',?,?)")->execute([
            $orgId, $sub['id'] ?? null, $invoiceNo, $amount, $tax, $total, $dueDate,
            $dupNotes
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
$pmtCfg = getSettings(['mpesa_paybill', 'mpesa_account_ref', 'bank_name', 'bank_account', 'bank_branch', 'support_email']);
require_once __DIR__ . '/../includes/header-client.php';

$plans = $pdo->query("SELECT * FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll();

// ── USD rate ──────────────────────────────────────────────────────
$usdRate = max(1, (float)(getSetting('usd_rate', '130') ?: 130));

// ── Usage limits (for overview tab banners) ───────────────────────
$usageLimits = checkUsageLimits($orgId);

// ── Data ─────────────────────────────────────────────────────────
$activeTab    = $_GET['tab'] ?? 'overview';
$highlightInv = (int)($_GET['inv'] ?? 0);

// ── Current plan — explicit columns to prevent alias collisions ───
$currentPlan = null;
if ($sub && !empty($sub['plan_id'])) {
    $cpStmt = $pdo->prepare("
        SELECT id, name, description,
               max_users, max_modules, is_popular,
               CAST(price_monthly AS DECIMAL(12,2)) AS price_monthly,
               CAST(price_annual  AS DECIMAL(12,2)) AS price_annual,
               status
        FROM subscription_plans WHERE id=? LIMIT 1
    ");
    $cpStmt->execute([(int)$sub['plan_id']]);
    $currentPlan = $cpStmt->fetch() ?: null;
}

// Validate plan prices — cap at 10 million to guard against bad data
if ($currentPlan) {
    $currentPlan['price_monthly'] = min((float)$currentPlan['price_monthly'], 9_999_999);
    $currentPlan['price_annual']  = min((float)$currentPlan['price_annual'],  9_999_999);
}

// ── All active modules (name + icon only — for display list) ──────
$activeModules = [];
if ($sub) {
    try {
        $amStmt = $pdo->prepare("
            SELECT m.name, m.icon, m.color, m.slug
            FROM modules m
            INNER JOIN subscription_modules sm ON m.id = sm.module_id
            WHERE sm.subscription_id = ? AND sm.status = 'active'
            ORDER BY m.sort_order, m.name
        ");
        $amStmt->execute([$sub['id']]);
        $activeModules = $amStmt->fetchAll();
    } catch (Exception $e) {}
}

// ── Add-on modules: individually purchased (have a paid invoice) ──
// These are the ACTUAL additional charges on top of the plan price.
$addonModules = [];
$totalAddonsPaid = 0.0;
try {
    $addStmt = $pdo->prepare("
        SELECT m.name, m.icon, m.color, i.amount, i.total,
               i.created_at, i.invoice_number, i.status
        FROM invoices i
        INNER JOIN modules m ON i.module_id = m.id
        WHERE i.org_id = ? AND i.status = 'paid'
        ORDER BY i.created_at DESC
    ");
    $addStmt->execute([$orgId]);
    $addonModules    = $addStmt->fetchAll();
    $totalAddonsPaid = array_sum(array_column($addonModules, 'total'));
} catch (Exception $e) {}

// ── Wallet data ───────────────────────────────────────────────────
$walletBalance = 0.00;
$walletTxns    = [];
try {
    $wb = $pdo->prepare("SELECT wallet_balance FROM organizations WHERE id=?");
    $wb->execute([$orgId]);
    $walletBalance = (float)($wb->fetchColumn() ?: 0);
} catch (Exception $e) {}

try {
    $wt = $pdo->prepare("SELECT * FROM wallet_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 50");
    $wt->execute([$orgId]);
    $walletTxns = $wt->fetchAll();
} catch (Exception $e) {}

// ── Invoice totals: dedicated aggregate query (no JOIN, no column bleed) ──────
$totalPaid    = 0.0;
$totalPending = 0.0;
$invoiceCount = 0;
try {
    $aggStmt = $pdo->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN status = 'paid'
                              THEN CAST(total AS DECIMAL(12,2)) ELSE 0 END), 0)            AS total_paid,
            COALESCE(SUM(CASE WHEN status IN ('draft','sent','overdue')
                              THEN CAST(total AS DECIMAL(12,2)) ELSE 0 END), 0)            AS total_pending,
            COUNT(*)                                                                        AS invoice_count
        FROM invoices
        WHERE org_id = ?
    ");
    $aggStmt->execute([$orgId]);
    $agg          = $aggStmt->fetch(PDO::FETCH_ASSOC);
    $totalPaid    = (float)($agg['total_paid']    ?? 0);
    $totalPending = (float)($agg['total_pending'] ?? 0);
    $invoiceCount = (int)($agg['invoice_count']   ?? 0);
} catch (Exception $e) {}

// ── Invoice list: explicit columns + CAST to prevent type confusion ──────────
$invoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            i.id,
            i.org_id,
            i.module_id,
            i.invoice_number,
            CAST(i.amount AS DECIMAL(12,2))  AS amount,
            CAST(i.tax    AS DECIMAL(12,2))  AS tax,
            CAST(i.total  AS DECIMAL(12,2))  AS total,
            i.status,
            i.due_date,
            i.paid_at,
            i.notes,
            i.created_at,
            m.name  AS module_name,
            m.icon  AS module_icon,
            m.color AS module_color
        FROM invoices i
        LEFT JOIN modules m ON i.module_id = m.id
        WHERE i.org_id = ?
        ORDER BY i.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$orgId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$unpaidInvoices = array_filter($invoices, fn($i) => in_array($i['status'], ['draft','sent','overdue']));
$statusColors   = ['draft'=>'secondary','sent'=>'info','paid'=>'success','overdue'=>'danger','cancelled'=>'dark'];

// Focus invoice for pay tab
$focusInv = null;
if ($highlightInv) {
    foreach ($invoices as $i) { if ($i['id'] === $highlightInv) { $focusInv = $i; break; } }
}
if (!$focusInv && $activeTab === 'pay' && !empty($unpaidInvoices)) {
    $focusInv = reset($unpaidInvoices);
}
?>

<style>
/* ── Billing page tab nav — OrbitDesk brand ─────────────────────── */
.billing-tabs { display:flex; gap:4px; background:#f0f4f8; border-radius:12px; padding:4px; margin-bottom:1.5rem; flex-wrap:wrap; }
.billing-tabs a {
  display:inline-flex; align-items:center; gap:6px;
  padding:.45rem 1rem; border-radius:9px; font-size:.84rem; font-weight:500;
  color:#475569; text-decoration:none; transition:all .18s; white-space:nowrap;
}
.billing-tabs a:hover { background:#fff; color:#0B2D4E; }
.billing-tabs a.active { background:#fff; color:#1A8A4E; font-weight:700;
  box-shadow:0 1px 6px rgba(0,0,0,.1); }
.billing-tabs a.active i { color:#1A8A4E; }
.billing-tabs .tab-badge { background:#f59e0b; color:#fff; border-radius:50px;
  font-size:.65rem; font-weight:700; padding:.1rem .42rem; margin-left:2px; }
.billing-tabs .tab-badge-green { background:#1A8A4E; color:#fff; border-radius:50px;
  font-size:.65rem; font-weight:700; padding:.1rem .42rem; margin-left:2px; }
@media(max-width:640px) { .billing-tabs a { font-size:.78rem; padding:.4rem .7rem; } }
</style>

<div class="page-header d-flex align-items-center justify-content-between mb-3">
  <div>
    <h4><i class="fas fa-file-invoice-dollar me-2 text-green"></i>Billing &amp; Subscription</h4>
    <p class="text-muted mb-0">Manage your plan, pay invoices, and activate modules</p>
  </div>
  <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-outline-primary btn-sm">
    <i class="fas fa-puzzle-piece me-1"></i>Browse Modules
  </a>
</div>

<!-- Tabs -->
<div class="billing-tabs">
  <a href="?tab=overview" class="<?= $activeTab==='overview' ? 'active' : '' ?>">
    <i class="fas fa-home"></i>Overview
  </a>
  <a href="?tab=invoices" class="<?= $activeTab==='invoices' ? 'active' : '' ?>">
    <i class="fas fa-file-invoice"></i>Invoices
    <?php if (count($unpaidInvoices)): ?><span class="tab-badge"><?= count($unpaidInvoices) ?></span><?php endif; ?>
  </a>
  <a href="?tab=pay" class="<?= $activeTab==='pay' ? 'active' : '' ?>">
    <i class="fas fa-credit-card"></i>Make Payment
  </a>
  <a href="?tab=plans" class="<?= $activeTab==='plans' ? 'active' : '' ?>">
    <i class="fas fa-layer-group"></i>Plans
  </a>
  <a href="?tab=wallet" class="<?= $activeTab==='wallet' ? 'active' : '' ?>">
    <i class="fas fa-wallet"></i>Wallet
    <?php if ($walletBalance > 0): ?><span class="tab-badge-green"><?= formatCurrency($walletBalance) ?></span><?php endif; ?>
  </a>
</div>

<!-- ═══════════════ OVERVIEW ═══════════════ -->
<?php if ($activeTab === 'overview'): ?>

<?php if ($sub && $sub['status'] === 'trial'): ?>
<?php
  $trialLeft = $sub['trial_ends_at'] ? max(0, (int)ceil((strtotime($sub['trial_ends_at']) - time()) / 86400)) : 0;
  $trialPct  = $sub['trial_ends_at'] ? max(0, min(100, round(((14 - $trialLeft) / 14) * 100))) : 100;
?>
<div class="card border-warning mb-4" style="border-width:2px!important">
  <div class="card-body py-3">
    <div class="d-flex align-items-center gap-3 flex-wrap">
      <div class="flex-shrink-0 text-warning" style="font-size:1.75rem"><i class="fas fa-hourglass-half"></i></div>
      <div class="flex-grow-1">
        <div class="fw-700 mb-1">
          <?= $trialLeft > 0 ? "Free trial — <strong>{$trialLeft} day" . ($trialLeft > 1 ? 's' : '') . " remaining</strong>" : "Your free trial <strong>ends today</strong>" ?>
        </div>
        <div class="progress" style="height:6px;max-width:320px">
          <div class="progress-bar bg-warning" style="width:<?= $trialPct ?>%"></div>
        </div>
        <div class="text-muted small mt-1">Trial ends <?= $sub['trial_ends_at'] ? date('d M Y', strtotime($sub['trial_ends_at'])) : '—' ?> · Upgrade to keep your workspace and data.</div>
      </div>
      <a href="?tab=plans" class="btn btn-warning fw-bold flex-shrink-0">
        <i class="fas fa-arrow-up me-1"></i>Upgrade Now
      </a>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
// ── Usage limits warning banners ─────────────────────────────────
$usersNear   = $usageLimits['users_max']   > 0 && $usageLimits['users_used']   >= $usageLimits['users_max']   * 0.9;
$modulesNear = $usageLimits['modules_max'] > 0 && $usageLimits['modules_used'] >= $usageLimits['modules_max'] * 0.9;
if ($usersNear || $modulesNear):
?>
<?php if ($usersNear): ?>
<div class="alert alert-<?= $usageLimits['users_ok'] ? 'warning' : 'danger' ?> alert-dismissible d-flex align-items-center gap-3 mb-3" role="alert">
  <i class="fas fa-users flex-shrink-0"></i>
  <div>
    <?php if (!$usageLimits['users_ok']): ?>
      You've reached your seat limit (<strong><?= $usageLimits['users_used'] ?> of <?= $usageLimits['users_max'] ?></strong> seats used).
      <a href="?tab=plans" class="fw-bold ms-1">Upgrade your plan to add more team members &rarr;</a>
    <?php else: ?>
      You're using <strong><?= $usageLimits['users_used'] ?> of <?= $usageLimits['users_max'] ?></strong> seats &mdash; almost at your limit.
      <a href="?tab=plans" class="fw-bold ms-1">Upgrade to add more team members &rarr;</a>
    <?php endif; ?>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($modulesNear): ?>
<div class="alert alert-<?= $usageLimits['modules_ok'] ? 'warning' : 'danger' ?> alert-dismissible d-flex align-items-center gap-3 mb-3" role="alert">
  <i class="fas fa-puzzle-piece flex-shrink-0"></i>
  <div>
    <?php if (!$usageLimits['modules_ok']): ?>
      You've reached your module limit (<strong><?= $usageLimits['modules_used'] ?> of <?= $usageLimits['modules_max'] ?></strong> module slots used).
      <a href="?tab=plans" class="fw-bold ms-1">Upgrade your plan for more modules &rarr;</a>
    <?php else: ?>
      You're using <strong><?= $usageLimits['modules_used'] ?> of <?= $usageLimits['modules_max'] ?></strong> module slots &mdash; almost at your limit.
      <a href="?tab=plans" class="fw-bold ms-1">Upgrade for more module slots &rarr;</a>
    <?php endif; ?>
  </div>
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php endif; ?>

<?php
// ── Subscription health metrics ───────────────────────────────────
$subDaysLeft  = 0;
$subTotalDays = 30;
$subHealthPct = 100;
$subHealthColor = '#1A8A4E';
$subRenewalDate = '—';
$subStatusLabel = 'No Subscription';

if ($sub) {
    $endField = $sub['ends_at'] ?: ($sub['trial_ends_at'] ?? null);
    if ($endField) {
        $subRenewalDate = date('d M Y', strtotime($endField));
        $subDaysLeft    = max(0, (int)ceil((strtotime($endField) - time()) / 86400));
        $isAnnualSub    = ($sub['billing_cycle'] ?? 'monthly') === 'annual';
        $subTotalDays   = $isAnnualSub ? 365 : 30;
        $subHealthPct   = max(0, min(100, round(($subDaysLeft / $subTotalDays) * 100)));
    }
    if ($sub['status'] === 'trial') {
        $subStatusLabel  = 'Free Trial';
        $subHealthColor  = '#f59e0b';
        $subTotalDays    = 14;
        $subDaysLeft     = $sub['trial_ends_at'] ? max(0, (int)ceil((strtotime($sub['trial_ends_at']) - time()) / 86400)) : 0;
        $subHealthPct    = max(0, min(100, round(($subDaysLeft / 14) * 100)));
    } elseif ($sub['status'] === 'active') {
        $subStatusLabel = 'Active';
        $subHealthColor = $subDaysLeft <= 5 ? '#ef4444' : ($subDaysLeft <= 14 ? '#f59e0b' : '#1A8A4E');
    } elseif ($sub['status'] === 'expired') {
        $subStatusLabel = 'Expired';
        $subHealthColor = '#ef4444';
        $subHealthPct   = 0;
    }
}
?>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalPaid) ?></div><div class="stat-label">Total Paid</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
      <div><div class="stat-value"><?= count($invoices) ?></div><div class="stat-label">Total Invoices</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <a href="?tab=wallet" class="stat-card text-decoration-none" style="border-left-color:<?= $walletBalance > 0 ? '#1A8A4E' : '#94a3b8' ?>">
      <div class="stat-icon" style="background:<?= $walletBalance > 0 ? 'rgba(26,138,78,.1)' : 'rgba(148,163,184,.1)' ?>;color:<?= $walletBalance > 0 ? '#1A8A4E' : '#94a3b8' ?>"><i class="fas fa-wallet"></i></div>
      <div>
        <div class="stat-value" style="font-size:1.1rem"><?= formatCurrency($walletBalance) ?></div>
        <div class="stat-label">Wallet Balance</div>
      </div>
    </a>
  </div>
</div>

<?php if (count($unpaidInvoices) > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
  <i class="fas fa-exclamation-circle fa-lg flex-shrink-0"></i>
  <div class="flex-grow-1">
    You have <strong><?= count($unpaidInvoices) ?> unpaid invoice<?= count($unpaidInvoices)>1?'s':'' ?></strong>
    totalling <strong><?= formatCurrency($totalPending) ?></strong>.
  </div>
  <a href="?tab=pay" class="btn btn-warning btn-sm fw-bold flex-shrink-0">Pay Now →</a>
</div>
<?php endif; ?>

<!-- ── Subscription Health Card ───────────────────────────────────── -->
<?php if ($sub): ?>
<div class="card mb-4" style="border-left:4px solid <?= $subHealthColor ?>">
  <div class="card-body py-3">
    <div class="row align-items-center g-3">
      <div class="col-auto">
        <div style="width:48px;height:48px;border-radius:12px;background:<?= $subHealthColor ?>1a;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:<?= $subHealthColor ?>">
          <i class="fas fa-<?= $sub['status']==='trial' ? 'hourglass-half' : ($sub['status']==='expired' ? 'times-circle' : 'shield-check') ?>"></i>
        </div>
      </div>
      <div class="col">
        <div class="d-flex align-items-center gap-2 mb-1">
          <span class="fw-bold" style="color:<?= $subHealthColor ?>"><?= e($sub['plan_name'] ?? 'Subscription') ?></span>
          <span class="badge rounded-pill" style="background:<?= $subHealthColor ?>20;color:<?= $subHealthColor ?>;border:1px solid <?= $subHealthColor ?>40;font-size:.7rem">
            <?= $subStatusLabel ?>
          </span>
        </div>
        <?php if ($sub['status'] !== 'expired'): ?>
        <div class="progress mb-1" style="height:6px;border-radius:6px;background:#e2e8f0">
          <div class="progress-bar" style="width:<?= $subHealthPct ?>%;background:<?= $subHealthColor ?>;border-radius:6px;transition:width .6s ease"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center">
          <span class="text-muted" style="font-size:.75rem">
            <i class="fas fa-calendar-alt me-1"></i>
            Renews <strong><?= $subRenewalDate ?></strong>
          </span>
          <span class="fw-semibold" style="font-size:.78rem;color:<?= $subHealthColor ?>">
            <?= $subDaysLeft ?> day<?= $subDaysLeft !== 1 ? 's' : '' ?> remaining
          </span>
        </div>
        <?php else: ?>
        <div class="text-danger small">Your subscription has expired. Renew to restore access to your modules.</div>
        <?php endif; ?>
      </div>
      <div class="col-auto d-flex gap-2">
        <?php if ($sub['status'] === 'trial' || $subDaysLeft <= 14): ?>
        <a href="?tab=plans" class="btn btn-sm fw-bold" style="background:<?= $subHealthColor ?>;color:#fff;border:none">
          <i class="fas fa-arrow-up me-1"></i><?= $sub['status']==='expired' ? 'Renew' : 'Upgrade' ?>
        </a>
        <?php else: ?>
        <a href="?tab=plans" class="btn btn-sm btn-outline-secondary">View Plans</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header fw-bold d-flex align-items-center justify-content-between">
        <span><i class="fas fa-receipt text-green me-2"></i>Charges Summary</span>
        <?php if ($sub): ?><?= statusBadge($sub['status']) ?><?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($sub && $currentPlan): ?>

        <?php
          $isAnnual    = ($sub['billing_cycle'] ?? 'monthly') === 'annual';
          $planPrice   = $isAnnual ? (float)$currentPlan['price_annual'] : (float)$currentPlan['price_monthly'];
          $planPriceUsd= $planPrice > 0 ? round($planPrice / $usdRate, 2) : 0;
          $cycleLabel  = $isAnnual ? '/yr' : '/mo';
          $nextDate    = $sub['ends_at'] ? formatDate($sub['ends_at']) : ($sub['trial_ends_at'] ? formatDate($sub['trial_ends_at']) : '—');
        ?>

        <!-- ── Plan charge ─────────────────────────────── -->
        <div class="px-3 pt-3 pb-2 border-bottom">
          <div class="d-flex align-items-start justify-content-between gap-2">
            <div>
              <div class="fw-bold text-navy" style="font-size:.9rem"><?= e($currentPlan['name']) ?> Plan</div>
              <div class="text-muted" style="font-size:.72rem">
                <?= ucfirst($sub['billing_cycle'] ?? 'monthly') ?> billing
                &bull; <?= (int)$currentPlan['max_users'] ?> users
                &bull; up to <?= (int)$currentPlan['max_modules'] ?> modules
              </div>
            </div>
            <div class="text-end flex-shrink-0">
              <div class="fw-bold text-green" style="font-size:.92rem;white-space:nowrap">
                KES <?= number_format($planPrice, 0) ?>
                <span class="text-muted fw-normal" style="font-size:.7rem"><?= $cycleLabel ?></span>
              </div>
              <?php if ($planPriceUsd > 0 && $usdRate > 1): ?>
              <div class="text-muted" style="font-size:.66rem;white-space:nowrap">
                (≈ $ <?= number_format($planPriceUsd, 2) ?> USD)
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- ── Active modules: names only (covered by plan fee) ─── -->
        <?php if (!empty($activeModules)): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="text-muted fw-semibold" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em">
              Active Modules
            </span>
            <span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25">
              <?= count($activeModules) ?> active
            </span>
          </div>
          <div class="d-flex flex-wrap gap-1">
            <?php foreach ($activeModules as $am): ?>
            <span class="d-inline-flex align-items-center gap-1 px-2 py-1 rounded-pill"
                  style="background:<?= e($am['color']) ?>15;border:1px solid <?= e($am['color']) ?>40;font-size:.68rem;color:<?= e($am['color']) ?>">
              <i class="<?= e($am['icon']) ?>" style="font-size:.6rem"></i>
              <?= e($am['name']) ?>
            </span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- ── Add-on modules actually paid ────────────────────── -->
        <?php if (!empty($addonModules)): ?>
        <div class="px-3 py-2 border-bottom">
          <div class="text-muted fw-semibold mb-2" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.05em">
            Paid Add-Ons
          </div>
          <?php foreach ($addonModules as $ad):
            $adTotal    = (float)$ad['total'];
            $adTotalUsd = $adTotal > 0 ? round($adTotal / $usdRate, 2) : 0;
          ?>
          <div class="d-flex align-items-center justify-content-between py-1">
            <div class="d-flex align-items-center gap-2">
              <div style="width:20px;height:20px;border-radius:5px;background:<?= e($ad['color']) ?>1a;
                          color:<?= e($ad['color']) ?>;display:flex;align-items:center;justify-content:center;
                          font-size:.6rem;flex-shrink:0">
                <i class="<?= e($ad['icon']) ?>"></i>
              </div>
              <div>
                <span class="small"><?= e($ad['name']) ?></span>
                <div class="text-muted" style="font-size:.67rem"><?= formatDate($ad['created_at']) ?> · <?= e($ad['invoice_number']) ?></div>
              </div>
            </div>
            <div class="text-end">
              <span class="fw-semibold small text-success"><?= formatCurrency($adTotal) ?></span>
              <div class="text-muted" style="font-size:.67rem">≈ $ <?= number_format($adTotalUsd, 2) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Next billing / renewal ───────────────────────────── -->
        <?php
          $taxEst       = round($planPrice * 0.16, 2);
          $planTotal    = $planPrice + $taxEst;
          $planTotalUsd = ($planTotal > 0 && $usdRate > 0) ? round($planTotal / $usdRate, 2) : 0;
        ?>
        <div class="px-3 py-3 bg-light">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="small text-muted">Plan subtotal</span>
            <span class="small fw-semibold" style="white-space:nowrap">
              KES <?= number_format($planPrice, 0) ?><?= $cycleLabel ?>
            </span>
          </div>
          <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="small text-muted">VAT est. (16%)</span>
            <span class="small text-muted" style="white-space:nowrap">KES <?= number_format($taxEst, 0) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center border-top pt-2">
            <span class="fw-bold small">Total<?= $cycleLabel ?></span>
            <div class="text-end">
              <span class="fw-bold text-green" style="font-size:.92rem;white-space:nowrap">KES <?= number_format($planTotal, 0) ?></span>
              <?php if ($planTotalUsd > 0 && $usdRate > 1): ?>
              <div class="text-muted" style="font-size:.68rem;white-space:nowrap">≈ USD <?= number_format($planTotalUsd, 2) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top">
            <span class="small text-muted"><i class="fas fa-calendar-alt me-1"></i>Next renewal</span>
            <span class="small fw-semibold"><?= $nextDate ?></span>
          </div>
        </div>

        <div class="px-3 py-2">
          <a href="?tab=plans" class="btn btn-sm btn-outline-primary w-100">
            <i class="fas fa-arrow-up me-1"></i>Upgrade Plan
          </a>
        </div>

        <?php elseif ($sub): ?>
        <!-- Subscription exists but plan not found -->
        <div class="p-3">
          <div class="text-center mb-3">
            <div class="fw-bold text-navy fs-5"><?= e($sub['plan_name'] ?? 'Custom Plan') ?></div>
            <?= statusBadge($sub['status']) ?>
          </div>
          <table class="table table-sm table-borderless">
            <tr><td class="text-muted small">Billing cycle</td><td class="fw-bold text-capitalize small"><?= e($sub['billing_cycle'] ?? '—') ?></td></tr>
            <tr><td class="text-muted small">Amount</td><td class="fw-bold text-green small"><?= formatCurrency((float)$sub['amount']) ?></td></tr>
            <?php if ($sub['ends_at']): ?>
            <tr><td class="text-muted small">Renews</td><td class="small"><?= formatDate($sub['ends_at']) ?></td></tr>
            <?php endif; ?>
          </table>
          <a href="?tab=plans" class="btn btn-sm btn-outline-primary w-100">Upgrade Plan</a>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted px-3">
          <i class="fas fa-layer-group fa-3x mb-3 d-block opacity-25"></i>
          <div class="fw-semibold mb-1">No active subscription</div>
          <div class="small mb-3">Choose a plan to unlock your workspace.</div>
          <a href="?tab=plans" class="btn btn-primary btn-sm"><i class="fas fa-layer-group me-1"></i>Choose a Plan</a>
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

<?php
$totalInv    = count($invoices);
$unpaidCnt   = count(array_filter($invoices, fn($i) => in_array($i['status'],['draft','sent','overdue'])));
$paidCnt     = count(array_filter($invoices, fn($i) => $i['status']==='paid'));
$overdueCnt  = count(array_filter($invoices, fn($i) => $i['status']==='overdue'));
?>
<style>
.inv-filter-chips { display:flex; gap:6px; flex-wrap:wrap; }
.inv-chip { display:inline-flex; align-items:center; gap:5px; padding:.3rem .85rem; border-radius:50px;
  font-size:.78rem; font-weight:600; cursor:pointer; border:1.5px solid #e2e8f0;
  background:#fff; color:#475569; transition:all .15s; user-select:none; }
.inv-chip:hover { border-color:#1A8A4E; color:#1A8A4E; }
.inv-chip.active { background:#1A8A4E; color:#fff; border-color:#1A8A4E; }
.inv-chip.active-warn { background:#f59e0b; color:#fff; border-color:#f59e0b; }
.inv-chip.active-danger { background:#ef4444; color:#fff; border-color:#ef4444; }
.inv-chip .chip-count { background:rgba(0,0,0,.12); border-radius:50px; padding:0 .35rem; font-size:.7rem; }
</style>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <span class="fw-bold"><i class="fas fa-file-invoice text-green me-2"></i>All Invoices</span>
    <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-sm btn-outline-primary">
      <i class="fas fa-plus me-1"></i>Add Module
    </a>
  </div>

  <?php if (!empty($invoices)): ?>
  <div class="px-3 py-2 border-bottom" style="background:#f8fafc">
    <div class="inv-filter-chips">
      <span class="inv-chip active" data-filter="all" onclick="filterInvoices('all',this)">
        <i class="fas fa-list"></i>All <span class="chip-count"><?= $totalInv ?></span>
      </span>
      <span class="inv-chip" data-filter="unpaid" onclick="filterInvoices('unpaid',this)">
        <i class="fas fa-clock"></i>Unpaid <span class="chip-count"><?= $unpaidCnt ?></span>
      </span>
      <span class="inv-chip" data-filter="paid" onclick="filterInvoices('paid',this)">
        <i class="fas fa-check-circle"></i>Paid <span class="chip-count"><?= $paidCnt ?></span>
      </span>
      <?php if ($overdueCnt > 0): ?>
      <span class="inv-chip" data-filter="overdue" onclick="filterInvoices('overdue',this)">
        <i class="fas fa-exclamation-circle"></i>Overdue <span class="chip-count"><?= $overdueCnt ?></span>
      </span>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card-body p-0">
    <?php if (empty($invoices)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-file-invoice fa-3x mb-2 d-block"></i>
      No invoices yet. <a href="<?= APP_URL ?>/client/modules.php">Add a module</a> to generate your first invoice.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="invoiceTable">
        <thead class="table-light">
          <tr>
            <th>Invoice #</th>
            <th>Module / Description</th>
            <th>Amount</th>
            <th>Total</th>
            <th>Due Date</th>
            <th>Paid Date</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody id="invTableBody">
          <?php foreach ($invoices as $inv):
            $sc = $statusColors[$inv['status']] ?? 'secondary';
            $isHighlighted = ($inv['id'] === $highlightInv);
            $isUnpaid = in_array($inv['status'], ['draft','sent','overdue']);
          ?>
          <tr class="inv-row <?= $isHighlighted ? 'table-warning' : '' ?>"
              data-status="<?= e($inv['status']) ?>"
              data-group="<?= $isUnpaid ? 'unpaid' : $inv['status'] ?>">
            <td class="fw-bold small"><?= e($inv['invoice_number']) ?></td>
            <td>
              <?php if ($inv['module_name']): ?>
              <span class="badge text-white" style="background:<?= e($inv['module_color'] ?? '#555') ?>">
                <i class="<?= e($inv['module_icon'] ?? 'fas fa-cube') ?> me-1"></i><?= e($inv['module_name']) ?>
              </span>
              <?php else: ?>
              <span class="text-muted small"><?= e(substr($inv['notes'] ?? '—', 0, 45)) ?></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= formatCurrency((float)$inv['amount']) ?>
              <span class="text-muted" style="font-size:.7rem"> +VAT</span>
            </td>
            <td class="fw-bold text-navy"><?= formatCurrency((float)$inv['total']) ?></td>
            <td class="small <?= $inv['status']==='overdue' ? 'text-danger fw-bold' : '' ?>">
              <?= formatDate($inv['due_date']) ?>
            </td>
            <td class="small text-muted"><?= $inv['paid_at'] ? formatDate($inv['paid_at']) : '—' ?></td>
            <td>
              <?php
                $badgeStyle = match($inv['status']) {
                    'paid'      => 'background:#e8f5ef;color:#1A8A4E;border:1px solid #c6e8d7',
                    'overdue'   => 'background:#fef2f2;color:#ef4444;border:1px solid #fecaca',
                    'sent'      => 'background:#eff6ff;color:#3b82f6;border:1px solid #bfdbfe',
                    default     => 'background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0',
                };
              ?>
              <span class="badge" style="<?= $badgeStyle ?>;font-size:.7rem"><?= strtoupper($inv['status']) ?></span>
            </td>
            <td class="text-center">
              <div class="d-flex gap-1 justify-content-center">
                <?php if ($isUnpaid): ?>
                <a href="?tab=pay&inv=<?= $inv['id'] ?>" class="btn btn-success btn-sm px-2 py-1" style="font-size:.75rem">
                  <i class="fas fa-credit-card me-1"></i>Pay
                </a>
                <?php endif; ?>
                <a href="<?= APP_URL ?>/client/invoice-pdf.php?id=<?= $inv['id'] ?>" target="_blank"
                   class="btn btn-outline-secondary btn-sm px-2 py-1" title="Download PDF" style="font-size:.75rem">
                  <i class="fas fa-download"></i>
                </a>
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

<style>
/* ── Payment step indicator ───────────────────── */
.pay-steps { display:flex; align-items:flex-start; gap:0; margin-bottom:1.5rem; }
.pay-step { flex:1; display:flex; flex-direction:column; align-items:center; position:relative; }
.pay-step:not(:last-child)::after {
  content:''; position:absolute; top:18px; left:calc(50% + 18px);
  width:calc(100% - 36px); height:2px;
  background:#e2e8f0; z-index:0;
}
.pay-step.done:not(:last-child)::after { background:#1A8A4E; }
.pay-step-circle {
  width:36px; height:36px; border-radius:50%; display:flex; align-items:center;
  justify-content:center; font-size:.85rem; font-weight:700; z-index:1; position:relative;
  transition:all .2s;
}
.pay-step.pending .pay-step-circle { background:#e2e8f0; color:#94a3b8; }
.pay-step.active  .pay-step-circle { background:#0B2D4E; color:#fff; box-shadow:0 0 0 4px rgba(11,45,78,.15); }
.pay-step.done    .pay-step-circle { background:#1A8A4E; color:#fff; }
.pay-step-label { font-size:.72rem; font-weight:600; margin-top:6px; text-align:center;
  color:#94a3b8; line-height:1.2; }
.pay-step.active .pay-step-label { color:#0B2D4E; }
.pay-step.done   .pay-step-label { color:#1A8A4E; }
</style>

<!-- Step indicator -->
<div class="pay-steps">
  <div class="pay-step <?= $focusInv ? 'done' : 'active' ?>">
    <div class="pay-step-circle"><?= $focusInv ? '<i class="fas fa-check"></i>' : '1' ?></div>
    <div class="pay-step-label">Select<br>Invoice</div>
  </div>
  <div class="pay-step <?= $focusInv ? 'active' : 'pending' ?>">
    <div class="pay-step-circle">2</div>
    <div class="pay-step-label">Choose<br>Method</div>
  </div>
  <div class="pay-step pending">
    <div class="pay-step-circle">3</div>
    <div class="pay-step-label">Confirm<br>&amp; Pay</div>
  </div>
</div>

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
            $isSelected = $focusInv && $focusInv['id'] === $inv['id'];
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

    <!-- Promo code card -->
    <div class="card mb-3" id="promoCard">
      <div class="card-body py-3">
        <h6 class="fw-semibold mb-2"><i class="fas fa-tag text-green me-2"></i>Have a promo code?</h6>
        <div class="d-flex gap-2">
          <input type="text" id="promoCodeInput" class="form-control form-control-sm text-uppercase fw-bold"
                 placeholder="e.g. SAVE20" maxlength="50" style="letter-spacing:1px;font-family:monospace;max-width:220px"
                 oninput="this.value=this.value.toUpperCase()">
          <button type="button" class="btn btn-sm btn-outline-success" onclick="applyPromoCode()" id="applyPromoBtn">
            <i class="fas fa-check me-1"></i>Apply
          </button>
          <button type="button" class="btn btn-sm btn-outline-secondary d-none" onclick="removePromoCode()" id="removePromoBtn">
            <i class="fas fa-times me-1"></i>Remove
          </button>
        </div>
        <div id="promoMsg" class="mt-2 small"></div>
        <input type="hidden" id="appliedPromoId" value="">
        <input type="hidden" id="appliedDiscount" value="0">
      </div>
    </div>

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
          <tr id="promoDiscountRow" class="d-none text-success">
            <td><i class="fas fa-tag me-1"></i>Promo Discount</td>
            <td class="text-end fw-semibold" id="promoDiscountDisplay">— KES 0.00</td>
          </tr>
          <tr class="border-top">
            <td class="fw-bold">Total Due</td>
            <td class="text-end fw-800 text-green fs-5">
              <span id="invoiceTotalDisplay"><?= formatCurrency((float)$focusInv['total']) ?></span>
            </td>
          </tr>
        </table>
      </div>
    </div>

    <!-- Payment options -->
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-credit-card text-green me-2"></i>Choose Payment Method</div>
      <div class="card-body">

        <!-- Wallet -->
        <?php if ($walletBalance >= (float)$focusInv['total']): ?>
        <div class="border border-success rounded p-3 mb-3" style="background:#f0fdf4">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <div class="fw-bold"><i class="fas fa-wallet text-success me-2"></i>Pay from Wallet</div>
            <span class="badge bg-success">Balance: <?= formatCurrency($walletBalance) ?></span>
          </div>
          <p class="text-muted small mb-3">Deduct <?= formatCurrency((float)$focusInv['total']) ?> from your wallet balance instantly — no M-Pesa prompt needed.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="pay_from_wallet">
            <input type="hidden" name="invoice_id" value="<?= $focusInv['id'] ?>">
            <input type="hidden" name="promo_id" id="walletPromoId" value="">
            <button type="submit" class="btn btn-success w-100 fw-bold"
                    onclick="return confirm('Pay <?= formatCurrency((float)$focusInv['total']) ?> from your wallet balance?')">
              <i class="fas fa-wallet me-2"></i>Pay <?= formatCurrency((float)$focusInv['total']) ?> from Wallet
            </button>
          </form>
        </div>
        <?php elseif ($walletBalance > 0): ?>
        <div class="border rounded p-3 mb-3" style="background:#fefce8">
          <div class="fw-bold mb-1"><i class="fas fa-wallet text-warning me-2"></i>Wallet Balance: <?= formatCurrency($walletBalance) ?></div>
          <p class="text-muted small mb-2">Your wallet balance is insufficient for this invoice (<?= formatCurrency((float)$focusInv['total']) ?>).
            <a href="?tab=wallet" class="fw-bold">Top up →</a>
          </p>
        </div>
        <?php else: ?>
        <div class="border rounded p-3 mb-3" style="background:#f8fafc">
          <div class="fw-bold mb-1 text-muted"><i class="fas fa-wallet me-2"></i>Wallet: KES 0.00</div>
          <p class="text-muted small mb-0"><a href="?tab=wallet" class="fw-bold">Load your wallet</a> to pay invoices instantly without entering M-Pesa PIN each time.</p>
        </div>
        <?php endif; ?>

        <!-- M-Pesa -->
        <div class="border rounded p-3 mb-3">
          <div class="fw-bold mb-2"><i class="fas fa-mobile-alt text-success me-2"></i>KopoKopo M-Pesa STK Push</div>
          <div class="mb-2">
            <label class="form-label small fw-semibold">M-Pesa Phone Number</label>
            <input type="tel" id="mpesaPhone" class="form-control"
                   placeholder="+254 700 000 000"
                   value="<?= e(preg_replace('/[^0-9+]/', '', $user['phone'] ?? '')) ?>">
          </div>
          <button class="btn btn-success w-100"
                  onclick="initiateMpesa(<?= $focusInv['id'] ?>, <?= $focusInv['total'] ?>)">
            <i class="fas fa-mobile-alt me-2"></i>Pay via KopoKopo M-Pesa — <?= formatCurrency((float)$focusInv['total']) ?>
          </button>
          <?php if (!empty($pmtCfg['mpesa_paybill'])): ?>
          <p class="text-muted small text-center mt-2 mb-0">
            Paybill: <strong><?= e($pmtCfg['mpesa_paybill']) ?></strong>
            · Account: <strong><?= e(!empty($pmtCfg['mpesa_account_ref']) ? $pmtCfg['mpesa_account_ref'] : $user['email']) ?></strong>
          </p>
          <?php endif; ?>
        </div>

        <!-- Cash / Bank Transfer -->
        <div class="border rounded p-3">
          <div class="fw-bold mb-2"><i class="fas fa-money-bill-wave text-primary me-2"></i>Cash / Bank Transfer</div>
          <p class="text-muted small mb-3">Have you already paid? Record the payment here and your module will be activated immediately.</p>
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="record_payment">
            <input type="hidden" name="invoice_id" value="<?= $focusInv['id'] ?>">
            <input type="hidden" name="promo_id" id="cashPromoId" value="">
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

<style>
/* ── Plan card cycle toggle ───────────────────── */
.cycle-toggle { display:inline-flex; background:#f1f5f9; border:1.5px solid #e2e8f0; border-radius:999px; padding:3px; gap:2px; }
.cycle-toggle button { border:none; padding:.32rem 1.1rem; font-size:.82rem; font-weight:700; cursor:pointer; border-radius:999px; transition:all .18s; background:transparent; color:#64748b; }
.cycle-toggle button.active { background:#0B2D4E; color:#fff; box-shadow:0 1px 4px rgba(0,0,0,.18); }
.cycle-toggle button.active.annual { background:#1A8A4E; }
.plan-price-display { transition:opacity .15s; }
</style>

<!-- Header: title + billing-cycle toggle + currency toggle -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
  <div>
    <h6 class="fw-bold text-navy mb-0">Choose Your Plan</h6>
    <div class="text-muted small">VAT (16%) added at checkout. Cancel or change plan anytime.</div>
  </div>
  <div class="d-flex align-items-center gap-3 flex-wrap">
    <!-- Billing cycle toggle -->
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted fw-semibold">Billing:</span>
      <div class="cycle-toggle" id="cycleToggle">
        <button id="cycleBtnMonthly" class="active" onclick="setPlanCycle('monthly')">Monthly</button>
        <button id="cycleBtnAnnual"  class="annual" onclick="setPlanCycle('annual')">
          Annual <span class="badge bg-success ms-1" style="font-size:.6rem;font-weight:700" id="annualSaveBadge">Save up to 17%</span>
        </button>
      </div>
    </div>
    <!-- Currency toggle -->
    <div class="d-flex align-items-center gap-2">
      <span class="small text-muted">Currency:</span>
      <div style="display:inline-flex;background:#f1f5f9;border:1.5px solid #e2e8f0;border-radius:999px;overflow:hidden">
        <button id="planBtnKES" onclick="setPlanCurrency('KES')"
                style="border:none;padding:.28rem .85rem;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .18s;background:#0B2D4E;color:#fff">KES</button>
        <button id="planBtnUSD" onclick="setPlanCurrency('USD')"
                style="border:none;padding:.28rem .85rem;font-size:.75rem;font-weight:700;cursor:pointer;transition:all .18s;background:transparent;color:#64748b">$ USD</button>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 justify-content-center">
  <?php foreach ($plans as $p):
    $isCurrent  = $sub && $sub['plan_id'] == $p['id'];
    $kesMo      = (float)$p['price_monthly'];
    $kesAnn     = (float)$p['price_annual'];
    $usdMo      = $kesMo  > 0 ? round($kesMo  / $usdRate, 2) : 0;
    $usdAnn     = $kesAnn > 0 ? round($kesAnn / $usdRate, 2) : 0;
    $savePct    = $kesMo > 0 && $kesAnn > 0 ? max(0, round((1 - $kesAnn / (12 * $kesMo)) * 100)) : 0;
  ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 <?= $isCurrent ? 'border-success border-2 shadow-sm' : ($p['is_popular'] ? 'border-2 shadow' : '') ?>"
         style="<?= $p['is_popular'] && !$isCurrent ? 'border-color:#1A8A4E!important' : '' ?>">

      <!-- Top ribbon -->
      <?php if ($isCurrent): ?>
      <div class="text-center py-1 fw-bold text-white" style="font-size:.7rem;border-radius:.375rem .375rem 0 0;background:#1A8A4E">
        <i class="fas fa-check me-1"></i>YOUR CURRENT PLAN
      </div>
      <?php elseif ($p['is_popular']): ?>
      <div class="text-center py-1 fw-bold text-white" style="font-size:.7rem;border-radius:.375rem .375rem 0 0;background:linear-gradient(90deg,#0B2D4E,#1A8A4E)">
        ⭐ MOST POPULAR
      </div>
      <?php endif; ?>

      <div class="card-body text-center d-flex flex-column px-4">
        <h5 class="fw-bold text-navy mb-1 mt-2"><?= e($p['name']) ?></h5>
        <p class="text-muted small mb-3"><?= e($p['description']) ?></p>

        <!-- Price display — switches via cycle + currency JS -->
        <div class="plan-price-display mb-1" id="priceBlock<?= $p['id'] ?>">
          <span class="fw-bold text-green" style="font-size:2.2rem;line-height:1"
                id="planPrice<?= $p['id'] ?>"
                data-kes-mo="KES <?= number_format($kesMo, 0) ?>"
                data-kes-ann="KES <?= number_format($kesAnn, 0) ?>"
                data-usd-mo="$ <?= number_format($usdMo, 2) ?>"
                data-usd-ann="$ <?= number_format($usdAnn, 2) ?>">
            KES <?= number_format($kesMo, 0) ?>
          </span>
          <span class="text-muted small" id="planCycleLabel<?= $p['id'] ?>">/mo</span>
        </div>
        <?php if ($savePct > 0): ?>
        <div class="mb-3" id="saveRow<?= $p['id'] ?>" style="display:none">
          <span class="badge bg-success px-2">Save <?= $savePct ?>% vs monthly</span>
        </div>
        <div class="mb-3" id="noSaveRow<?= $p['id'] ?>">
          <span class="text-muted" style="font-size:.75rem">or KES <?= number_format($kesAnn, 0) ?>/yr with annual plan</span>
        </div>
        <?php else: ?>
        <div class="mb-3"></div>
        <?php endif; ?>

        <ul class="list-unstyled text-start mb-4 small flex-grow-1">
          <li class="mb-2"><i class="fas fa-users text-green me-2"></i>Up to <strong><?= $p['max_users'] ?></strong> team members</li>
          <li class="mb-2"><i class="fas fa-puzzle-piece text-green me-2"></i>Up to <strong><?= $p['max_modules'] ?></strong> modules</li>
          <li class="mb-2"><i class="fas fa-check text-green me-2"></i>All core features included</li>
          <li class="mb-2"><i class="fas fa-envelope text-green me-2"></i>Email &amp; chat support</li>
          <?php if ($p['is_popular']): ?>
          <li class="mb-2"><i class="fas fa-star text-green me-2"></i>Priority support</li>
          <li class="mb-2"><i class="fas fa-chart-line text-green me-2"></i>Advanced analytics</li>
          <?php endif; ?>
        </ul>

        <?php if ($isCurrent): ?>
        <div class="rounded-3 p-3 mb-3 text-start" style="background:#e8f5ef;border:1px solid #c6e8d7">
          <div class="fw-semibold text-green small"><i class="fas fa-check-circle me-1"></i>Active — <?= ucfirst($sub['billing_cycle'] ?? 'monthly') ?> billing</div>
          <?php if ($sub['ends_at']): ?>
          <div class="text-muted small mt-1"><i class="fas fa-calendar-alt me-1"></i>Renews <?= formatDate($sub['ends_at']) ?></div>
          <?php endif; ?>
        </div>
        <button class="btn btn-outline-success w-100" disabled>
          <i class="fas fa-check me-1"></i>Your Current Plan
        </button>
        <?php else: ?>
        <form method="POST" class="mt-auto" id="planForm<?= $p['id'] ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="request_upgrade">
          <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
          <input type="hidden" name="billing_cycle" value="monthly" id="planCycleInput<?= $p['id'] ?>">
          <button type="submit" class="btn w-100 fw-bold"
                  style="background:<?= $p['is_popular'] ? '#1A8A4E' : '#0B2D4E' ?>;color:#fff"
                  id="planBtn<?= $p['id'] ?>">
            Get Started — <span id="planBtnLabel<?= $p['id'] ?>">Monthly</span>
          </button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (empty($plans)): ?>
<div class="text-center py-5 text-muted">
  <i class="fas fa-layer-group fa-3x mb-3 d-block opacity-25"></i>
  No active plans available. Contact support.
</div>
<?php endif; ?>

<!-- ═══════════════ WALLET ═══════════════ -->
<?php elseif ($activeTab === 'wallet'): ?>

<div class="row g-4">
  <!-- Left: Balance + Top-up -->
  <div class="col-lg-5">

    <!-- Balance card — always shown, even at 0 -->
    <div class="card mb-3 border-0 text-white"
         style="background:linear-gradient(135deg,#0B2D4E 0%,#1A8A4E 100%)">
      <div class="card-body text-center py-4">
        <div class="mb-2" style="font-size:.82rem;opacity:.75">
          <i class="fas fa-wallet me-1"></i>Available Wallet Balance
        </div>
        <?php if ($walletBalance <= 0): ?>
        <!-- Zero-state: explicit KES 0.00 display -->
        <div id="walletBalanceDisplay" style="font-size:2.6rem;font-weight:900;letter-spacing:-1.5px;opacity:.6">
          KES 0.00
        </div>
        <div style="font-size:.78rem;opacity:.65;margin-top:.3rem">
          <i class="fas fa-info-circle me-1"></i>No balance — top up below to pay invoices instantly
        </div>
        <?php else: ?>
        <div id="walletBalanceDisplay" style="font-size:2.6rem;font-weight:900;letter-spacing:-1.5px">
          <?= formatCurrency($walletBalance) ?>
        </div>
        <div style="font-size:.78rem;opacity:.75;margin-top:.3rem">
          ≈ $ <?= number_format(round($walletBalance / $usdRate, 2), 2) ?> · Available for invoice payments
        </div>
        <?php endif; ?>
      </div>

      <?php if ($walletBalance > 0): ?>
      <!-- Quick-pay banner when balance is sufficient -->
      <div class="px-3 pb-3">
        <a href="?tab=pay" class="btn btn-light btn-sm w-100 fw-semibold" style="color:#0B2D4E">
          <i class="fas fa-bolt me-1"></i>Pay Invoice from Wallet
        </a>
      </div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-plus-circle text-green me-2"></i>Top Up via M-Pesa</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label fw-semibold">Amount (KES)</label>
          <div class="d-flex gap-2 flex-wrap mb-2">
            <?php foreach ([500, 1000, 2000, 5000, 10000] as $preset): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary preset-btn"
                    onclick="setTopupAmount(<?= $preset ?>)">
              KES <?= number_format($preset) ?>
            </button>
            <?php endforeach; ?>
          </div>
          <input type="number" id="topupAmount" class="form-control" min="10" max="150000"
                 placeholder="Enter amount in KES…" oninput="updateTopupBtn()">
          <div class="form-text" id="topupUsdHint">Min KES 10 · Max KES 150,000 per top-up</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold">M-Pesa Phone Number</label>
          <input type="tel" id="topupPhone" class="form-control"
                 placeholder="+254 7XX XXX XXX"
                 value="<?= e(preg_replace('/[^0-9+]/', '', $user['phone'] ?? '')) ?>">
        </div>
        <button class="btn btn-success w-100 fw-bold" id="topupBtn" onclick="initiateTopUp(this)" disabled>
          <i class="fas fa-wallet me-2"></i>Top Up Wallet
        </button>
        <div class="alert alert-info small mt-3 mb-0 py-2">
          <i class="fas fa-bolt me-1 text-info"></i>
          Funds credited <strong>instantly</strong> after M-Pesa confirmation. Use your wallet to pay invoices in one click — no PIN needed per payment.
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Transaction history -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header fw-bold d-flex align-items-center justify-content-between">
        <span><i class="fas fa-history text-green me-2"></i>Transaction History</span>
        <?php if (!empty($walletTxns)): ?>
        <span class="badge bg-primary"><?= count($walletTxns) ?> transactions</span>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if (empty($walletTxns)): ?>
        <div class="text-center py-5 text-muted">
          <i class="fas fa-wallet fa-2x mb-3 d-block opacity-25"></i>
          <div class="fw-semibold mb-1">No transactions yet</div>
          <div class="small">Your wallet is at KES 0.00. Top up above to get started.</div>
          <div class="mt-3">
            <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('topupAmount').focus()">
              <i class="fas fa-plus-circle me-1"></i>Make Your First Top-Up
            </button>
          </div>
        </div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
              <tr><th>Date</th><th>Description</th><th>Amount</th><th>Balance</th><th>Status</th></tr>
            </thead>
            <tbody>
              <?php foreach ($walletTxns as $tx):
                $txColor = match($tx['type']) {
                    'topup'     => 'text-success',
                    'deduction' => 'text-danger',
                    'refund'    => 'text-info',
                    default     => '',
                };
                $txSign = $tx['type'] === 'topup' || $tx['type'] === 'refund' ? '+' : '-';
                $txIcon = match($tx['type']) {
                    'topup'     => 'fa-arrow-down',
                    'deduction' => 'fa-arrow-up',
                    'refund'    => 'fa-undo',
                    default     => 'fa-circle',
                };
              ?>
              <tr>
                <td class="small text-muted"><?= formatDate($tx['created_at']) ?></td>
                <td class="small">
                  <i class="fas <?= $txIcon ?> <?= $txColor ?> me-1 small"></i>
                  <?= e($tx['description'] ?: ucfirst($tx['type'])) ?>
                  <?php if ($tx['mpesa_receipt']): ?>
                  <div class="text-muted" style="font-size:.7rem"><?= e($tx['mpesa_receipt']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="fw-semibold <?= $txColor ?>">
                  <?= $txSign ?><?= formatCurrency((float)$tx['amount']) ?>
                </td>
                <td class="small"><?= $tx['status'] === 'completed' ? formatCurrency((float)$tx['balance_after']) : '—' ?></td>
                <td><?= statusBadge($tx['status']) ?></td>
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

<?php endif; ?>

<?php
$focusInvTotal = (float)($focusInv['total'] ?? 0);
$extraJs = '<script>
const BILLING_USD_RATE = ' . (float)$usdRate . ';

// ── Plans tab: currency toggle ────────────────────────────────────
let planCur   = localStorage.getItem("billingPlanCur")   || "KES";
let planCycle = localStorage.getItem("billingPlanCycle") || "monthly";

function updatePlanPrices() {
  const isUSD = planCur === "USD";
  const isAnn = planCycle === "annual";

  // Update currency buttons
  const uBtn = document.getElementById("planBtnUSD");
  const kBtn = document.getElementById("planBtnKES");
  if (uBtn && kBtn) {
    uBtn.style.background = isUSD  ? "#0B2D4E" : "transparent";
    uBtn.style.color      = isUSD  ? "#fff"    : "#64748b";
    kBtn.style.background = !isUSD ? "#0B2D4E" : "transparent";
    kBtn.style.color      = !isUSD ? "#fff"    : "#64748b";
  }

  // Update cycle buttons
  const moBtn  = document.getElementById("cycleBtnMonthly");
  const annBtn = document.getElementById("cycleBtnAnnual");
  if (moBtn && annBtn) {
    moBtn.classList.toggle("active",  !isAnn);
    annBtn.classList.toggle("active",  isAnn);
  }

  // Update each plan card price + cycle label + hidden form input
  document.querySelectorAll("[id^=planPrice]").forEach(function(el) {
    const id = el.id.replace("planPrice","");
    const key = (isUSD ? "usd" : "kes") + "-" + (isAnn ? "ann" : "mo");
    el.textContent = el.dataset[key.replace("-","").replace("kesmo","kesMo").replace("kesann","kesAnn").replace("usdmo","usdMo").replace("usdann","usdAnn")] || el.dataset[key];

    // Simpler approach: map keys properly
    const dataKey = isAnn ? (isUSD ? "usdAnn" : "kesAnn") : (isUSD ? "usdMo" : "kesMo");
    const val = el.dataset[dataKey];
    if (val) el.textContent = val;

    // Update cycle label
    const lbl = document.getElementById("planCycleLabel" + id);
    if (lbl) lbl.textContent = isAnn ? "/yr" : "/mo";

    // Update hidden billing_cycle input
    const inp = document.getElementById("planCycleInput" + id);
    if (inp) inp.value = isAnn ? "annual" : "monthly";

    // Update CTA button label
    const btnLbl = document.getElementById("planBtnLabel" + id);
    if (btnLbl) btnLbl.textContent = isAnn ? "Annual" : "Monthly";

    // Show/hide save badge row
    const saveRow   = document.getElementById("saveRow" + id);
    const noSaveRow = document.getElementById("noSaveRow" + id);
    if (saveRow)   saveRow.style.display   = isAnn ? "" : "none";
    if (noSaveRow) noSaveRow.style.display = isAnn ? "none" : "";
  });
}

function setPlanCurrency(cur) {
  planCur = cur;
  localStorage.setItem("billingPlanCur", cur);
  updatePlanPrices();
}

function setPlanCycle(cycle) {
  planCycle = cycle;
  localStorage.setItem("billingPlanCycle", cycle);
  updatePlanPrices();
}

// Apply saved preferences on load
if (document.getElementById("planBtnUSD")) updatePlanPrices();

// ── Invoices tab: status filter chips ────────────────────────────
function filterInvoices(filter, chip) {
  // Update chip active state
  document.querySelectorAll(".inv-chip").forEach(function(c) {
    c.classList.remove("active","active-warn","active-danger");
  });
  const colorMap = { unpaid:"active-warn", overdue:"active-danger" };
  chip.classList.add(colorMap[filter] || "active");

  // Show/hide rows
  document.querySelectorAll(".inv-row").forEach(function(row) {
    const status = row.dataset.status;
    const group  = row.dataset.group;
    let show = false;
    if (filter === "all")    show = true;
    else if (filter === "unpaid")  show = group === "unpaid";
    else if (filter === "paid")    show = status === "paid";
    else if (filter === "overdue") show = status === "overdue";
    row.style.display = show ? "" : "none";
  });

  // Show "no results" message
  const visible = document.querySelectorAll(".inv-row:not([style*=none])").length;
  let noRes = document.getElementById("invNoResults");
  if (visible === 0) {
    if (!noRes) {
      noRes = document.createElement("tr");
      noRes.id = "invNoResults";
      noRes.innerHTML = \'<td colspan="8" class="text-center py-4 text-muted"><i class="fas fa-search me-2"></i>No invoices match this filter.</td>\';
      document.getElementById("invTableBody").appendChild(noRes);
    }
    noRes.style.display = "";
  } else if (noRes) {
    noRes.style.display = "none";
  }
}

// ── Wallet: top-up USD hint ────────────────────────────────────────
function initiateTopUp(btn) {
  const phone  = document.getElementById("topupPhone").value.trim();
  const amount = parseFloat(document.getElementById("topupAmount").value) || 0;
  if (!phone) { Swal.fire({ icon:"warning", title:"Phone Required", text:"Enter your M-Pesa phone number." }); return; }
  if (amount < 10) { Swal.fire({ icon:"warning", title:"Amount Too Low", text:"Minimum top-up is KES 10." }); return; }

  btn.disabled = true;
  btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Sending STK Push…\';

  fetch("' . APP_URL . '/api/wallet-topup.php", {
    method:"POST",
    headers:{"Content-Type":"application/x-www-form-urlencoded"},
    body:`phone=${encodeURIComponent(phone)}&amount=${encodeURIComponent(amount)}`
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      btn.disabled = false;
      btn.innerHTML = \'<i class="fas fa-wallet me-2"></i>Top Up Wallet\';
      Swal.fire({ icon:"error", title:"Failed", text:data.message });
      return;
    }
    btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Waiting for M-Pesa…\';
    pollWallet(data.checkout_id, btn, 0);
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-wallet me-2"></i>Top Up Wallet\';
    Swal.fire({ icon:"error", title:"Network Error" });
  });
}

function pollWallet(checkoutId, btn, attempts) {
  if (attempts >= 40) {
    btn.disabled = false; btn.innerHTML = \'<i class="fas fa-wallet me-2"></i>Top Up Wallet\';
    Swal.fire({ icon:"warning", title:"Timeout", text:"Payment not confirmed yet. Refresh this page to check your balance.", confirmButtonColor:"#1A8A4E" });
    return;
  }
  setTimeout(() => {
    fetch("' . APP_URL . '/api/check-payment.php?id=" + encodeURIComponent(checkoutId))
      .then(r => r.json())
      .then(res => {
        if (res.status === "completed") {
          Swal.fire({ icon:"success", title:"Wallet Topped Up!", html:"Your wallet has been credited.<br><strong>Receipt: "+(res.receipt||"")+"</strong>", confirmButtonColor:"#1A8A4E" })
            .then(() => location.reload());
        } else if (res.status === "failed") {
          btn.disabled = false; btn.innerHTML = \'<i class="fas fa-wallet me-2"></i>Top Up Wallet\';
          Swal.fire({ icon:"error", title:"Payment Failed", text:"M-Pesa payment was not completed." });
        } else { pollWallet(checkoutId, btn, attempts+1); }
      })
      .catch(() => pollWallet(checkoutId, btn, attempts+1));
  }, 3000);
}

function setTopupAmount(n) {
  document.getElementById("topupAmount").value = n;
  updateTopupBtn();
}
function updateTopupBtn() {
  const v    = parseFloat(document.getElementById("topupAmount").value) || 0;
  const hint = document.getElementById("topupUsdHint");
  document.getElementById("topupBtn").disabled = v < 10;
  if (hint) {
    hint.textContent = v >= 10
      ? "KES " + v.toLocaleString("en-KE") + " ≈ $ " + (v / BILLING_USD_RATE).toFixed(2) + " · Min KES 10, Max KES 150,000"
      : "Min KES 10 · Max KES 150,000 per top-up";
  }
}

// ── Promo code helpers ────────────────────────────────────────────
const INVOICE_TOTAL = ' . $focusInvTotal . ';

function applyPromoCode() {
  const code = document.getElementById("promoCodeInput").value.trim();
  if (!code) {
    showPromoMsg("warning", "Please enter a promo code.");
    return;
  }
  const btn = document.getElementById("applyPromoBtn");
  btn.disabled = true;
  btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-1"></i>Checking…\';

  fetch("' . APP_URL . '/api/apply-promo.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `code=${encodeURIComponent(code)}&amount=${encodeURIComponent(INVOICE_TOTAL)}`
  })
  .then(r => r.json())
  .then(data => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-check me-1"></i>Apply\';
    if (data.valid) {
      document.getElementById("appliedPromoId").value   = data.promo_id || "";
      document.getElementById("appliedDiscount").value  = data.discount || 0;
      document.getElementById("walletPromoId").value    = data.promo_id || "";
      document.getElementById("cashPromoId").value      = data.promo_id || "";

      // Show discount row
      document.getElementById("promoDiscountRow").classList.remove("d-none");
      document.getElementById("promoDiscountDisplay").textContent = "— " + formatKES(data.discount);
      document.getElementById("invoiceTotalDisplay").textContent  = formatKES(data.final_price);

      showPromoMsg("success", data.message);
      document.getElementById("applyPromoBtn").classList.add("d-none");
      document.getElementById("removePromoBtn").classList.remove("d-none");
      document.getElementById("promoCodeInput").readOnly = true;
    } else {
      showPromoMsg("danger", data.message || "Invalid promo code.");
    }
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-check me-1"></i>Apply\';
    showPromoMsg("danger", "Network error. Please try again.");
  });
}

function removePromoCode() {
  document.getElementById("appliedPromoId").value  = "";
  document.getElementById("appliedDiscount").value = "0";
  document.getElementById("walletPromoId").value   = "";
  document.getElementById("cashPromoId").value     = "";
  document.getElementById("promoCodeInput").value  = "";
  document.getElementById("promoCodeInput").readOnly = false;
  document.getElementById("promoDiscountRow").classList.add("d-none");
  document.getElementById("invoiceTotalDisplay").textContent = formatKES(INVOICE_TOTAL);
  document.getElementById("applyPromoBtn").classList.remove("d-none");
  document.getElementById("removePromoBtn").classList.add("d-none");
  showPromoMsg("", "");
}

function showPromoMsg(type, msg) {
  const el = document.getElementById("promoMsg");
  if (!type || !msg) { el.innerHTML = ""; return; }
  const icons = { success:"fa-check-circle", danger:"fa-exclamation-circle", warning:"fa-exclamation-triangle" };
  el.innerHTML = `<span class="text-${type}"><i class="fas ${icons[type]||"fa-info-circle"} me-1"></i>${msg}</span>`;
}

function formatKES(amount) {
  return "KES " + parseFloat(amount).toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function initiateMpesa(invoiceId, amount) {
  const phone = document.getElementById("mpesaPhone").value.trim();
  if (!phone) {
    Swal.fire({ icon: "warning", title: "Phone Required", text: "Please enter your M-Pesa phone number." });
    return;
  }
  const btn = event.currentTarget;
  btn.disabled = true;
  btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Sending STK Push…\';

  fetch("' . APP_URL . '/api/mpesa-stk.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: `phone=${encodeURIComponent(phone)}&amount=${encodeURIComponent(amount)}&invoice_id=${invoiceId}`
  })
  .then(r => r.json())
  .then(data => {
    if (!data.success) {
      btn.disabled = false;
      btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Pay via KopoKopo M-Pesa\';
      Swal.fire({ icon: "error", title: "Failed", text: data.message });
      return;
    }
    // STK sent — show waiting state and start polling
    btn.innerHTML = \'<i class="fas fa-spinner fa-spin me-2"></i>Waiting for M-Pesa confirmation…\';
    pollPayment(data.checkout_id, btn, 0);
  })
  .catch(() => {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Pay via KopoKopo M-Pesa\';
    Swal.fire({ icon: "error", title: "Network Error", text: "Could not reach payment server." });
  });
}

function pollPayment(checkoutId, btn, attempts) {
  const MAX_ATTEMPTS = 40; // 40 × 3 s = 2 minutes
  if (attempts >= MAX_ATTEMPTS) {
    btn.disabled = false;
    btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Pay via KopoKopo M-Pesa\';
    Swal.fire({
      icon: "warning",
      title: "Timeout",
      text: "We did not receive payment confirmation. If you completed the M-Pesa prompt, please refresh this page to check your invoice status.",
      confirmButtonColor: "#1A8A4E"
    });
    return;
  }
  setTimeout(() => {
    fetch("' . APP_URL . '/api/check-payment.php?id=" + encodeURIComponent(checkoutId))
      .then(r => r.json())
      .then(res => {
        if (res.status === "completed") {
          Swal.fire({
            icon: "success",
            title: "Payment Confirmed!",
            html: "Your M-Pesa payment has been received.<br><strong>Receipt: " + (res.receipt || "") + "</strong>",
            confirmButtonColor: "#1A8A4E",
            confirmButtonText: "View Invoice"
          }).then(() => location.reload());
        } else if (res.status === "failed") {
          btn.disabled = false;
          btn.innerHTML = \'<i class="fas fa-mobile-alt me-2"></i>Pay via KopoKopo M-Pesa\';
          Swal.fire({ icon: "error", title: "Payment Failed", text: "The M-Pesa payment was not completed. Please try again." });
        } else {
          pollPayment(checkoutId, btn, attempts + 1);
        }
      })
      .catch(() => pollPayment(checkoutId, btn, attempts + 1));
  }, 3000);
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
