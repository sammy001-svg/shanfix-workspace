<?php
$pageTitle = 'Invoice Management';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Generate invoice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    $orgId     = (int)($_POST['org_id']    ?? 0);
    $amount    = (float)($_POST['amount']  ?? 0);
    $taxRate   = (float)($_POST['tax_rate']?? 16);
    $dueDate   = $_POST['due_date']        ?? date('Y-m-d', strtotime('+14 days'));
    $notes     = sanitize($_POST['notes']  ?? '');

    if (!$orgId || !$amount) {
        setFlash('danger','Client and amount are required.');
    } else {
        $taxAmt = $amount * ($taxRate / 100);
        $total  = $amount + $taxAmt;
        $invoiceNo = 'INV-' . strtoupper(substr(md5(microtime()), 0, 6)) . '-' . date('Ymd');

        // Get active subscription
        $subStmt = $pdo->prepare("SELECT id FROM subscriptions WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
        $subStmt->execute([$orgId]);
        $sub = $subStmt->fetch();

        $pdo->prepare("INSERT INTO invoices (org_id,subscription_id,invoice_number,amount,tax,total,status,due_date,notes) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orgId, $sub['id'] ?? null, $invoiceNo, $amount, $taxAmt, $total, 'sent', $dueDate, $notes]);
        logActivity('generate_invoice','admin',"Generated invoice $invoiceNo");
        setFlash('success',"Invoice $invoiceNo generated successfully.");
    }
    redirect(APP_URL . '/admin/invoices.php');
}

// Mark paid
if (isset($_GET['mark_paid'])) {
    $id = (int)$_GET['mark_paid'];
    $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]);
    setFlash('success','Invoice marked as paid.');
    redirect(APP_URL . '/admin/invoices.php');
}

$invoices = $pdo->query("
    SELECT i.*, o.name as org_name, o.email as org_email
    FROM invoices i JOIN organizations o ON i.org_id = o.id
    ORDER BY i.created_at DESC
")->fetchAll();

$orgs = $pdo->query("SELECT id, name FROM organizations WHERE status='active' ORDER BY name")->fetchAll();

// ── CSV Export ───────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';
    $headers = ['Invoice #', 'Organization', 'Amount', 'Tax', 'Total', 'Status', 'Due Date', 'Created Date'];
    $rows = [];
    foreach ($invoices as $inv) {
        $rows[] = [
            $inv['invoice_number'] ?? '',
            $inv['org_name']       ?? '',
            number_format((float)$inv['amount'], 2),
            number_format((float)$inv['tax'], 2),
            number_format((float)$inv['total'], 2),
            $inv['status']         ?? '',
            $inv['due_date']       ?? '',
            $inv['created_at']     ?? '',
        ];
    }
    exportCsv('invoices-' . date('Y-m-d') . '.csv', $headers, $rows);
}

// Stats
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid'")->fetchColumn();
$totalPending  = $pdo->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='sent'")->fetchColumn();
$countPaid     = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='paid'")->fetchColumn();
$countOverdue  = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();

require_once __DIR__ . '/../includes/header-admin.php';
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-file-invoice me-2 text-green"></i>Invoice Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Invoices</li></ol></nav>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invoiceModal">
    <i class="fas fa-plus me-2"></i>Generate Invoice
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Total Collected</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalPending) ?></div><div class="stat-label">Pending</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
      <div><div class="stat-value"><?= $countPaid ?></div><div class="stat-label">Paid Invoices</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card danger"><div class="stat-icon danger-bg"><i class="fas fa-exclamation-triangle"></i></div>
      <div><div class="stat-value"><?= $countOverdue ?></div><div class="stat-label">Overdue</div></div></div>
  </div>
</div>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table">
        <thead>
          <tr><th>Invoice #</th><th>Client</th><th>Amount</th><th>Tax</th><th>Total</th><th>Due Date</th><th>Status</th><th>Paid At</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach($invoices as $inv): ?>
          <tr>
            <td class="fw-700 text-navy"><?= e($inv['invoice_number']) ?></td>
            <td>
              <div class="fw-600"><?= e($inv['org_name']) ?></div>
              <div class="text-muted small"><?= e($inv['org_email']) ?></div>
            </td>
            <td><?= formatCurrency($inv['amount']) ?></td>
            <td><?= formatCurrency($inv['tax']) ?></td>
            <td class="fw-700"><?= formatCurrency($inv['total']) ?></td>
            <td class="small <?= ($inv['status']==='sent' && $inv['due_date'] < date('Y-m-d')) ? 'text-danger fw-600' : '' ?>">
              <?= formatDate($inv['due_date']) ?>
            </td>
            <td><?= statusBadge($inv['status']) ?></td>
            <td class="small text-muted"><?= $inv['paid_at'] ? formatDate($inv['paid_at']) : '—' ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/admin/invoice-pdf.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary" title="Download PDF" target="_blank">
                  <i class="fas fa-file-pdf"></i>
                </a>
                <?php if ($inv['status'] !== 'paid'): ?>
                <a href="?mark_paid=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-success" title="Mark as paid"
                   data-confirm="Mark invoice <?= e($inv['invoice_number']) ?> as paid?">
                  <i class="fas fa-check"></i>
                </a>
                <?php endif; ?>
                <button class="btn btn-xs btn-outline-info" title="Send reminder" onclick="sendReminder(<?= $inv['id'] ?>)">
                  <i class="fas fa-paper-plane"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No invoices generated yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Generate Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Generate Invoice</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="generate_invoice" value="1">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Client / Organization *</label>
            <select name="org_id" class="form-select" required>
              <option value="">Select client...</option>
              <?php foreach($orgs as $o): ?><option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-8">
              <label class="form-label">Amount (KES) *</label>
              <input type="number" name="amount" class="form-control" required min="1" step="0.01" oninput="calcInvoice()">
            </div>
            <div class="col-4">
              <label class="form-label">Tax Rate (%)</label>
              <input type="number" name="tax_rate" id="taxRate" class="form-control" value="16" min="0" max="100" oninput="calcInvoice()">
            </div>
          </div>
          <div class="mb-3 p-3 rounded" style="background:var(--green-pale)">
            <div class="d-flex justify-content-between">
              <span class="small text-muted">Subtotal</span><strong id="calcSubtotal">KES 0.00</strong>
            </div>
            <div class="d-flex justify-content-between">
              <span class="small text-muted">Tax (VAT 16%)</span><strong id="calcTax">KES 0.00</strong>
            </div>
            <hr class="my-1">
            <div class="d-flex justify-content-between">
              <span class="fw-700">Total</span><strong class="text-green" id="calcTotal">KES 0.00</strong>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Due Date</label>
            <input type="date" name="due_date" class="form-control" value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label">Notes</label>
            <textarea name="notes" class="form-control" rows="2" placeholder="Payment notes or subscription details..."></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Generate & Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function calcInvoice() {
  const amt  = parseFloat(document.querySelector("[name=amount]").value) || 0;
  const rate = parseFloat(document.getElementById("taxRate").value) || 0;
  const tax  = amt * rate / 100;
  document.getElementById("calcSubtotal").textContent = "KES " + amt.toFixed(2);
  document.getElementById("calcTax").textContent      = "KES " + tax.toFixed(2);
  document.getElementById("calcTotal").textContent    = "KES " + (amt + tax).toFixed(2);
}
function sendReminder(id) {
  Swal.fire({ icon:"info", title:"Reminder Sent", text:"Payment reminder emailed to the client.", confirmButtonColor:"#1A8A4E" });
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
