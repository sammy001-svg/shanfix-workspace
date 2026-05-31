<?php
/**
 * DIAGNOSTIC & REPAIR TOOL — delete this file after running.
 * Access: https://orbitdesk.net/admin/fix-invoice-tax.php
 *
 * Fixes:
 *  1. Corrupted invoice_tax_rate system setting
 *  2. Module monthly/annual prices that were accidentally set to huge values
 *  3. Voids unpaid invoices whose total exceeds KES 999,999
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$log     = [];
$actions = [];

// ── Correct module seed prices (source of truth from schema) ─────────────────
$correctPrices = [
    'accounting'    => ['monthly' => 2500,  'annual' => 25000],
    'crm'           => ['monthly' => 2000,  'annual' => 20000],
    'sales'         => ['monthly' => 2000,  'annual' => 20000],
    'meetings'      => ['monthly' => 1500,  'annual' => 15000],
    'school'        => ['monthly' => 3500,  'annual' => 35000],
    'health'        => ['monthly' => 3500,  'annual' => 35000],
    'pos'           => ['monthly' => 2500,  'annual' => 25000],
    'sacco'         => ['monthly' => 4000,  'annual' => 40000],
    'rental'        => ['monthly' => 3000,  'annual' => 30000],
    'church'        => ['monthly' => 2000,  'annual' => 20000],
    'finance'       => ['monthly' => 2500,  'annual' => 25000],
    'hotel'         => ['monthly' => 4000,  'annual' => 40000],
    'salon'         => ['monthly' => 2000,  'annual' => 20000],
    'retail'        => ['monthly' => 3000,  'annual' => 30000],
    'tour'          => ['monthly' => 3000,  'annual' => 30000],
    'events'        => ['monthly' => 2500,  'annual' => 25000],
    'manufacturing' => ['monthly' => 4500,  'annual' => 45000],
    'hrm'           => ['monthly' => 3500,  'annual' => 35000],
    'caryard'       => ['monthly' => 3000,  'annual' => 30000],
    'shopping-mall' => ['monthly' => 5000,  'annual' => 50000],
    'courier'       => ['monthly' => 3000,  'annual' => 30000],
    'driving'       => ['monthly' => 4500,  'annual' => 45000],
];

// ── 1. Check & fix invoice_tax_rate ──────────────────────────────────────────
$currentRate = getSetting('invoice_tax_rate', '');
$rateFloat   = ($currentRate === '') ? null : (float)$currentRate;

$log[] = "invoice_tax_rate stored value : \"" . ($currentRate === '' ? '(not set)' : $currentRate) . "\"";
$log[] = "invoice_tax_rate as float     : " . ($rateFloat === null ? '— using default 16' : $rateFloat);

if ($rateFloat === null || $rateFloat < 0 || $rateFloat > 100) {
    saveSetting('invoice_tax_rate', '16');
    $log[] = "⚠️  RESET to 16% (was: " . ($rateFloat ?? 'not set') . "%)";
} else {
    $log[] = "✅  invoice_tax_rate OK ({$rateFloat}%)";
}

// ── 2. Check module prices ───────────────────────────────────────────────────
$maxAllowed = 999999; // KES — anything above this is clearly wrong
try {
    $mods = $pdo->query("SELECT id, slug, name, monthly_price, annual_price FROM modules ORDER BY sort_order")->fetchAll();
} catch (Throwable $e) { $mods = []; $log[] = "ERROR reading modules: " . $e->getMessage(); }

$badMods = [];
foreach ($mods as $m) {
    $mo = (float)$m['monthly_price'];
    $an = (float)$m['annual_price'];
    if ($mo > $maxAllowed || $an > $maxAllowed * 12) {
        $badMods[] = $m;
        $log[] = "⚠️  MODULE [{$m['slug']}] '{$m['name']}' has WRONG prices: monthly=KES " . number_format($mo, 2) . "  annual=KES " . number_format($an, 2);
    }
}
if (empty($badMods)) $log[] = "✅  All module prices are within normal range.";

// ── 3. Check invoices with total > 999,999 ───────────────────────────────────
try {
    $badInvoices = $pdo->query("
        SELECT i.id, i.invoice_number, i.org_id, i.amount, i.tax, i.total, i.status, i.created_at,
               o.name AS org_name
        FROM invoices i
        LEFT JOIN organizations o ON i.org_id = o.id
        WHERE i.total > 999999
        ORDER BY i.total DESC LIMIT 100
    ")->fetchAll();
} catch (Throwable $e) { $badInvoices = []; $log[] = "ERROR reading invoices: " . $e->getMessage(); }

$log[] = count($badInvoices) . " invoice(s) found with total > KES 999,999";

// ── POST: apply fixes ────────────────────────────────────────────────────────
$fixed = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = $_POST['do'] ?? '';

    // Reset bad module prices
    if ($do === 'fix_prices' && !empty($badMods)) {
        foreach ($badMods as $m) {
            $slug = $m['slug'];
            if (isset($correctPrices[$slug])) {
                $mo = $correctPrices[$slug]['monthly'];
                $an = $correctPrices[$slug]['annual'];
            } else {
                $mo = 2000; $an = 20000; // safe generic default
            }
            $pdo->prepare("UPDATE modules SET monthly_price=?, annual_price=? WHERE id=?")
                ->execute([$mo, $an, $m['id']]);
            $fixed[] = "Reset [{$m['slug']}] monthly=KES {$mo}  annual=KES {$an}";
            $log[]   = "✅  FIXED [{$m['slug']}] → monthly=KES {$mo}  annual=KES {$an}";
        }
    }

    // Void bad invoices
    if ($do === 'void_invoices' && !empty($badInvoices)) {
        $voided = 0;
        foreach ($badInvoices as $inv) {
            if (in_array($inv['status'], ['draft','sent','overdue'])) {
                $pdo->prepare("UPDATE invoices SET status='cancelled', notes=CONCAT(IFNULL(notes,''),' [VOIDED: abnormal total KES ".number_format((float)$inv['total'],0)."]') WHERE id=?")
                    ->execute([$inv['id']]);
                $voided++;
                $fixed[] = "Voided invoice {$inv['invoice_number']} (was KES " . number_format((float)$inv['total'], 2) . ")";
            }
        }
        $log[] = "Voided {$voided} unpaid invoice(s) with abnormal totals.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billing Repair Tool — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
<div class="container" style="max-width:960px">
  <div class="card shadow">
    <div class="card-header bg-danger text-white d-flex align-items-center justify-content-between">
      <strong><i class="fas fa-wrench me-2"></i>Billing Repair Tool — <?= e(APP_NAME) ?></strong>
      <span class="badge bg-warning text-dark">Delete this file when done</span>
    </div>
    <div class="card-body">

      <?php if (!empty($fixed)): ?>
      <div class="alert alert-success">
        <strong>Actions taken:</strong>
        <ul class="mb-0 mt-1">
          <?php foreach ($fixed as $f): ?><li><?= htmlspecialchars($f) ?></li><?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

      <!-- Diagnosis log -->
      <h6 class="fw-bold">Diagnosis</h6>
      <pre class="bg-dark text-light p-3 rounded mb-4" style="font-size:.8rem;white-space:pre-wrap"><?= htmlspecialchars(implode("\n", $log)) ?></pre>

      <!-- Module prices -->
      <div class="row g-3 mb-4">
        <div class="col-lg-6">
          <h6 class="fw-bold">All Module Prices (current DB values)</h6>
          <div class="table-responsive" style="max-height:340px;overflow-y:auto">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-dark sticky-top"><tr><th>Module</th><th>Monthly (KES)</th><th>Annual (KES)</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($mods as $m):
                  $mo = (float)$m['monthly_price'];
                  $an = (float)$m['annual_price'];
                  $isWrong = $mo > $maxAllowed || $an > $maxAllowed * 12;
                ?>
                <tr class="<?= $isWrong ? 'table-danger fw-bold' : '' ?>">
                  <td><?= htmlspecialchars($m['name']) ?></td>
                  <td><?= number_format($mo, 2) ?><?= $isWrong ? ' ⚠️' : '' ?></td>
                  <td><?= number_format($an, 2) ?><?= $isWrong ? ' ⚠️' : '' ?></td>
                  <td><?= $isWrong ? '<span class="badge bg-danger">WRONG</span>' : '<span class="badge bg-success">OK</span>' ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php if (!empty($badMods)): ?>
          <form method="POST" class="mt-2" onsubmit="return confirm('Reset all highlighted module prices to the correct seed values?')">
            <input type="hidden" name="do" value="fix_prices">
            <button type="submit" class="btn btn-danger w-100 fw-bold">
              Reset <?= count($badMods) ?> Wrong Module Price(s) to Correct Values
            </button>
          </form>
          <?php endif; ?>
        </div>

        <!-- Bad invoices -->
        <div class="col-lg-6">
          <h6 class="fw-bold">Invoices with Abnormal Totals (> KES 999,999)</h6>
          <?php if (empty($badInvoices)): ?>
          <div class="alert alert-success py-2">No abnormal invoices found.</div>
          <?php else: ?>
          <div class="table-responsive" style="max-height:280px;overflow-y:auto">
            <table class="table table-sm table-bordered mb-0">
              <thead class="table-dark sticky-top"><tr><th>Invoice</th><th>Org</th><th>Total</th><th>Status</th></tr></thead>
              <tbody>
                <?php foreach ($badInvoices as $inv): ?>
                <tr class="table-danger">
                  <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
                  <td><?= htmlspecialchars($inv['org_name'] ?? '?') ?></td>
                  <td>KES <?= number_format((float)$inv['total'], 2) ?></td>
                  <td><?= htmlspecialchars($inv['status']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php
          $voidable = count(array_filter($badInvoices, fn($i) => in_array($i['status'],['draft','sent','overdue'])));
          if ($voidable > 0): ?>
          <form method="POST" class="mt-2" onsubmit="return confirm('Cancel <?= $voidable ?> unpaid invoice(s) with abnormal totals?')">
            <input type="hidden" name="do" value="void_invoices">
            <button type="submit" class="btn btn-danger w-100 fw-bold">
              Void <?= $voidable ?> Unpaid Invoice(s) with Wrong Totals
            </button>
          </form>
          <?php endif; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="alert alert-warning mb-0">
        <strong>Step-by-step:</strong>
        <ol class="mb-0 mt-1">
          <li>If any module prices show <span class="badge bg-danger">WRONG</span> — click <em>Reset Module Prices</em> first.</li>
          <li>If any invoices appear above — click <em>Void Invoices</em> to cancel them.</li>
          <li>After both fixes, go to <strong>Admin → Modules</strong> and verify module prices look correct.</li>
          <li><strong>Delete this file</strong> from your server: <code>admin/fix-invoice-tax.php</code></li>
        </ol>
      </div>

    </div>
  </div>
</div>
</body>
</html>
