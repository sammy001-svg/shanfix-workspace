<?php
// ── Sales: Invoice Payments ─────────────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        // Auto-ref PMT-YYYY-NNNN
        $stmt = $pdo->prepare("SELECT COUNT(*)+1 FROM sales_payments WHERE org_id=? AND YEAR(created_at)=YEAR(NOW())");
        $stmt->execute([$orgId]);
        $ref = 'PMT-' . date('Y') . '-' . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

        $invoiceId  = (int)($_POST['invoice_id'] ?? 0) ?: null;
        $customerId = (int)($_POST['customer_id'] ?? 0) ?: null;
        $amount     = (float)($_POST['amount'] ?? 0);
        $method     = in_array($_POST['method'] ?? '', ['cash','mpesa','card','bank','cheque']) ? $_POST['method'] : 'cash';
        $payDate    = sanitize($_POST['payment_date'] ?? date('Y-m-d'));
        $notes      = sanitize($_POST['notes'] ?? '');

        $pdo->prepare("INSERT INTO sales_payments (org_id,ref,invoice_id,customer_id,amount,method,payment_date,notes) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$ref,$invoiceId,$customerId,$amount,$method,$payDate,$notes]);
        setFlash('success', "Payment $ref recorded.");
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM sales_payments WHERE id=? AND org_id=?")->execute([(int)$_POST['id'],$orgId]);
        setFlash('success','Payment deleted.');
    }
    redirect('payments.php');
}

$customers = $pdo->prepare("SELECT id,name FROM sales_customers WHERE org_id=? ORDER BY name"); $customers->execute([$orgId]); $customers=$customers->fetchAll();
$invoices  = $pdo->prepare("SELECT id, CONCAT('INV#',id,' (',invoice_date,')') as label FROM sales_invoices WHERE org_id=? ORDER BY invoice_date DESC LIMIT 100"); $invoices->execute([$orgId]); $invoices=$invoices->fetchAll();

$methodFilter = sanitize($_GET['method'] ?? '');
$monthFilter  = sanitize($_GET['month'] ?? '');
$sql = "SELECT p.*, c.name as customer_name FROM sales_payments p LEFT JOIN sales_customers c ON p.customer_id=c.id WHERE p.org_id=?";
$params = [$orgId];
if ($methodFilter) { $sql .= " AND p.method=?"; $params[] = $methodFilter; }
if ($monthFilter)  { $sql .= " AND DATE_FORMAT(p.payment_date,'%Y-%m')=?"; $params[] = $monthFilter; }
$sql .= " ORDER BY p.payment_date DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $payments=$stmt->fetchAll();

$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_payments WHERE org_id=?"); $stmt->execute([$orgId]); $totalReceived=(float)$stmt->fetchColumn();
$stmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM sales_payments WHERE org_id=? AND DATE_FORMAT(payment_date,'%Y-%m')=DATE_FORMAT(NOW(),'%Y-%m')"); $stmt->execute([$orgId]); $thisMonth=(float)$stmt->fetchColumn();
$totalCount=countRows($pdo,'sales_payments','org_id=?',[$orgId]);

$methodColors=['cash'=>'success','mpesa'=>'primary','card'=>'info','bank'=>'secondary','cheque'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-money-check-alt me-2" style="color:<?= $moduleColor ?>"></i>Invoice Payments</h4>
    <p class="text-muted mb-0">Record and track customer invoice payments and receipts</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#payModal">
    <i class="fas fa-plus me-1"></i>Record Payment
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-money-check-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalCount ?></div><div class="stat-label">Total Payments</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalReceived) ?></div><div class="stat-label">Total Received</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-calendar-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($thisMonth) ?></div><div class="stat-label">This Month</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="method" class="form-select form-select-sm">
          <option value="">All Methods</option>
          <?php foreach(['cash','mpesa','card','bank','cheque'] as $m): ?><option value="<?= $m ?>" <?= $methodFilter===$m?'selected':'' ?>><?= ucfirst($m) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3"><input type="month" name="month" class="form-control form-control-sm" value="<?= e($monthFilter) ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($methodFilter||$monthFilter): ?><div class="col-auto"><a href="payments.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr><th class="ps-3">Ref</th><th>Customer</th><th class="text-center">Method</th><th class="text-end">Amount</th><th class="text-center">Date</th><th>Notes</th><th class="text-end pe-3">Actions</th></tr>
        </thead>
        <tbody>
          <?php if(empty($payments)): ?>
          <tr><td colspan="7" class="text-center text-muted py-4">No payments recorded.</td></tr>
          <?php else: foreach($payments as $p): ?>
          <tr>
            <td class="ps-3"><code><?= e($p['ref']) ?></code></td>
            <td><?= e($p['customer_name'] ?? '—') ?></td>
            <td class="text-center"><span class="badge bg-<?= $methodColors[$p['method']]??'secondary' ?>"><?= strtoupper($p['method']) ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency($p['amount']) ?></td>
            <td class="text-center"><?= formatDate($p['payment_date']) ?></td>
            <td class="text-muted small"><?= e($p['notes']) ?></td>
            <td class="text-end pe-3">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete payment?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Payment Modal -->
<div class="modal fade" id="payModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-money-check-alt me-2"></i>Record Payment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Customer</label>
              <select name="customer_id" class="form-select"><option value="">— Walk-in —</option>
                <?php foreach($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Invoice</label>
              <select name="invoice_id" class="form-select"><option value="">— None —</option>
                <?php foreach($invoices as $inv): ?><option value="<?= $inv['id'] ?>"><?= e($inv['label']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Amount (KES) <span class="text-danger">*</span></label><input type="number" name="amount" class="form-control" step="0.01" min="0" required></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Payment Method</label>
              <select name="method" class="form-select">
                <option value="cash">Cash</option><option value="mpesa">M-Pesa</option><option value="card">Card</option><option value="bank">Bank Transfer</option><option value="cheque">Cheque</option>
              </select>
            </div>
            <div class="col-md-6"><label class="form-label fw-semibold">Payment Date</label><input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Payment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
