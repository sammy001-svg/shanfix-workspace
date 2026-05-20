<?php
// AJAX: Return transaction line items as HTML for the view modal
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/functions.php';

if (!isLoggedIn()) {
    echo '<p class="text-danger p-3">Unauthorised.</p>';
    exit;
}

$user  = currentUser();
$orgId = (int)$user['org_id'];
$id    = (int)($_GET['id'] ?? 0);

if (!$id) {
    echo '<p class="text-danger p-3">Invalid transaction ID.</p>';
    exit;
}

// Verify transaction belongs to this org
$stmt = $pdo->prepare("SELECT * FROM acc_transactions WHERE id=? AND org_id=?");
$stmt->execute([$id, $orgId]);
$tx = $stmt->fetch();

if (!$tx) {
    echo '<p class="text-danger p-3">Transaction not found.</p>';
    exit;
}

// Fetch line items
$stmt = $pdo->prepare("
    SELECT ti.*, a.name AS account_name, a.code AS account_code
    FROM acc_transaction_items ti
    LEFT JOIN acc_accounts a ON ti.account_id = a.id
    WHERE ti.transaction_id = ?
    ORDER BY ti.id
");
$stmt->execute([$id]);
$lines = $stmt->fetchAll();
?>
<div class="p-3">
  <div class="mb-3">
    <div class="row">
      <div class="col-sm-6">
        <small class="text-muted">Reference:</small>
        <strong class="d-block"><?= htmlspecialchars($tx['reference'] ?? '—') ?></strong>
      </div>
      <div class="col-sm-3">
        <small class="text-muted">Date:</small>
        <strong class="d-block"><?= date('d M Y', strtotime($tx['date'])) ?></strong>
      </div>
      <div class="col-sm-3">
        <small class="text-muted">Status:</small>
        <div><?php
          $map = ['posted'=>'success','draft'=>'warning','voided'=>'secondary'];
          $cls = $map[$tx['status']] ?? 'secondary';
          echo "<span class='badge bg-{$cls}'>".ucfirst($tx['status'])."</span>";
        ?></div>
      </div>
    </div>
    <?php if ($tx['description']): ?>
    <div class="mt-2 text-muted small"><?= htmlspecialchars($tx['description']) ?></div>
    <?php endif; ?>
  </div>
  <table class="table table-bordered table-sm mb-0">
    <thead class="table-light">
      <tr>
        <th>Account</th>
        <th>Description</th>
        <th class="text-end">Debit</th>
        <th class="text-end">Credit</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($lines)): ?>
      <tr><td colspan="4" class="text-center text-muted py-3">No line items found.</td></tr>
      <?php else: foreach ($lines as $line): ?>
      <tr>
        <td>
          <?php if ($line['account_code']): ?><span class="text-muted small"><?= htmlspecialchars($line['account_code']) ?> —</span><?php endif; ?>
          <?= htmlspecialchars($line['account_name'] ?? '—') ?>
        </td>
        <td class="text-muted small"><?= htmlspecialchars($line['description'] ?? '') ?></td>
        <td class="text-end <?= (float)$line['debit'] > 0 ? 'text-success fw-semibold' : 'text-muted' ?>">
          <?= (float)$line['debit'] > 0 ? number_format((float)$line['debit'], 2) : '—' ?>
        </td>
        <td class="text-end <?= (float)$line['credit'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
          <?= (float)$line['credit'] > 0 ? number_format((float)$line['credit'], 2) : '—' ?>
        </td>
      </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot class="table-light fw-bold">
      <tr>
        <td colspan="2" class="text-end">Totals:</td>
        <td class="text-end text-success"><?= number_format((float)$tx['total_debit'],  2) ?></td>
        <td class="text-end text-danger"> <?= number_format((float)$tx['total_credit'], 2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
