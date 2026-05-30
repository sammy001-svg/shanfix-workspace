<?php
// ── Sales: Sales Returns / Refunds ─────────────────────────────
$moduleSlug='sales';$moduleName='Sales Management';$moduleIcon='fas fa-chart-line';$moduleColor='#1A8A4E';
$moduleNav=[['url'=>'index.php','icon'=>'fas fa-tachometer-alt','label'=>'Dashboard'],['url'=>'customers.php','icon'=>'fas fa-users','label'=>'Customers'],['url'=>'products.php','icon'=>'fas fa-box','label'=>'Products'],['url'=>'orders.php','icon'=>'fas fa-shopping-cart','label'=>'Orders'],['url'=>'quotes.php','icon'=>'fas fa-file-alt','label'=>'Quotes'],['url'=>'invoices.php','icon'=>'fas fa-file-invoice','label'=>'Invoices'],['url'=>'fulfillment.php','icon'=>'fas fa-truck','label'=>'Fulfillment'],['url'=>'commissions.php','icon'=>'fas fa-percent','label'=>'Commissions'],['url'=>'targets.php','icon'=>'fas fa-bullseye','label'=>'Targets'],['url'=>'returns.php','icon'=>'fas fa-undo-alt','label'=>'Returns'],['url'=>'payments.php','icon'=>'fas fa-money-check-alt','label'=>'Payments'],['url'=>'reports.php','icon'=>'fas fa-chart-bar','label'=>'Reports']];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        // Auto-ref RET-YYYY-NNNN
        $stmt = $pdo->prepare("SELECT COUNT(*)+1 FROM sales_returns WHERE org_id=? AND YEAR(created_at)=YEAR(NOW())");
        $stmt->execute([$orgId]);
        $ref = 'RET-' . date('Y') . '-' . str_pad((int)$stmt->fetchColumn(), 4, '0', STR_PAD_LEFT);

        $orderId      = (int)($_POST['order_id'] ?? 0) ?: null;
        $customerId   = (int)($_POST['customer_id'] ?? 0) ?: null;
        $reason       = sanitize($_POST['reason'] ?? '');
        $returnType   = in_array($_POST['return_type'] ?? '', ['refund','exchange','credit_note','repair']) ? $_POST['return_type'] : 'refund';
        $totalAmount  = (float)($_POST['total_amount'] ?? 0);
        $status       = in_array($_POST['status'] ?? '', ['pending','approved','rejected','processed']) ? $_POST['status'] : 'pending';
        $notes        = sanitize($_POST['notes'] ?? '');
        $items        = $_POST['items'] ?? [];

        $pdo->prepare("INSERT INTO sales_returns (org_id,ref,order_id,customer_id,reason,return_type,total_amount,status,notes) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$ref,$orderId,$customerId,$reason,$returnType,$totalAmount,$status,$notes]);
        $retId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("INSERT INTO sales_return_items (return_id,product_name,qty,unit_price,total_price) VALUES (?,?,?,?,?)");
        foreach ($items as $it) {
            $qty   = (float)($it['qty'] ?? 0);
            $price = (float)($it['unit_price'] ?? 0);
            if ($qty > 0) $ins->execute([$retId, sanitize($it['product_name'] ?? ''), $qty, $price, $qty*$price]);
        }
        setFlash('success', "Return $ref created.");
    } elseif ($action === 'status') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['pending','approved','rejected','processed']) ? $_POST['status'] : 'pending';
        $pdo->prepare("UPDATE sales_returns SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
        setFlash('success','Return status updated.');
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sales_return_items WHERE return_id=?")->execute([$id]);
        $pdo->prepare("DELETE FROM sales_returns WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Return deleted.');
    }
    redirect('returns.php');
}

// Dropdowns
$customers = $pdo->prepare("SELECT id,name FROM sales_customers WHERE org_id=? ORDER BY name"); $customers->execute([$orgId]); $customers=$customers->fetchAll();
$orders    = $pdo->prepare("SELECT id, CONCAT('#',id,' — ',order_date) as label FROM sales_orders WHERE org_id=? ORDER BY order_date DESC LIMIT 100"); $orders->execute([$orgId]); $orders=$orders->fetchAll();

$statusFilter = sanitize($_GET['status'] ?? '');
$sql = "SELECT r.*, c.name as customer_name FROM sales_returns r LEFT JOIN sales_customers c ON r.customer_id=c.id WHERE r.org_id=?";
$params = [$orgId];
if ($statusFilter) { $sql .= " AND r.status=?"; $params[] = $statusFilter; }
$sql .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $returns = $stmt->fetchAll();

$totalReturns   = countRows($pdo,'sales_returns','org_id=?',[$orgId]);
$pendingReturns = countRows($pdo,'sales_returns','org_id=? AND status=?',[$orgId,'pending']);
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM sales_returns WHERE org_id=? AND status='processed'"); $stmt->execute([$orgId]); $processedVal=(float)$stmt->fetchColumn();

$viewItems=[]; $viewReturn=null;
if(isset($_GET['view'])){ $stmt=$pdo->prepare("SELECT r.*,c.name as customer_name FROM sales_returns r LEFT JOIN sales_customers c ON r.customer_id=c.id WHERE r.id=? AND r.org_id=?"); $stmt->execute([(int)$_GET['view'],$orgId]); $viewReturn=$stmt->fetch(); if($viewReturn){ $stmt=$pdo->prepare("SELECT * FROM sales_return_items WHERE return_id=?"); $stmt->execute([$viewReturn['id']]); $viewItems=$stmt->fetchAll(); } }

$statusColors=['pending'=>'warning','approved'=>'info','rejected'=>'danger','processed'=>'success'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-undo-alt me-2" style="color:<?= $moduleColor ?>"></i>Sales Returns</h4>
    <p class="text-muted mb-0">Process customer return and refund requests</p>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#retModal">
    <i class="fas fa-plus me-1"></i>New Return
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(26,138,78,0.12);color:#1A8A4E"><i class="fas fa-undo-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalReturns ?></div><div class="stat-label">Total Returns</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingReturns ?></div><div class="stat-label">Pending Review</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(220,53,69,0.12);color:#dc3545"><i class="fas fa-dollar-sign"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($processedVal) ?></div><div class="stat-label">Refunds Processed</div></div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form class="row g-2 align-items-center" method="get">
      <div class="col-sm-3">
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach(['pending','approved','rejected','processed'] as $s): ?>
          <option value="<?= $s ?>" <?= $statusFilter===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
      <?php if($statusFilter): ?><div class="col-auto"><a href="returns.php" class="btn btn-sm btn-link">Clear</a></div><?php endif; ?>
    </form>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr><th class="ps-3">Ref</th><th>Customer</th><th>Type</th><th>Reason</th><th class="text-end">Amount</th><th class="text-center">Status</th><th class="text-center">Date</th><th class="text-end pe-3">Actions</th></tr>
        </thead>
        <tbody>
          <?php if(empty($returns)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No return records found.</td></tr>
          <?php else: foreach($returns as $r): ?>
          <tr>
            <td class="ps-3"><code><?= e($r['ref']) ?></code></td>
            <td><?= e($r['customer_name'] ?? '—') ?></td>
            <td><span class="badge bg-secondary"><?= ucfirst($r['return_type']) ?></span></td>
            <td class="text-muted small"><?= e($r['reason']) ?></td>
            <td class="text-end fw-bold"><?= formatCurrency($r['total_amount']) ?></td>
            <td class="text-center">
              <form method="post" class="d-inline">
                <?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                <select name="status" class="form-select form-select-sm d-inline-block w-auto border-0 bg-transparent fw-semibold text-<?= $statusColors[$r['status']]??'secondary' ?>" onchange="this.form.submit()">
                  <?php foreach(['pending','approved','rejected','processed'] as $s): ?><option value="<?= $s ?>" <?= $r['status']===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?>
                </select>
              </form>
            </td>
            <td class="text-center text-muted small"><?= formatDate($r['created_at']) ?></td>
            <td class="text-end pe-3">
              <a href="returns.php?view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-info me-1"><i class="fas fa-eye"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete?')">
                <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
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

<?php if($viewReturn): ?>
<div class="card border-0 shadow-sm mt-4">
  <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
    <h6 class="mb-0 fw-semibold"><i class="fas fa-undo-alt me-2 text-primary"></i>Return <?= e($viewReturn['ref']) ?> — <?= e($viewReturn['customer_name'] ?? 'Walk-in') ?></h6>
    <a href="returns.php" class="btn btn-sm btn-outline-secondary">Close</a>
  </div>
  <div class="card-body p-0">
    <table class="table table-bordered align-middle mb-0">
      <thead class="table-light"><tr><th class="ps-3">Product</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end pe-3">Line Total</th></tr></thead>
      <tbody>
        <?php foreach($viewItems as $li): ?>
        <tr><td class="ps-3"><?= e($li['product_name']) ?></td><td class="text-center"><?= $li['qty'] ?></td><td class="text-end"><?= formatCurrency($li['unit_price']) ?></td><td class="text-end pe-3 fw-bold"><?= formatCurrency($li['total_price']) ?></td></tr>
        <?php endforeach; ?>
        <tr class="table-light fw-bold"><td colspan="3" class="text-end ps-3">Total Refund</td><td class="text-end pe-3"><?= formatCurrency($viewReturn['total_amount']) ?></td></tr>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- New Return Modal -->
<div class="modal fade" id="retModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title"><i class="fas fa-undo-alt me-2"></i>New Sales Return</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <form method="post">
        <?= csrfField() ?><input type="hidden" name="action" value="save">
        <div class="modal-body">
          <div class="row g-3 mb-3">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Customer</label>
              <select name="customer_id" class="form-select">
                <option value="">— Walk-in —</option>
                <?php foreach($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Return Type</label>
              <select name="return_type" class="form-select">
                <option value="refund">Refund</option><option value="exchange">Exchange</option>
                <option value="credit_note">Credit Note</option><option value="repair">Repair</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" class="form-select">
                <option value="pending">Pending</option><option value="approved">Approved</option>
                <option value="rejected">Rejected</option><option value="processed">Processed</option>
              </select>
            </div>
            <div class="col-md-8">
              <label class="form-label fw-semibold">Reason for Return <span class="text-danger">*</span></label>
              <input type="text" name="reason" class="form-control" required>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Total Amount</label>
              <input type="number" name="total_amount" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-12"><label class="form-label fw-semibold">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
          </div>
          <h6 class="fw-semibold mb-2">Returned Items</h6>
          <div class="table-responsive">
            <table class="table table-bordered align-middle" id="retLineTable">
              <thead class="table-light"><tr><th>Product Name</th><th style="width:90px">Qty</th><th style="width:130px">Unit Price</th><th style="width:130px">Line Total</th><th style="width:50px"></th></tr></thead>
              <tbody id="retLineBody">
                <tr>
                  <td><input type="text" name="items[0][product_name]" class="form-control form-control-sm" required></td>
                  <td><input type="number" name="items[0][qty]" class="form-control form-control-sm qty" min="0" step="0.01" value="1" oninput="calcRetLine(this)"></td>
                  <td><input type="number" name="items[0][unit_price]" class="form-control form-control-sm price" min="0" step="0.01" value="0" oninput="calcRetLine(this)"></td>
                  <td><input type="text" class="form-control form-control-sm line-total bg-light" readonly value="0.00"></td>
                  <td></td>
                </tr>
              </tbody>
            </table>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addRetLine()"><i class="fas fa-plus me-1"></i>Add Line</button>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Create Return</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
var retIdx=1;
function calcRetLine(el){var row=el.closest('tr');var qty=parseFloat(row.querySelector('.qty').value)||0;var price=parseFloat(row.querySelector('.price').value)||0;row.querySelector('.line-total').value=(qty*price).toFixed(2);}
function addRetLine(){var i=retIdx++;var row=document.createElement('tr');row.innerHTML='<td><input type="text" name="items['+i+'][product_name]" class="form-control form-control-sm" required></td><td><input type="number" name="items['+i+'][qty]" class="form-control form-control-sm qty" min="0" step="0.01" value="1" oninput="calcRetLine(this)"></td><td><input type="number" name="items['+i+'][unit_price]" class="form-control form-control-sm price" min="0" step="0.01" value="0" oninput="calcRetLine(this)"></td><td><input type="text" class="form-control form-control-sm line-total bg-light" readonly value="0.00"></td><td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\'tr\').remove()"><i class="fas fa-times"></i></button></td>';document.getElementById('retLineBody').appendChild(row);}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
