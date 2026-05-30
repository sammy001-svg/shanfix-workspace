<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',  'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',          'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text','label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    // Save quote (header + items)
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $contactId  = (int)($_POST['contact_id'] ?? 0) ?: null;
        $dealId     = (int)($_POST['deal_id'] ?? 0) ?: null;
        $title      = sanitize($_POST['title'] ?? '');
        $taxRate    = (float)($_POST['tax_rate'] ?? 16);
        $discount   = (float)($_POST['discount'] ?? 0);
        $validUntil = $_POST['valid_until'] ?? null;
        $notes      = sanitize($_POST['notes'] ?? '');
        $terms      = sanitize($_POST['terms'] ?? '');
        $status     = in_array($_POST['status'] ?? '', ['draft','sent','accepted','rejected','expired']) ? $_POST['status'] : 'draft';

        // Items
        $descs  = $_POST['item_desc']  ?? [];
        $qtys   = $_POST['item_qty']   ?? [];
        $prices = $_POST['item_price'] ?? [];
        $discs  = $_POST['item_disc']  ?? [];

        $subtotal = 0;
        $itemRows = [];
        foreach ($descs as $i => $desc) {
            if (trim($desc) === '') continue;
            $qty   = max(0, (float)($qtys[$i] ?? 1));
            $price = max(0, (float)($prices[$i] ?? 0));
            $disc  = min(100, max(0, (float)($discs[$i] ?? 0)));
            $line  = $qty * $price * (1 - $disc / 100);
            $subtotal += $line;
            $itemRows[] = [sanitize($desc), $qty, $price, $disc, round($line, 2), $i];
        }
        $taxAmt = round($subtotal * $taxRate / 100, 2);
        $total  = max(0, $subtotal + $taxAmt - $discount);

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_quotes SET contact_id=?,deal_id=?,title=?,subtotal=?,tax_rate=?,tax_amount=?,discount=?,total=?,status=?,valid_until=?,notes=?,terms=? WHERE id=? AND org_id=?")
                ->execute([$contactId,$dealId,$title,round($subtotal,2),$taxRate,$taxAmt,round($discount,2),round($total,2),$status,$validUntil?:null,$notes,$terms,$id,$orgId]);
            $pdo->prepare("DELETE FROM crm_quote_items WHERE quote_id=?")->execute([$id]);
        } else {
            $qNo = 'QUO-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid($orgId,true)),0,5));
            $pdo->prepare("INSERT INTO crm_quotes (org_id,quote_number,contact_id,deal_id,title,subtotal,tax_rate,tax_amount,discount,total,status,valid_until,notes,terms,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$qNo,$contactId,$dealId,$title,round($subtotal,2),$taxRate,$taxAmt,round($discount,2),round($total,2),$status,$validUntil?:null,$notes,$terms,$user['id']??0]);
            $id = (int)$pdo->lastInsertId();
        }

        $ins = $pdo->prepare("INSERT INTO crm_quote_items (quote_id,description,qty,unit_price,discount,total,sort_order) VALUES (?,?,?,?,?,?,?)");
        foreach ($itemRows as $r) {
            $ins->execute([$id, $r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
        }
        setFlash('success', 'Quote saved.');
        logActivity($id > 0 ? 'update' : 'create', 'crm', "Quote: $title");
        redirect('quotes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_quotes WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Quote deleted.');
        redirect('quotes.php');
    }

    if ($action === 'status') {
        $id  = (int)($_POST['id'] ?? 0);
        $st  = in_array($_POST['new_status'] ?? '', ['draft','sent','accepted','rejected','expired']) ? $_POST['new_status'] : 'draft';
        $pdo->prepare("UPDATE crm_quotes SET status=? WHERE id=? AND org_id=?")->execute([$st, $id, $orgId]);
        setFlash('success', 'Quote status updated.');
        redirect('quotes.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$where   = 'q.org_id=?';
$params  = [$orgId];
if ($fStatus) { $where .= ' AND q.status=?'; $params[] = $fStatus; }

$quotes = [];
try {
    $stmt = $pdo->prepare("
        SELECT q.*, CONCAT(c.first_name,' ',c.last_name) AS contact_name, d.title AS deal_title
        FROM crm_quotes q
        LEFT JOIN crm_contacts c ON q.contact_id = c.id
        LEFT JOIN crm_deals d    ON q.deal_id    = d.id
        WHERE $where ORDER BY q.created_at DESC
    ");
    $stmt->execute($params);
    $quotes = $stmt->fetchAll();
} catch (Exception $e) {}

$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT id,first_name,last_name,company FROM crm_contacts WHERE org_id=? AND status='active' ORDER BY first_name");
    $stmt->execute([$orgId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

$deals = [];
try {
    $stmt = $pdo->prepare("SELECT id,title FROM crm_deals WHERE org_id=? AND status='open' ORDER BY title");
    $stmt->execute([$orgId]);
    $deals = $stmt->fetchAll();
} catch (Exception $e) {}

$products = [];
try {
    $stmt = $pdo->prepare("SELECT id,name,unit_price,unit,tax_rate FROM crm_products WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $products = $stmt->fetchAll();
} catch (Exception $e) {}

// View single quote with items
$viewQ     = null;
$viewItems = [];
if (isset($_GET['view'])) {
    try {
        $stmt = $pdo->prepare("SELECT q.*,CONCAT(c.first_name,' ',c.last_name) AS contact_name,d.title AS deal_title FROM crm_quotes q LEFT JOIN crm_contacts c ON q.contact_id=c.id LEFT JOIN crm_deals d ON q.deal_id=d.id WHERE q.id=? AND q.org_id=?");
        $stmt->execute([(int)$_GET['view'], $orgId]);
        $viewQ = $stmt->fetch();
        if ($viewQ) {
            $stmt2 = $pdo->prepare("SELECT * FROM crm_quote_items WHERE quote_id=? ORDER BY sort_order");
            $stmt2->execute([$viewQ['id']]);
            $viewItems = $stmt2->fetchAll();
        }
    } catch (Exception $e) {}
}

// Edit items
$editItems = [];
if (isset($_GET['edit'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_quote_items WHERE quote_id=? ORDER BY sort_order");
        $stmt->execute([(int)$_GET['edit'], $orgId]);
        $editItems = $stmt->fetchAll();
    } catch (Exception $e) {}
}

$total   = countRows('crm_quotes', 'org_id=?', [$orgId]);
$draft   = countRows('crm_quotes', 'org_id=? AND status=?', [$orgId, 'draft']);
$sent    = countRows('crm_quotes', 'org_id=? AND status=?', [$orgId, 'sent']);
$accepted= countRows('crm_quotes', 'org_id=? AND status=?', [$orgId, 'accepted']);

$statusColors = ['draft'=>'secondary','sent'=>'info','accepted'=>'success','rejected'=>'danger','expired'=>'warning'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Quotes &amp; Proposals</h4>
    <p class="text-muted mb-0">Create, send and track client proposals</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#qModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Quote
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-file-invoice"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Quotes</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-paper-plane"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $sent ?></div><div class="stat-label">Sent</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $accepted ?></div><div class="stat-label">Accepted</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-edit"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $draft ?></div><div class="stat-label">Drafts</div></div></div>
  </div>
</div>

<!-- Quote Preview -->
<?php if ($viewQ): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2"></i><?= e($viewQ['quote_number']) ?> — <?= e($viewQ['title']) ?></h6>
    <div class="d-flex gap-2">
      <form method="POST" class="d-inline">
        <?= csrfField() ?><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?= $viewQ['id'] ?>">
        <select name="new_status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
          <?php foreach (['draft','sent','accepted','rejected','expired'] as $st): ?>
          <option value="<?= $st ?>" <?= $viewQ['status']===$st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <a href="quotes.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
    </div>
  </div>
  <div class="card-body">
    <div class="row mb-3">
      <div class="col-md-6">
        <p class="mb-1 small text-muted">Contact: <strong><?= e($viewQ['contact_name'] ?? '—') ?></strong></p>
        <p class="mb-1 small text-muted">Deal: <strong><?= e($viewQ['deal_title'] ?? '—') ?></strong></p>
        <p class="mb-0 small text-muted">Valid Until: <strong><?= $viewQ['valid_until'] ? formatDate($viewQ['valid_until']) : '—' ?></strong></p>
      </div>
      <div class="col-md-6 text-md-end">
        <span class="badge bg-<?= $statusColors[$viewQ['status']] ?? 'secondary' ?> fs-6"><?= ucfirst($viewQ['status']) ?></span>
      </div>
    </div>
    <div class="table-responsive mb-3">
      <table class="table table-sm table-bordered mb-0">
        <thead class="table-light"><tr><th>#</th><th>Description</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Disc %</th><th class="text-end">Total</th></tr></thead>
        <tbody>
        <?php foreach ($viewItems as $i => $item): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td><?= e($item['description']) ?></td>
          <td class="text-end"><?= number_format((float)$item['qty'], 2) ?></td>
          <td class="text-end"><?= formatCurrency((float)$item['unit_price']) ?></td>
          <td class="text-end"><?= number_format((float)$item['discount'], 1) ?>%</td>
          <td class="text-end fw-semibold"><?= formatCurrency((float)$item['total']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr><td colspan="5" class="text-end fw-semibold">Subtotal</td><td class="text-end fw-semibold"><?= formatCurrency((float)$viewQ['subtotal']) ?></td></tr>
          <tr><td colspan="5" class="text-end text-muted">Tax (<?= number_format((float)$viewQ['tax_rate'], 1) ?>%)</td><td class="text-end"><?= formatCurrency((float)$viewQ['tax_amount']) ?></td></tr>
          <?php if ((float)$viewQ['discount'] > 0): ?>
          <tr><td colspan="5" class="text-end text-danger">Discount</td><td class="text-end text-danger">− <?= formatCurrency((float)$viewQ['discount']) ?></td></tr>
          <?php endif; ?>
          <tr class="fw-bold"><td colspan="5" class="text-end">TOTAL</td><td class="text-end fs-5 text-success"><?= formatCurrency((float)$viewQ['total']) ?></td></tr>
        </tfoot>
      </table>
    </div>
    <?php if ($viewQ['notes'] || $viewQ['terms']): ?>
    <div class="row">
      <?php if ($viewQ['notes']): ?><div class="col-md-6"><p class="small"><strong>Notes:</strong><br><?= nl2br(e($viewQ['notes'])) ?></p></div><?php endif; ?>
      <?php if ($viewQ['terms']): ?><div class="col-md-6"><p class="small"><strong>Terms &amp; Conditions:</strong><br><?= nl2br(e($viewQ['terms'])) ?></p></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (['draft','sent','accepted','rejected','expired'] as $st): ?>
        <option value="<?= $st ?>" <?= $fStatus===$st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="quotes.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
  </form>
</div></div>

<!-- Quotes table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>All Quotes</h6>
    <span class="badge bg-secondary"><?= count($quotes) ?> quotes</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Quote #</th><th>Title</th><th>Contact</th><th>Total</th><th>Status</th><th>Valid Until</th><th>Created</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($quotes)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-file-invoice fa-2x mb-2 d-block"></i>No quotes yet.</td></tr>
        <?php else: foreach ($quotes as $q): ?>
          <tr>
            <td class="fw-semibold"><?= e($q['quote_number']) ?></td>
            <td><?= e($q['title']) ?></td>
            <td><?= e($q['contact_name'] ?? '—') ?></td>
            <td class="fw-semibold text-success"><?= formatCurrency((float)$q['total']) ?></td>
            <td><span class="badge bg-<?= $statusColors[$q['status']] ?? 'secondary' ?> <?= in_array($q['status'],['expired']) ? 'text-dark' : '' ?>"><?= ucfirst($q['status']) ?></span></td>
            <td><?= $q['valid_until'] ? formatDate($q['valid_until']) : '—' ?></td>
            <td><?= formatDate($q['created_at']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $q['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delQuote(<?= $q['id'] ?>,'<?= e($q['quote_number']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Quote Modal -->
<div class="modal fade" id="qModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST" id="quoteForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="qId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="qModalTitle"><i class="fas fa-file-invoice me-2"></i>New Quote</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <!-- Header -->
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Quote Title <span class="text-danger">*</span></label>
              <input type="text" name="title" id="qTitle" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Contact</label>
              <select name="contact_id" id="qContact" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($contacts as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['first_name'].' '.$c['last_name'].($c['company'] ? ' ('.$c['company'].')' : '')) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Deal</label>
              <select name="deal_id" id="qDeal" class="form-select">
                <option value="">— None —</option>
                <?php foreach ($deals as $d): ?>
                <option value="<?= $d['id'] ?>"><?= e($d['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="qStatus" class="form-select">
                <?php foreach (['draft','sent','accepted','rejected','expired'] as $st): ?>
                <option value="<?= $st ?>"><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Valid Until</label>
              <input type="date" name="valid_until" id="qValidUntil" class="form-control">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Tax Rate (%)</label>
              <input type="number" name="tax_rate" id="qTaxRate" class="form-control" step="0.1" min="0" max="100" value="16" onchange="recalc()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Header Discount (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="discount" id="qDiscount" class="form-control" step="0.01" min="0" value="0" onchange="recalc()">
            </div>
          </div>

          <!-- Line Items -->
          <h6 class="fw-semibold mb-2"><i class="fas fa-list me-1" style="color:<?= $moduleColor ?>"></i>Line Items</h6>
          <div class="mb-2">
            <label class="form-label small text-muted">Quick-add from product catalog:</label>
            <select id="productPicker" class="form-select form-select-sm" onchange="addProduct(this)" style="max-width:320px">
              <option value="">— Pick a product —</option>
              <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>" data-name="<?= e($p['name']) ?>" data-price="<?= $p['unit_price'] ?>"><?= e($p['name']) ?> — <?= formatCurrency((float)$p['unit_price']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-bordered" id="itemsTable">
              <thead class="table-light">
                <tr><th style="min-width:250px">Description</th><th style="width:90px">Qty</th><th style="width:130px">Unit Price</th><th style="width:90px">Disc %</th><th style="width:120px">Total</th><th style="width:40px"></th></tr>
              </thead>
              <tbody id="itemsBody"></tbody>
              <tfoot>
                <tr><td colspan="6"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="addRow()"><i class="fas fa-plus me-1"></i>Add Row</button></td></tr>
                <tr class="table-light"><td colspan="4" class="text-end fw-semibold">Subtotal</td><td id="subtotalCell" class="fw-semibold">0.00</td><td></td></tr>
                <tr><td colspan="4" class="text-end text-muted">Tax (<span id="taxRateLabel">16</span>%)</td><td id="taxCell">0.00</td><td></td></tr>
                <tr><td colspan="4" class="text-end text-danger">Discount</td><td id="discCell" class="text-danger">0.00</td><td></td></tr>
                <tr class="fw-bold table-success"><td colspan="4" class="text-end">TOTAL</td><td id="totalCell" class="fs-6">0.00</td><td></td></tr>
              </tfoot>
            </table>
          </div>

          <!-- Notes & Terms -->
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Notes</label>
              <textarea name="notes" id="qNotes" class="form-control" rows="3" placeholder="Internal or client-facing notes…"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Terms &amp; Conditions</label>
              <textarea name="terms" id="qTerms" class="form-control" rows="3" placeholder="Payment terms, delivery conditions…"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Quote</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delQForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delQId"></form>

<?php
$currency = CURRENCY_SYMBOL;
$productsJson = json_encode($products);
$extraJs = <<<JS
<script>
const CURRENCY = '{$currency}';
const products = {$productsJson};

let rowIdx = 0;

function fmtCur(n) {
  return CURRENCY + parseFloat(n).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
}

function addRow(desc='', qty=1, price=0, disc=0) {
  const idx = rowIdx++;
  const row = document.createElement('tr');
  row.id = 'row_' + idx;
  row.innerHTML = \`
    <td><input type="text" name="item_desc[]" class="form-control form-control-sm" value="\${desc}" required></td>
    <td><input type="number" name="item_qty[]" class="form-control form-control-sm text-end" value="\${qty}" step="0.01" min="0" onchange="calcRow(this)"></td>
    <td><input type="number" name="item_price[]" class="form-control form-control-sm text-end" value="\${price}" step="0.01" min="0" onchange="calcRow(this)"></td>
    <td><input type="number" name="item_disc[]" class="form-control form-control-sm text-end" value="\${disc}" step="0.1" min="0" max="100" onchange="calcRow(this)"></td>
    <td class="row-total text-end fw-semibold align-middle">\${fmtCur(qty * price * (1 - disc/100))}</td>
    <td class="text-center align-middle"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
  \`;
  document.getElementById('itemsBody').appendChild(row);
  recalc();
}

function calcRow(el) {
  const tr = el.closest('tr');
  const qty   = parseFloat(tr.querySelector('[name="item_qty[]"]').value) || 0;
  const price = parseFloat(tr.querySelector('[name="item_price[]"]').value) || 0;
  const disc  = parseFloat(tr.querySelector('[name="item_disc[]"]').value) || 0;
  tr.querySelector('.row-total').textContent = fmtCur(qty * price * (1 - disc/100));
  recalc();
}

function removeRow(btn) {
  btn.closest('tr').remove();
  recalc();
}

function recalc() {
  let sub = 0;
  document.querySelectorAll('#itemsBody tr').forEach(tr => {
    const qty   = parseFloat(tr.querySelector('[name="item_qty[]"]')?.value)   || 0;
    const price = parseFloat(tr.querySelector('[name="item_price[]"]')?.value) || 0;
    const disc  = parseFloat(tr.querySelector('[name="item_disc[]"]')?.value)  || 0;
    sub += qty * price * (1 - disc/100);
  });
  const taxRate = parseFloat(document.getElementById('qTaxRate')?.value) || 0;
  const discAmt = parseFloat(document.getElementById('qDiscount')?.value) || 0;
  const tax   = sub * taxRate / 100;
  const total = Math.max(0, sub + tax - discAmt);
  document.getElementById('subtotalCell').textContent = fmtCur(sub);
  document.getElementById('taxCell').textContent      = fmtCur(tax);
  document.getElementById('discCell').textContent     = fmtCur(discAmt);
  document.getElementById('totalCell').textContent    = fmtCur(total);
  document.getElementById('taxRateLabel').textContent = taxRate;
}

function addProduct(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt.value) return;
  addRow(opt.dataset.name, 1, parseFloat(opt.dataset.price), 0);
  sel.value = '';
}

function openAdd() {
  document.getElementById('qModalTitle').innerHTML = '<i class="fas fa-file-invoice me-2"></i>New Quote';
  ['qId','qTitle','qNotes','qTerms','qValidUntil'].forEach(i => document.getElementById(i).value = i==='qId' ? '0' : '');
  document.getElementById('qContact').value  = '';
  document.getElementById('qDeal').value     = '';
  document.getElementById('qStatus').value   = 'draft';
  document.getElementById('qTaxRate').value  = 16;
  document.getElementById('qDiscount').value = 0;
  document.getElementById('itemsBody').innerHTML = '';
  rowIdx = 0;
  addRow();
  recalc();
}

function openEdit(q) {
  document.getElementById('qModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Quote';
  document.getElementById('qId').value          = q.id;
  document.getElementById('qTitle').value        = q.title || '';
  document.getElementById('qContact').value      = q.contact_id || '';
  document.getElementById('qDeal').value         = q.deal_id || '';
  document.getElementById('qStatus').value       = q.status || 'draft';
  document.getElementById('qTaxRate').value      = q.tax_rate || 16;
  document.getElementById('qDiscount').value     = q.discount || 0;
  document.getElementById('qValidUntil').value   = q.valid_until ? q.valid_until.substring(0,10) : '';
  document.getElementById('qNotes').value        = q.notes || '';
  document.getElementById('qTerms').value        = q.terms || '';
  document.getElementById('itemsBody').innerHTML = '';
  rowIdx = 0;
  // Load items via fetch
  fetch('?get_items=' + q.id)
    .then(r => r.json())
    .then(items => {
      items.forEach(it => addRow(it.description, it.qty, it.unit_price, it.discount));
      if (!items.length) addRow();
      recalc();
    })
    .catch(() => { addRow(); recalc(); });
  new bootstrap.Modal(document.getElementById('qModal')).show();
}

function delQuote(id, num) {
  Swal.fire({title:'Delete Quote?',text:num+' will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r => { if (r.isConfirmed) { document.getElementById('delQId').value = id; document.getElementById('delQForm').submit(); } });
}
</script>
JS;

// JSON endpoint for edit items
if (isset($_GET['get_items'])) {
    header('Content-Type: application/json');
    $items = [];
    try {
        $s = $pdo->prepare("SELECT qi.* FROM crm_quote_items qi JOIN crm_quotes q ON qi.quote_id=q.id WHERE qi.quote_id=? AND q.org_id=? ORDER BY sort_order");
        $s->execute([(int)$_GET['get_items'], $orgId]);
        $items = $s->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    echo json_encode($items);
    exit;
}

require_once __DIR__ . '/../../includes/footer.php';
?>
