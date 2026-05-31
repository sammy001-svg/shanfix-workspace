<?php
/**
 * DIAGNOSTIC & REPAIR TOOL — delete after use.
 * Access: https://orbitdesk.net/admin/fix-invoice-tax.php
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$log     = [];
$actions = [];

// ── Correct seed prices ───────────────────────────────────────────────────────
$correctModPrices = [
    'accounting'=>['mo'=>2500,'an'=>25000],'crm'=>['mo'=>2000,'an'=>20000],
    'sales'=>['mo'=>2000,'an'=>20000],'meetings'=>['mo'=>1500,'an'=>15000],
    'school'=>['mo'=>3500,'an'=>35000],'health'=>['mo'=>3500,'an'=>35000],
    'pos'=>['mo'=>2500,'an'=>25000],'sacco'=>['mo'=>4000,'an'=>40000],
    'rental'=>['mo'=>3000,'an'=>30000],'church'=>['mo'=>2000,'an'=>20000],
    'finance'=>['mo'=>2500,'an'=>25000],'hotel'=>['mo'=>4000,'an'=>40000],
    'salon'=>['mo'=>2000,'an'=>20000],'retail'=>['mo'=>3000,'an'=>30000],
    'tour'=>['mo'=>3000,'an'=>30000],'events'=>['mo'=>2500,'an'=>25000],
    'manufacturing'=>['mo'=>4500,'an'=>45000],'hrm'=>['mo'=>3500,'an'=>35000],
    'caryard'=>['mo'=>3000,'an'=>30000],'shopping-mall'=>['mo'=>5000,'an'=>50000],
    'courier'=>['mo'=>3000,'an'=>30000],'driving'=>['mo'=>4500,'an'=>45000],
];

// ── 1. Tax rate ───────────────────────────────────────────────────────────────
$currentRate = getSetting('invoice_tax_rate', '');
$rateFloat   = ($currentRate === '') ? null : (float)$currentRate;
$log[] = "invoice_tax_rate = \"" . ($currentRate ?: '(not set)') . "\" → " . ($rateFloat ?? 'default 16') . "%";
if ($rateFloat === null || $rateFloat < 0 || $rateFloat > 100) {
    saveSetting('invoice_tax_rate', '16');
    $log[] = "⚠️  RESET to 16%";
} else {
    $log[] = "✅  Tax rate OK";
}

// ── 2. Module prices ──────────────────────────────────────────────────────────
try { $mods = $pdo->query("SELECT id,slug,name,monthly_price,annual_price FROM modules ORDER BY sort_order")->fetchAll(); }
catch (Throwable $e) { $mods=[]; }
$badMods = array_filter($mods, fn($m) => (float)$m['monthly_price'] > 999999 || (float)$m['annual_price'] > 11999988);
$log[] = count($badMods) . " module(s) with abnormal prices";
if (empty($badMods)) $log[] = "✅  Module prices OK";

// ── 3. Subscription plan prices ───────────────────────────────────────────────
try { $plans = $pdo->query("SELECT id,name,price_monthly,price_annual FROM subscription_plans ORDER BY price_monthly")->fetchAll(); }
catch (Throwable $e) { $plans=[]; }
$badPlans = array_filter($plans, fn($p) => (float)$p['price_monthly'] > 999999 || (float)$p['price_annual'] > 11999988);
$log[] = count($badPlans) . " plan(s) with abnormal prices";
if (empty($badPlans)) $log[] = "✅  Plan prices OK";
else foreach ($badPlans as $p) $log[] = "  ⚠️  Plan [{$p['name']}]: monthly=" . number_format((float)$p['price_monthly']) . " annual=" . number_format((float)$p['price_annual']);

// ── 4. Bad invoices — check amount OR tax OR total ────────────────────────────
try {
    $badInvoices = $pdo->query("
        SELECT i.id, i.invoice_number,
               CAST(i.amount AS DECIMAL(12,2)) AS amount,
               CAST(i.tax    AS DECIMAL(12,2)) AS tax,
               CAST(i.total  AS DECIMAL(12,2)) AS total,
               i.status, i.created_at, o.name AS org_name
        FROM invoices i
        LEFT JOIN organizations o ON i.org_id = o.id
        WHERE i.amount > 999999 OR i.tax > 999999 OR i.total > 999999
        ORDER BY i.created_at DESC LIMIT 100
    ")->fetchAll();
} catch (Throwable $e) { $badInvoices=[]; }
$log[] = count($badInvoices) . " invoice(s) with any column (amount/tax/total) > KES 999,999";

// ── 5. All recent invoices — explicit columns so SELECT i.* collision can't hide values ──
try {
    $recentInvoices = $pdo->query("
        SELECT i.id, i.invoice_number,
               CAST(i.amount AS DECIMAL(12,2)) AS amount,
               CAST(i.tax    AS DECIMAL(12,2)) AS tax,
               CAST(i.total  AS DECIMAL(12,2)) AS total,
               i.status, i.created_at, o.name AS org_name
        FROM invoices i
        LEFT JOIN organizations o ON i.org_id = o.id
        ORDER BY i.created_at DESC LIMIT 20
    ")->fetchAll();
} catch (Throwable $e) { $recentInvoices=[]; }

// ── 6. Detect SELECT i.* column collision — compare i.* vs explicit ─────────
$starVsExplicit = '';
try {
    $r1 = $pdo->query("SELECT i.*, o.name AS org_name FROM invoices i LEFT JOIN organizations o ON i.org_id = o.id ORDER BY i.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $r2 = $pdo->query("SELECT i.id, CAST(i.amount AS DECIMAL(12,2)) AS amount, CAST(i.tax AS DECIMAL(12,2)) AS tax, CAST(i.total AS DECIMAL(12,2)) AS total FROM invoices i ORDER BY i.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r1 && $r2) {
        $diff = abs((float)$r1['amount'] - (float)$r2['amount']);
        if ($diff > 1) {
            $starVsExplicit = "⚠️  SELECT i.* returns amount=" . number_format((float)$r1['amount'],2) . " but explicit SELECT returns amount=" . number_format((float)$r2['amount'],2) . " — COLUMN COLLISION CONFIRMED (difference: " . number_format($diff,2) . ")";
            $log[] = $starVsExplicit;
        } else {
            $log[] = "✅  SELECT i.* vs explicit columns match (no collision)";
        }
    }
} catch (Throwable $e) { $log[] = "Could not run collision test: " . $e->getMessage(); }

// ── POST: apply fixes ─────────────────────────────────────────────────────────
$fixed = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    if ($do === 'fix_mod_prices' && !empty($badMods)) {
        foreach ($badMods as $m) {
            $p = $correctModPrices[$m['slug']] ?? ['mo'=>2000,'an'=>20000];
            $pdo->prepare("UPDATE modules SET monthly_price=?,annual_price=? WHERE id=?")->execute([$p['mo'],$p['an'],$m['id']]);
            $fixed[] = "Fixed module [{$m['slug']}]: monthly=KES {$p['mo']} annual=KES {$p['an']}";
        }
    }
    if ($do === 'fix_plan_prices' && !empty($badPlans)) {
        foreach ($badPlans as $p) {
            // Reset to schema defaults
            $defaults = [
                'Starter'      => ['mo'=>4999,  'an'=>49990],
                'Professional' => ['mo'=>12999, 'an'=>129990],
                'Enterprise'   => ['mo'=>29999, 'an'=>299990],
            ];
            $def = $defaults[$p['name']] ?? ['mo'=>4999,'an'=>49990];
            $pdo->prepare("UPDATE subscription_plans SET price_monthly=?,price_annual=? WHERE id=?")->execute([$def['mo'],$def['an'],$p['id']]);
            $fixed[] = "Fixed plan [{$p['name']}]: monthly=KES {$def['mo']} annual=KES {$def['an']}";
        }
    }
    if ($do === 'void_invoices' && !empty($badInvoices)) {
        $n = 0;
        foreach ($badInvoices as $inv) {
            if (in_array($inv['status'],['draft','sent','overdue'])) {
                $pdo->prepare("UPDATE invoices SET status='cancelled', notes=CONCAT(IFNULL(notes,''),' [AUTO-VOIDED abnormal amount]') WHERE id=?")->execute([$inv['id']]);
                $fixed[] = "Voided invoice {$inv['invoice_number']}";
                $n++;
            }
        }
        $fixed[] = "Total voided: $n";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billing Repair — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  .val-bad { color:#dc3545; font-weight:700; }
  .val-ok  { color:#198754; }
  pre { font-size:.78rem; }
</style>
</head>
<body class="bg-light p-3">
<div class="container-fluid" style="max-width:1100px">
<div class="card shadow">
  <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
    <strong>Billing Repair Tool — <?= e(APP_NAME) ?></strong>
    <span class="badge bg-warning text-dark">Delete after use</span>
  </div>
  <div class="card-body">

  <?php if ($fixed): ?>
  <div class="alert alert-success"><strong>Done:</strong><ul class="mb-0 mt-1"><?php foreach($fixed as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?></ul></div>
  <?php endif; ?>

  <div class="row g-3">

    <!-- Diagnosis log -->
    <div class="col-12">
      <h6 class="fw-bold">Diagnosis</h6>
      <pre class="bg-dark text-light p-2 rounded"><?= htmlspecialchars(implode("\n",$log)) ?></pre>
    </div>

    <!-- Module prices -->
    <div class="col-md-4">
      <h6 class="fw-bold">Module Prices</h6>
      <div style="max-height:300px;overflow-y:auto">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-dark"><tr><th>Module</th><th>Monthly</th><th>Annual</th></tr></thead>
        <tbody>
        <?php foreach($mods as $m):
          $mo=(float)$m['monthly_price']; $an=(float)$m['annual_price'];
          $bad=$mo>999999||$an>11999988;
        ?>
        <?php $cls = $bad ? 'table-danger' : ''; $vcls = $bad ? 'val-bad' : 'val-ok'; ?>
        <tr class="<?= $cls ?>">
          <td style="font-size:.78rem"><?= htmlspecialchars($m['slug']) ?></td>
          <td class="<?= $vcls ?>"><?= number_format($mo, 0) ?></td>
          <td class="<?= $vcls ?>"><?= number_format($an, 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if (!empty($badMods)): ?>
      <form method="POST" class="mt-2" onsubmit="return confirm('Reset module prices?')">
        <input type="hidden" name="do" value="fix_mod_prices">
        <button class="btn btn-danger btn-sm w-100">Fix <?=count($badMods)?> Module Price(s)</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Plan prices -->
    <div class="col-md-4">
      <h6 class="fw-bold">Subscription Plan Prices</h6>
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-dark"><tr><th>Plan</th><th>Monthly</th><th>Annual</th></tr></thead>
        <tbody>
        <?php foreach($plans as $p):
          $mo=(float)$p['price_monthly']; $an=(float)$p['price_annual'];
          $bad=$mo>999999||$an>11999988;
        ?>
        <?php $cls = $bad ? 'table-danger' : ''; $vcls = $bad ? 'val-bad' : 'val-ok'; ?>
        <tr class="<?= $cls ?>">
          <td style="font-size:.78rem"><?= htmlspecialchars($p['name']) ?></td>
          <td class="<?= $vcls ?>"><?= number_format($mo, 0) ?></td>
          <td class="<?= $vcls ?>"><?= number_format($an, 0) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (!empty($badPlans)): ?>
      <form method="POST" class="mt-2" onsubmit="return confirm('Reset plan prices to defaults?')">
        <input type="hidden" name="do" value="fix_plan_prices">
        <button class="btn btn-danger btn-sm w-100">Fix <?=count($badPlans)?> Plan Price(s)</button>
      </form>
      <?php endif; ?>
    </div>

    <!-- Invoices with ANY bad column -->
    <div class="col-md-4">
      <h6 class="fw-bold">Bad Invoices (amount OR tax OR total &gt; 999,999)</h6>
      <?php if (empty($badInvoices)): ?>
      <div class="alert alert-success py-2 small">None found.</div>
      <?php else: ?>
      <div style="max-height:200px;overflow-y:auto">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-dark"><tr><th>#</th><th>Amt</th><th>Tax</th><th>Total</th><th>St</th></tr></thead>
        <tbody>
        <?php foreach($badInvoices as $inv): ?>
        <tr class="table-danger">
          <td style="font-size:.7rem"><?=htmlspecialchars($inv['invoice_number'])?></td>
          <td class="val-bad" style="font-size:.7rem"><?=number_format((float)$inv['amount'],0)?></td>
          <td class="val-bad" style="font-size:.7rem"><?=number_format((float)$inv['tax'],0)?></td>
          <td class="val-bad" style="font-size:.7rem"><?=number_format((float)$inv['total'],0)?></td>
          <td style="font-size:.7rem"><?=htmlspecialchars($inv['status'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php
      $voidable=count(array_filter($badInvoices,fn($i)=>in_array($i['status'],['draft','sent','overdue'])));
      if($voidable>0): ?>
      <form method="POST" class="mt-2" onsubmit="return confirm('Void <?=$voidable?> unpaid bad invoice(s)?')">
        <input type="hidden" name="do" value="void_invoices">
        <button class="btn btn-danger btn-sm w-100">Void <?=$voidable?> Invoice(s)</button>
      </form>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- ALL recent invoices — raw values -->
    <div class="col-12">
      <h6 class="fw-bold">Last 20 Invoices — Raw Database Values</h6>
      <p class="text-muted small mb-2">This shows the ACTUAL numbers stored in the database. If any row has a suspiciously large Amount, Tax, or Total, that is the corrupted invoice.</p>
      <div class="table-responsive">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-dark">
          <tr><th>Invoice #</th><th>Organisation</th><th>Amount (raw)</th><th>Tax (raw)</th><th>Total (raw)</th><th>Status</th><th>Created</th></tr>
        </thead>
        <tbody>
        <?php foreach($recentInvoices as $inv):
          $isBadRow = (float)$inv['amount']>999999 || (float)$inv['tax']>999999 || (float)$inv['total']>999999;
        ?>
        <tr class="<?=$isBadRow?'table-danger fw-bold':''?>">
          <td><?=htmlspecialchars($inv['invoice_number'])?></td>
          <td><?=htmlspecialchars($inv['org_name']??'?')?></td>
          <td class="<?=$isBadRow?'val-bad':''?>"><?=number_format((float)$inv['amount'],2)?></td>
          <td class="<?=$isBadRow?'val-bad':''?>"><?=number_format((float)$inv['tax'],2)?></td>
          <td class="<?=$isBadRow?'val-bad':''?>"><?=number_format((float)$inv['total'],2)?></td>
          <td><?=htmlspecialchars($inv['status'])?></td>
          <td style="font-size:.75rem"><?=htmlspecialchars($inv['created_at'])?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

  </div><!-- /row -->

  <div class="alert alert-warning mt-3 mb-0">
    <strong>What to do:</strong>
    <ol class="mb-0 mt-1 small">
      <li>Check the "Last 20 Invoices" table — any row highlighted red is a problem invoice.</li>
      <li>Check Plan Prices — if any plan shows a huge number, click <em>Fix Plan Prices</em>.</li>
      <li>Click <em>Void Invoices</em> to cancel all bad unpaid invoices.</li>
      <li><strong>Delete this file</strong> from your server when done.</li>
    </ol>
  </div>

  </div><!-- /card-body -->
</div>
</div>
</body>
</html>
