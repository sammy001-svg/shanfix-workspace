<?php
// Force OPCache to reload this file so the CAST fix takes effect immediately
if (function_exists('opcache_invalidate')) opcache_invalidate(__FILE__, true);

$pageTitle = 'Invoice Management';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Ensure CSRF token exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// ── Bulk CSV Export (POST with selected IDs) ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_export_csv'])) {
    verifyCsrf();
    require_once __DIR__ . '/../includes/export.php';
    $ids = array_map('intval', json_decode($_POST['selected_ids'] ?? '[]', true));
    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $expStmt = $pdo->prepare("
            SELECT
                i.id, i.invoice_number,
                CAST(i.amount AS DECIMAL(12,2)) AS amount,
                CAST(i.tax    AS DECIMAL(12,2)) AS tax,
                CAST(i.total  AS DECIMAL(12,2)) AS total,
                i.status, i.due_date, i.paid_at, i.notes, i.created_at,
                o.name AS org_name, o.email AS org_email
            FROM invoices i
            JOIN organizations o ON i.org_id = o.id
            WHERE i.id IN ($placeholders)
            ORDER BY i.created_at DESC
        ");
        $expStmt->execute($ids);
        $expInvoices = $expStmt->fetchAll();
    } else {
        $expInvoices = [];
    }
    $csvHeaders = ['Invoice #','Organization','Email','Amount','Tax','Total','Status','Due Date','Paid At','Created'];
    $csvRows = [];
    foreach ($expInvoices as $inv) {
        $csvRows[] = [
            $inv['invoice_number'] ?? '',
            $inv['org_name']       ?? '',
            $inv['org_email']      ?? '',
            number_format((float)$inv['amount'], 2),
            number_format((float)$inv['tax'], 2),
            number_format((float)$inv['total'], 2),
            $inv['status']         ?? '',
            $inv['due_date']       ?? '',
            $inv['paid_at']        ?? '',
            $inv['created_at']     ?? '',
        ];
    }
    exportCsv('invoices-selected-' . date('Y-m-d') . '.csv', $csvHeaders, $csvRows);
}

// ── Generate invoice ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_invoice'])) {
    verifyCsrf();
    $orgId   = (int)($_POST['org_id']    ?? 0);
    $amount  = (float)($_POST['amount']  ?? 0);
    $taxRate = (float)($_POST['tax_rate']?? 16);
    $dueDate = $_POST['due_date']        ?? date('Y-m-d', strtotime('+14 days'));
    $notes   = sanitize($_POST['notes']  ?? '');

    if (!$orgId || !$amount) {
        setFlash('danger', 'Client and amount are required.');
    } else {
        $taxAmt    = $amount * ($taxRate / 100);
        $total     = $amount + $taxAmt;
        $settings  = getSettings(['invoice_prefix']);
        $prefix    = strtoupper($settings['invoice_prefix'] ?? 'INV');
        $invoiceNo = $prefix . '-' . strtoupper(substr(md5(microtime()), 0, 6)) . '-' . date('Ymd');

        $subStmt = $pdo->prepare("SELECT id FROM subscriptions WHERE org_id=? ORDER BY created_at DESC LIMIT 1");
        $subStmt->execute([$orgId]);
        $sub = $subStmt->fetch();

        $pdo->prepare("INSERT INTO invoices (org_id,subscription_id,invoice_number,amount,tax,total,status,due_date,notes) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$orgId, $sub['id'] ?? null, $invoiceNo, $amount, $taxAmt, $total, 'sent', $dueDate, $notes]);

        // Send invoice email
        require_once __DIR__ . '/../includes/mailer.php';
        $orgRow = $pdo->prepare("SELECT name, email FROM organizations WHERE id=?");
        $orgRow->execute([$orgId]);
        $orgRow = $orgRow->fetch();
        if ($orgRow && $orgRow['email']) {
            $invData = ['invoice_number' => $invoiceNo, 'amount' => $amount, 'tax' => $taxAmt, 'total' => $total, 'due_date' => $dueDate];
            mailer()->sendInvoice($orgRow['email'], $orgRow['name'], $invData);
        }

        logActivity('generate_invoice', 'admin', "Generated invoice $invoiceNo");
        setFlash('success', "Invoice $invoiceNo generated successfully.");
    }
    redirect(APP_URL . '/admin/invoices.php');
}

// ── Filters ───────────────────────────────────────────────────────
$allowedStatuses = ['sent', 'paid', 'overdue', 'cancelled'];
$fStatus   = in_array($_GET['status'] ?? '', $allowedStatuses) ? $_GET['status'] : '';
$fOrg      = (int)($_GET['org_id'] ?? 0);
$fFrom     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$fTo       = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : '';
$activeTab = ($_GET['tab'] ?? '') === 'reconciliation' ? 'reconciliation' : 'invoices';

// ── Main invoices query ───────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($fStatus) { $where[] = 'i.status=?';         $params[] = $fStatus; }
if ($fOrg)    { $where[] = 'i.org_id=?';         $params[] = $fOrg; }
if ($fFrom)   { $where[] = 'i.created_at >= ?';  $params[] = $fFrom . ' 00:00:00'; }
if ($fTo)     { $where[] = 'i.created_at <= ?';  $params[] = $fTo   . ' 23:59:59'; }
$whereSQL = implode(' AND ', $where);

$invStmt = $pdo->prepare("
    SELECT
        i.id,
        i.org_id,
        i.subscription_id,
        i.module_id,
        i.invoice_number,
        CAST(i.amount AS DECIMAL(12,2)) AS amount,
        CAST(i.tax    AS DECIMAL(12,2)) AS tax,
        CAST(i.total  AS DECIMAL(12,2)) AS total,
        i.status,
        i.due_date,
        i.paid_at,
        i.notes,
        i.created_at,
        o.name  AS org_name,
        o.email AS org_email
    FROM invoices i
    JOIN organizations o ON i.org_id = o.id
    WHERE $whereSQL
    ORDER BY i.created_at DESC
");
$invStmt->execute($params);
$invoices = $invStmt->fetchAll();

$orgs = $pdo->query("SELECT id, name FROM organizations WHERE status='active' ORDER BY name")->fetchAll();

// ── Filtered CSV export (all matching rows) ───────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_once __DIR__ . '/../includes/export.php';
    $csvHeaders = ['Invoice #','Organization','Email','Amount','Tax','Total','Status','Due Date','Paid At','Created'];
    $csvRows = [];
    foreach ($invoices as $inv) {
        $csvRows[] = [
            $inv['invoice_number'] ?? '',
            $inv['org_name']       ?? '',
            $inv['org_email']      ?? '',
            number_format((float)$inv['amount'], 2),
            number_format((float)$inv['tax'], 2),
            number_format((float)$inv['total'], 2),
            $inv['status']         ?? '',
            $inv['due_date']       ?? '',
            $inv['paid_at']        ?? '',
            $inv['created_at']     ?? '',
        ];
    }
    exportCsv('invoices-' . date('Y-m-d') . '.csv', $csvHeaders, $csvRows);
}

// ── Stats ─────────────────────────────────────────────────────────
$totalRevenue = $pdo->query("SELECT COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) FROM invoices WHERE status='paid'")->fetchColumn();
$totalPending = $pdo->query("SELECT COALESCE(SUM(CAST(total AS DECIMAL(12,2))),0) FROM invoices WHERE status='sent'")->fetchColumn();
$countPaid    = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='paid'")->fetchColumn();
$countOverdue = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='overdue'")->fetchColumn();

// ── Reconciliation ────────────────────────────────────────────────
$reconciliation = [];
try {
    $recStmt = $pdo->query("
        SELECT
            pc.id            AS cb_id,
            pc.checkout_id,
            pc.mpesa_receipt,
            pc.amount        AS cb_amount,
            pc.phone,
            pc.processed_at,
            pc.status        AS cb_status,
            i.id             AS inv_id,
            i.invoice_number,
            i.total          AS inv_total,
            i.status         AS inv_status,
            o.name           AS org_name
        FROM payment_callbacks pc
        LEFT JOIN invoices i      ON pc.invoice_id = i.id
        LEFT JOIN organizations o ON i.org_id = o.id
        WHERE pc.provider = 'kopokopo'
        ORDER BY pc.processed_at DESC
        LIMIT 200
    ");
    $reconciliation = $recStmt->fetchAll();
} catch (Exception $e) {}

foreach ($reconciliation as &$r) {
    if (!$r['inv_id']) {
        $r['flag'] = 'no_invoice';
    } elseif ($r['inv_status'] !== 'paid') {
        $r['flag'] = 'unpaid';
    } elseif (abs((float)$r['cb_amount'] - (float)$r['inv_total']) > 1) {
        $r['flag'] = 'mismatch';
    } else {
        $r['flag'] = 'ok';
    }
}
unset($r);

$flagCount = count(array_filter($reconciliation, fn($r) => $r['flag'] !== 'ok'));

require_once __DIR__ . '/../includes/header-admin.php';
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-file-invoice me-2 text-green"></i>Invoice Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Invoices</li>
    </ol></nav>
  </div>
  <div class="d-flex gap-2">
    <a href="?tab=invoices&export=csv<?= $fStatus ? '&status='.urlencode($fStatus) : '' ?><?= $fOrg ? '&org_id='.$fOrg : '' ?><?= $fFrom ? '&from='.urlencode($fFrom) : '' ?><?= $fTo ? '&to='.urlencode($fTo) : '' ?>"
       class="btn btn-outline-secondary">
      <i class="fas fa-download me-2"></i>Export CSV
    </a>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invoiceModal">
      <i class="fas fa-plus me-2"></i>Generate Invoice
    </button>
  </div>
</div>

<!-- DEBUG-MARKER-v2 | totalRevenue=<?= $totalRevenue ?> | firstInv_amount=<?= isset($invoices[0]) ? $invoices[0]['amount'] : 'none' ?> | phpfile=<?= basename(__FILE__) ?> | ts=<?= time() ?> -->

<!-- KPI Cards -->
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

<!-- Tab Nav -->
<ul class="nav nav-tabs mb-3">
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'invoices' ? 'active' : '' ?>" href="?tab=invoices">
      <i class="fas fa-file-invoice me-1"></i>Invoices
    </a>
  </li>
  <li class="nav-item">
    <a class="nav-link <?= $activeTab === 'reconciliation' ? 'active' : '' ?>" href="?tab=reconciliation">
      <i class="fas fa-search-dollar me-1"></i>Reconciliation
      <?php if ($flagCount): ?>
        <span class="badge bg-danger ms-1"><?= $flagCount ?></span>
      <?php endif; ?>
    </a>
  </li>
</ul>

<?php if ($activeTab === 'invoices'): ?>

<!-- Filter Bar -->
<form method="GET" class="card mb-3">
  <div class="card-body py-2">
    <div class="row g-2 align-items-end">
      <input type="hidden" name="tab" value="invoices">
      <div class="col-sm-3">
        <label class="form-label small mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['sent' => 'Sent', 'paid' => 'Paid', 'overdue' => 'Overdue', 'cancelled' => 'Cancelled'] as $v => $l): ?>
            <option value="<?= $v ?>" <?= $fStatus === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small mb-1">Organization</label>
        <select name="org_id" class="form-select form-select-sm">
          <option value="">All Organizations</option>
          <?php foreach ($orgs as $o): ?>
            <option value="<?= $o['id'] ?>" <?= $fOrg === (int)$o['id'] ? 'selected' : '' ?>><?= e($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">From</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fFrom) ?>">
      </div>
      <div class="col-sm-2">
        <label class="form-label small mb-1">To</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($fTo) ?>">
      </div>
      <div class="col-auto d-flex gap-1">
        <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="?tab=invoices" class="btn btn-sm btn-outline-secondary">Clear</a>
      </div>
    </div>
  </div>
</form>

<!-- Bulk Action Toolbar (hidden until rows are selected) -->
<div id="bulkBar" class="d-none mb-2 p-2 rounded border border-primary" style="background:#f0f7ff">
  <div class="d-flex align-items-center gap-2 flex-wrap">
    <span id="bulkCount" class="fw-semibold text-primary small me-1"></span>
    <button class="btn btn-sm btn-success" onclick="bulkAction('mark_paid')">
      <i class="fas fa-check me-1"></i>Mark Paid
    </button>
    <button class="btn btn-sm btn-warning text-dark" onclick="bulkAction('mark_overdue')">
      <i class="fas fa-exclamation-triangle me-1"></i>Mark Overdue
    </button>
    <button class="btn btn-sm btn-info text-dark" onclick="bulkAction('send_reminder')">
      <i class="fas fa-paper-plane me-1"></i>Send Reminder
    </button>
    <button class="btn btn-sm btn-outline-secondary" onclick="bulkAction('export_csv')">
      <i class="fas fa-download me-1"></i>Export Selected
    </button>
    <button class="btn btn-sm btn-outline-danger ms-auto" onclick="clearSelection()">
      <i class="fas fa-times me-1"></i>Deselect All
    </button>
  </div>
</div>

<!-- Invoice Table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0" id="invoiceTable">
        <thead class="table-light">
          <tr>
            <th style="width:36px">
              <input type="checkbox" id="selectAll" class="form-check-input" title="Select all">
            </th>
            <th>Invoice #</th>
            <th>Client</th>
            <th>Amount</th>
            <th>Tax</th>
            <th>Total</th>
            <th>Due Date</th>
            <th>Status</th>
            <th>Paid At</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invoices as $inv): ?>
          <tr data-id="<?= $inv['id'] ?>">
            <td><input type="checkbox" class="form-check-input row-check" value="<?= $inv['id'] ?>"></td>
            <td class="fw-700 text-navy"><?= e($inv['invoice_number']) ?></td>
            <td>
              <div class="fw-600"><?= e($inv['org_name']) ?></div>
              <div class="text-muted small"><?= e($inv['org_email']) ?></div>
            </td>
            <td><?= formatCurrency($inv['amount']) ?></td>
            <td><?= formatCurrency($inv['tax']) ?></td>
            <td class="fw-700"><?= formatCurrency($inv['total']) ?></td>
            <td class="small <?= ($inv['status'] === 'sent' && ($inv['due_date'] ?? '') < date('Y-m-d')) ? 'text-danger fw-600' : '' ?>">
              <?= formatDate($inv['due_date']) ?>
            </td>
            <td><?= statusBadge($inv['status']) ?></td>
            <td class="small text-muted"><?= $inv['paid_at'] ? formatDate($inv['paid_at']) : '—' ?></td>
            <td>
              <div class="d-flex gap-1">
                <a href="<?= APP_URL ?>/admin/invoice-pdf.php?id=<?= $inv['id'] ?>"
                   class="btn btn-xs btn-outline-primary" title="Download PDF" target="_blank">
                  <i class="fas fa-file-pdf"></i>
                </a>
                <?php if ($inv['status'] !== 'paid'): ?>
                <button class="btn btn-xs btn-outline-success" title="Mark as paid"
                        onclick="markPaid(<?= $inv['id'] ?>, this)">
                  <i class="fas fa-check"></i>
                </button>
                <?php endif; ?>
                <button class="btn btn-xs btn-outline-info" title="Send reminder"
                        onclick="sendReminder(<?= $inv['id'] ?>, this)">
                  <i class="fas fa-paper-plane"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($invoices)): ?>
          <tr><td colspan="10" class="text-center py-5 text-muted">
            <i class="fas fa-file-invoice fa-2x mb-2 d-block opacity-25"></i>
            No invoices found<?= $fStatus || $fOrg || $fFrom || $fTo ? ' for current filters' : ' yet' ?>.
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Hidden form for bulk CSV export -->
<form id="bulkCsvForm" method="POST" style="display:none">
  <input type="hidden" name="_token" value="<?= $csrfToken ?>">
  <input type="hidden" name="bulk_export_csv" value="1">
  <input type="hidden" name="selected_ids" id="bulkCsvIds">
</form>

<?php else: // ── Reconciliation Tab ─────────────────────────────── ?>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h6 class="mb-0"><i class="fas fa-search-dollar me-2 text-navy"></i>M-Pesa Payment Reconciliation</h6>
    <div class="d-flex gap-2 flex-wrap">
      <?php
        $cNoInv   = count(array_filter($reconciliation, fn($r) => $r['flag'] === 'no_invoice'));
        $cUnpaid  = count(array_filter($reconciliation, fn($r) => $r['flag'] === 'unpaid'));
        $cMismatch= count(array_filter($reconciliation, fn($r) => $r['flag'] === 'mismatch'));
        $cOk      = count(array_filter($reconciliation, fn($r) => $r['flag'] === 'ok'));
      ?>
      <span class="badge bg-danger fs-xs"><i class="fas fa-unlink me-1"></i><?= $cNoInv ?> No Invoice</span>
      <span class="badge bg-warning text-dark fs-xs"><i class="fas fa-exclamation me-1"></i><?= $cUnpaid ?> Invoice Unpaid</span>
      <span class="badge bg-info text-dark fs-xs"><i class="fas fa-not-equal me-1"></i><?= $cMismatch ?> Amount Mismatch</span>
      <span class="badge bg-success fs-xs"><i class="fas fa-check me-1"></i><?= $cOk ?> OK</span>
    </div>
  </div>
  <div class="card-body p-0">
    <?php if (empty($reconciliation)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-search-dollar fa-2x mb-2 d-block opacity-25"></i>
      No M-Pesa callback records yet. Payments will appear here after clients pay via KopoKopo.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0">
        <thead class="table-light">
          <tr>
            <th>M-Pesa Receipt</th>
            <th>Paid Amount</th>
            <th>Phone</th>
            <th>Invoice #</th>
            <th>Inv. Total</th>
            <th>Inv. Status</th>
            <th>Organization</th>
            <th>Received</th>
            <th>Flag</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reconciliation as $r):
            $rowClass = match($r['flag']) {
                'no_invoice' => 'table-danger',
                'unpaid'     => 'table-warning',
                'mismatch'   => 'table-info',
                default      => '',
            };
            $flagBadge = match($r['flag']) {
                'no_invoice' => '<span class="badge bg-danger">No Invoice</span>',
                'unpaid'     => '<span class="badge bg-warning text-dark">Invoice Unpaid</span>',
                'mismatch'   => '<span class="badge bg-info text-dark">Amount Mismatch</span>',
                default      => '<span class="badge bg-success">OK</span>',
            };
          ?>
          <tr class="<?= $rowClass ?>">
            <td class="fw-semibold small font-monospace"><?= e($r['mpesa_receipt'] ?: '—') ?></td>
            <td class="fw-semibold"><?= $r['cb_amount'] ? formatCurrency($r['cb_amount']) : '—' ?></td>
            <td class="small"><?= e($r['phone'] ?: '—') ?></td>
            <td class="small"><?= e($r['invoice_number'] ?: '—') ?></td>
            <td><?= $r['inv_total'] ? formatCurrency($r['inv_total']) : '—' ?></td>
            <td><?= $r['inv_status'] ? statusBadge($r['inv_status']) : '—' ?></td>
            <td class="small"><?= e($r['org_name'] ?: '—') ?></td>
            <td class="small text-muted"><?= formatDate($r['processed_at']) ?></td>
            <td><?= $flagBadge ?></td>
            <td>
              <?php if ($r['flag'] === 'unpaid' && $r['inv_id']): ?>
              <button class="btn btn-xs btn-success" onclick="markPaid(<?= $r['inv_id'] ?>, this)" title="Mark invoice paid">
                <i class="fas fa-check"></i>
              </button>
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

<?php endif; ?>

<!-- Generate Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Generate Invoice</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="generate_invoice" value="1">
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Client / Organization *</label>
            <select name="org_id" class="form-select" required>
              <option value="">Select client…</option>
              <?php foreach ($orgs as $o): ?>
                <option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option>
              <?php endforeach; ?>
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
              <span class="small text-muted">Tax (VAT)</span><strong id="calcTax">KES 0.00</strong>
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
            <textarea name="notes" class="form-control" rows="2" placeholder="Payment notes or subscription details…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Generate &amp; Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$CSRF = $csrfToken;
$extraJs = <<<JS
<script>
const CSRF_TOKEN = <?= json_encode($CSRF) ?>;
const AJAX_URL   = <?= json_encode(APP_URL . '/admin/ajax.php') ?>;

// ── Invoice total calculator ──────────────────────────────────────
function calcInvoice() {
  const amt  = parseFloat(document.querySelector('[name=amount]').value) || 0;
  const rate = parseFloat(document.getElementById('taxRate').value) || 0;
  const tax  = amt * rate / 100;
  document.getElementById('calcSubtotal').textContent = 'KES ' + amt.toFixed(2);
  document.getElementById('calcTax').textContent      = 'KES ' + tax.toFixed(2);
  document.getElementById('calcTotal').textContent    = 'KES ' + (amt + tax).toFixed(2);
}

// ── Checkbox / bulk selection ─────────────────────────────────────
const selectAll = document.getElementById('selectAll');
if (selectAll) {
  selectAll.addEventListener('change', function () {
    document.querySelectorAll('.row-check').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
  });
  document.querySelectorAll('.row-check').forEach(cb =>
    cb.addEventListener('change', updateBulkBar)
  );
}

function getCheckedIds() {
  return [...document.querySelectorAll('.row-check:checked')].map(cb => parseInt(cb.value));
}

function updateBulkBar() {
  const ids = getCheckedIds();
  const bar = document.getElementById('bulkBar');
  if (!bar) return;
  if (ids.length > 0) {
    bar.classList.remove('d-none');
    document.getElementById('bulkCount').textContent = ids.length + ' invoice' + (ids.length > 1 ? 's' : '') + ' selected';
  } else {
    bar.classList.add('d-none');
  }
}

function clearSelection() {
  document.querySelectorAll('.row-check, #selectAll').forEach(cb => cb.checked = false);
  updateBulkBar();
}

// ── Mark single invoice paid (AJAX) ──────────────────────────────
function markPaid(id, btn) {
  Swal.fire({
    title: 'Mark as Paid?',
    text: 'This will mark the invoice as paid immediately.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#1A8A4E',
    confirmButtonText: 'Yes, mark paid',
  }).then(result => {
    if (!result.isConfirmed) return;
    btn.disabled = true;
    fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'mark_invoice_paid', id: id, _token: CSRF_TOKEN }),
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        Swal.fire({ icon: 'success', title: 'Marked Paid', timer: 1200, showConfirmButton: false })
          .then(() => location.reload());
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Failed to update.' });
        btn.disabled = false;
      }
    })
    .catch(() => { Swal.fire({ icon: 'error', title: 'Network error' }); btn.disabled = false; });
  });
}

// ── Send reminder email (single) ─────────────────────────────────
function sendReminder(id, btn) {
  Swal.fire({
    title: 'Send Payment Reminder?',
    text: 'An email reminder will be sent to the client.',
    icon: 'question',
    showCancelButton: true,
    confirmButtonColor: '#0dcaf0',
    confirmButtonText: 'Send',
  }).then(result => {
    if (!result.isConfirmed) return;
    btn.disabled = true;
    fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'send_invoice_reminder', id: id, _token: CSRF_TOKEN }),
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        Swal.fire({ icon: 'success', title: 'Reminder Sent', text: res.message || 'Email delivered.', timer: 2000, showConfirmButton: false });
      } else {
        Swal.fire({ icon: 'error', title: 'Failed', text: res.error || 'Could not send email.' });
      }
      btn.disabled = false;
    })
    .catch(() => { Swal.fire({ icon: 'error', title: 'Network error' }); btn.disabled = false; });
  });
}

// ── Bulk actions ──────────────────────────────────────────────────
function bulkAction(action) {
  const ids = getCheckedIds();
  if (!ids.length) return;

  if (action === 'export_csv') {
    document.getElementById('bulkCsvIds').value = JSON.stringify(ids);
    document.getElementById('bulkCsvForm').submit();
    return;
  }

  const titles = {
    mark_paid:     { title: 'Mark ' + ids.length + ' Invoice(s) Paid?',     icon: 'question', confirmColor: '#1A8A4E', btn: 'Mark Paid' },
    mark_overdue:  { title: 'Mark ' + ids.length + ' Invoice(s) Overdue?',  icon: 'warning',  confirmColor: '#e67e22', btn: 'Mark Overdue' },
    send_reminder: { title: 'Send Reminder to ' + ids.length + ' Client(s)?', icon: 'question', confirmColor: '#0dcaf0', btn: 'Send Reminders' },
  };
  const cfg = titles[action];
  if (!cfg) return;

  Swal.fire({
    title: cfg.title,
    icon: cfg.icon,
    showCancelButton: true,
    confirmButtonColor: cfg.confirmColor,
    confirmButtonText: cfg.btn,
  }).then(result => {
    if (!result.isConfirmed) return;

    const ajaxAction = action === 'mark_paid'     ? 'bulk_mark_paid'     :
                       action === 'mark_overdue'  ? 'bulk_mark_overdue'  :
                       'bulk_send_reminder';

    Swal.fire({ title: 'Processing…', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    fetch(AJAX_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: ajaxAction, ids: ids, _token: CSRF_TOKEN }),
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        Swal.fire({ icon: 'success', title: 'Done', text: res.message || 'Operation completed.', timer: 1800, showConfirmButton: false })
          .then(() => location.reload());
      } else {
        Swal.fire({ icon: 'error', title: 'Error', text: res.error || 'Operation failed.' });
      }
    })
    .catch(() => Swal.fire({ icon: 'error', title: 'Network error' }));
  });
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
?>
