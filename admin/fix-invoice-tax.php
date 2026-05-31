<?php
/**
 * ONE-TIME FIX — run once, then delete this file.
 *
 * Corrects a corrupted invoice_tax_rate system setting and voids
 * any invoices whose total is clearly impossible given the module prices.
 *
 * Access:  https://yourdomain/admin/fix-invoice-tax.php
 * Delete after running.
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$log = [];

// ── 1. Check and fix invoice_tax_rate ────────────────────────────────────────
$currentRate = getSetting('invoice_tax_rate', '16');
$rateFloat   = (float)$currentRate;
$log[] = "Current invoice_tax_rate in DB: \"{$currentRate}\" → float: {$rateFloat}";

if ($rateFloat < 0 || $rateFloat > 100) {
    saveSetting('invoice_tax_rate', '16');
    $log[] = "⚠️  CORRUPTED — reset to 16% (was: {$rateFloat}%)";
} else {
    $log[] = "✅  invoice_tax_rate is valid ({$rateFloat}%), no change needed.";
}

// ── 2. Report invoices with suspiciously large totals ───────────────────────
try {
    $stmt = $pdo->query("
        SELECT i.id, i.invoice_number, i.org_id, i.amount, i.tax, i.total,
               i.status, i.created_at,
               o.name AS org_name
        FROM invoices i
        LEFT JOIN organizations o ON i.org_id = o.id
        WHERE i.total > 9999999
        ORDER BY i.total DESC
        LIMIT 50
    ");
    $badInvoices = $stmt->fetchAll();
    $log[] = count($badInvoices) . " invoice(s) found with total > KES 9,999,999:";
    foreach ($badInvoices as $inv) {
        $log[] = "  Invoice {$inv['invoice_number']} | Org: {$inv['org_name']} | Total: " . number_format((float)$inv['total'], 2) . " | Status: {$inv['status']}";
    }
} catch (Throwable $e) {
    $log[] = "Error reading invoices: " . $e->getMessage();
    $badInvoices = [];
}

// ── 3. Void bad invoices if requested ────────────────────────────────────────
$voided = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($badInvoices)) {
    foreach ($badInvoices as $inv) {
        if (in_array($inv['status'], ['draft','sent','overdue'])) {
            $pdo->prepare("UPDATE invoices SET status='cancelled', notes=CONCAT(IFNULL(notes,''),' [AUTO-VOIDED: abnormal total]') WHERE id=?")
                ->execute([$inv['id']]);
            $voided++;
        }
    }
    $log[] = "Voided {$voided} unpaid invoice(s) with abnormal totals.";
}

// ── 4. Show current module prices for review ─────────────────────────────────
try {
    $modules = $pdo->query("SELECT name, monthly_price, annual_price FROM modules ORDER BY sort_order")->fetchAll();
} catch (Throwable $e) {
    $modules = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Fix Invoice Tax — <?= APP_NAME ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
<div class="container" style="max-width:860px">
  <div class="card shadow-sm">
    <div class="card-header bg-danger text-white fw-bold">
      <i class="fas fa-wrench me-2"></i>Invoice Tax Rate Repair Tool
      <span class="badge bg-warning text-dark ms-2">Delete this file after use</span>
    </div>
    <div class="card-body">

      <h6 class="fw-bold mb-3">Diagnosis Log</h6>
      <pre class="bg-dark text-success p-3 rounded" style="font-size:.8rem"><?= implode("\n", array_map('htmlspecialchars', $log)) ?></pre>

      <?php if (!empty($badInvoices)): ?>
      <h6 class="fw-bold mt-4">Invoices with Abnormal Totals (total > KES 9,999,999)</h6>
      <table class="table table-sm table-bordered">
        <thead class="table-dark"><tr><th>Invoice #</th><th>Organisation</th><th>Amount</th><th>Tax</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach ($badInvoices as $inv): ?>
          <tr class="table-danger">
            <td><?= htmlspecialchars($inv['invoice_number']) ?></td>
            <td><?= htmlspecialchars($inv['org_name'] ?? 'N/A') ?></td>
            <td>KES <?= number_format((float)$inv['amount'], 2) ?></td>
            <td>KES <?= number_format((float)$inv['tax'], 2) ?></td>
            <td><strong>KES <?= number_format((float)$inv['total'], 2) ?></strong></td>
            <td><?= htmlspecialchars($inv['status']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <form method="POST" onsubmit="return confirm('This will cancel all unpaid invoices with abnormal totals. Proceed?')">
        <button type="submit" class="btn btn-danger">
          <i class="fas fa-trash me-1"></i>Void All Unpaid Abnormal Invoices (<?= count(array_filter($badInvoices, fn($i) => in_array($i['status'],['draft','sent','overdue']))) ?>)
        </button>
        <span class="text-muted small ms-2">Only draft/sent/overdue invoices will be cancelled. Paid ones are untouched.</span>
      </form>
      <?php else: ?>
      <div class="alert alert-success mt-3"><strong>No abnormal invoices found.</strong> All invoice totals are within the KES 9,999,999 limit.</div>
      <?php endif; ?>

      <h6 class="fw-bold mt-4">Current Module Prices (for reference)</h6>
      <table class="table table-sm table-bordered">
        <thead class="table-light"><tr><th>Module</th><th>Monthly (KES)</th><th>Annual (KES)</th></tr></thead>
        <tbody>
          <?php foreach ($modules as $m): ?>
          <tr>
            <td><?= htmlspecialchars($m['name']) ?></td>
            <td><?= number_format((float)$m['monthly_price'], 2) ?></td>
            <td><?= number_format((float)$m['annual_price'], 2) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="alert alert-warning mt-3">
        <strong>Remember:</strong> Delete <code>admin/fix-invoice-tax.php</code> after you're done.
      </div>
    </div>
  </div>
</div>
</body>
</html>
