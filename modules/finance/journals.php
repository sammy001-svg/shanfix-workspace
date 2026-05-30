<?php
// ── Finance: Journal Entries ────────────────────────────────────
$moduleSlug  = 'finance';
$moduleName  = 'Finance & Budgeting';
$moduleIcon  = 'fas fa-wallet';
$moduleColor = '#16a085';
$moduleNav   = [
    ['url' => 'index.php',          'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'accounts.php',       'icon' => 'fas fa-university',     'label' => 'Accounts'],
    ['url' => 'transactions.php',   'icon' => 'fas fa-exchange-alt',   'label' => 'Transactions'],
    ['url' => 'categories.php',     'icon' => 'fas fa-tags',           'label' => 'Categories'],
    ['url' => 'budgets.php',        'icon' => 'fas fa-bullseye',       'label' => 'Budgets'],
    ['url' => 'journals.php',       'icon' => 'fas fa-book',           'label' => 'Journals'],
    ['url' => 'reconciliation.php', 'icon' => 'fas fa-check-double',   'label' => 'Reconciliation'],
    ['url' => 'statements.php',     'icon' => 'fas fa-file-alt',       'label' => 'Statements'],
    ['url' => 'reports.php',        'icon' => 'fas fa-chart-pie',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_journal') {
        $entryDate   = $_POST['entry_date']   ?? date('Y-m-d');
        $description = sanitize($_POST['description'] ?? '');
        $entryType   = sanitize($_POST['entry_type']  ?? 'general');
        $lines       = $_POST['lines'] ?? [];

        if (empty($description) || count($lines) < 2) {
            setFlash('danger', 'A journal entry requires a description and at least 2 lines.');
            redirect('journals.php');
        }

        $totalDebit = 0; $totalCredit = 0;
        foreach ($lines as $line) {
            $totalDebit  += (float)($line['debit']  ?? 0);
            $totalCredit += (float)($line['credit'] ?? 0);
        }

        if (abs($totalDebit - $totalCredit) > 0.01) {
            setFlash('danger', 'Journal entry is not balanced. Debits (' . formatCurrency($totalDebit) . ') must equal Credits (' . formatCurrency($totalCredit) . ').');
            redirect('journals.php');
        }

        try {
            $pdo->beginTransaction();

            // Generate journal number
            $yr   = date('Y');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM fin_journal_entries WHERE org_id=? AND YEAR(entry_date)=?");
            $stmt->execute([$orgId, $yr]);
            $seq = (int)$stmt->fetchColumn() + 1;
            $journalNo = 'JNL-' . $yr . '-' . str_pad($seq, 4, '0', STR_PAD_LEFT);

            $stmt = $pdo->prepare("
                INSERT INTO fin_journal_entries (org_id, journal_no, entry_date, description, entry_type, status, total_debit, total_credit)
                VALUES (?,?,?,?,?,'posted',?,?)
            ");
            $stmt->execute([$orgId, $journalNo, $entryDate, $description, $entryType, $totalDebit, $totalCredit]);
            $journalId = $pdo->lastInsertId();

            $stmtLine = $pdo->prepare("
                INSERT INTO fin_journal_lines (journal_id, account_id, description, debit, credit)
                VALUES (?,?,?,?,?)
            ");
            foreach ($lines as $line) {
                $accId   = (int)($line['account_id'] ?? 0);
                $lineDesc = sanitize($line['description'] ?? '');
                $debit   = (float)($line['debit']  ?? 0);
                $credit  = (float)($line['credit'] ?? 0);
                if ($accId <= 0) continue;
                $stmtLine->execute([$journalId, $accId, $lineDesc, $debit, $credit]);
            }

            $pdo->commit();
            setFlash('success', "Journal entry {$journalNo} posted successfully.");
            logActivity('create', 'finance', "Posted journal entry {$journalNo}: {$description}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error posting journal: ' . $e->getMessage());
        }
        redirect('journals.php');
    }

    if ($action === 'void_journal') {
        $jid = (int)($_POST['journal_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("UPDATE fin_journal_entries SET status='voided' WHERE id=? AND org_id=? AND status='posted'");
            $stmt->execute([$jid, $orgId]);
            setFlash('success', 'Journal entry voided.');
            logActivity('update', 'finance', "Voided journal entry #{$jid}");
        } catch (Exception $e) {
            setFlash('danger', 'Error voiding entry: ' . $e->getMessage());
        }
        redirect('journals.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Load accounts for line entry selector
$accounts = [];
try {
    $stmt = $pdo->prepare("SELECT id, account_name, account_code, account_type FROM fin_accounts WHERE org_id=? ORDER BY account_code");
    $stmt->execute([$orgId]);
    $accounts = $stmt->fetchAll();
} catch (Exception $e) {}

// Load journals
$journals = [];
try {
    $stmt = $pdo->prepare("
        SELECT j.*, COUNT(l.id) as line_count
        FROM fin_journal_entries j
        LEFT JOIN fin_journal_lines l ON l.journal_id = j.id
        WHERE j.org_id = ?
        GROUP BY j.id
        ORDER BY j.entry_date DESC, j.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $journals = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats
$totalPosted = array_sum(array_column(array_filter($journals, fn($j) => $j['status'] === 'posted'), 'total_debit'));
$countPosted = count(array_filter($journals, fn($j) => $j['status'] === 'posted'));
$countVoided = count(array_filter($journals, fn($j) => $j['status'] === 'voided'));
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-book me-2" style="color:<?= $moduleColor ?>"></i>Journal Entries</h4>
    <p class="text-muted mb-0">Double-entry bookkeeping — record balanced debit/credit journal entries</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#journalModal">
    <i class="fas fa-plus-circle me-1"></i>New Journal Entry
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(22,160,133,0.12);color:#16a085"><i class="fas fa-book"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $countPosted ?></div><div class="stat-label">Posted Entries</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-balance-scale"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalPosted) ?></div><div class="stat-label">Total Debits Recorded</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-ban"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $countVoided ?></div><div class="stat-label">Voided Entries</div></div>
    </div>
  </div>
</div>

<!-- Journal Table -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white border-bottom py-3">
    <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>All Journal Entries</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="journalsTable">
        <thead class="table-light">
          <tr>
            <th>Date</th>
            <th>Journal No.</th>
            <th>Description</th>
            <th>Type</th>
            <th class="text-center">Lines</th>
            <th class="text-end">Total Debit</th>
            <th class="text-center">Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($journals)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-book-open fa-3x mb-3 d-block"></i>No journal entries recorded yet.
            </td>
          </tr>
          <?php else: foreach ($journals as $j): ?>
          <tr>
            <td><?= formatDate($j['entry_date']) ?></td>
            <td><span class="badge bg-secondary"><?= e($j['journal_no']) ?></span></td>
            <td class="fw-semibold"><?= e($j['description']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst(str_replace('_', ' ', e($j['entry_type']))) ?></span></td>
            <td class="text-center"><?= (int)$j['line_count'] ?></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$j['total_debit']) ?></td>
            <td class="text-center">
              <?php if ($j['status'] === 'posted'): ?>
                <span class="badge bg-success">Posted</span>
              <?php elseif ($j['status'] === 'voided'): ?>
                <span class="badge bg-danger">Voided</span>
              <?php else: ?>
                <span class="badge bg-warning text-dark">Draft</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" onclick="viewLines(<?= $j['id'] ?>)" title="View Lines">
                <i class="fas fa-eye"></i>
              </button>
              <?php if ($j['status'] === 'posted'): ?>
              <form method="POST" class="d-inline" onsubmit="return confirm('Void this journal entry? This cannot be undone.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="void_journal">
                <input type="hidden" name="journal_id" value="<?= $j['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" title="Void Entry"><i class="fas fa-ban"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Lines view modal -->
<div class="modal fade" id="linesModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-list me-2"></i>Journal Lines</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div id="linesContent" class="p-3 text-center text-muted"><i class="fas fa-spinner fa-spin"></i> Loading…</div>
      </div>
    </div>
  </div>
</div>

<!-- New Journal Modal -->
<div class="modal fade" id="journalModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <form method="POST" id="journalForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_journal">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-book me-2"></i>New Journal Entry</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3 mb-4">
            <div class="col-md-4">
              <label class="form-label fw-semibold">Entry Date <span class="text-danger">*</span></label>
              <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Entry Type</label>
              <select name="entry_type" class="form-select">
                <option value="general">General</option>
                <option value="adjusting">Adjusting</option>
                <option value="closing">Closing</option>
                <option value="reversing">Reversing</option>
                <option value="opening">Opening Balance</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" class="form-control" required placeholder="Brief description of this entry">
            </div>
          </div>

          <!-- Lines -->
          <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="mb-0 fw-semibold">Journal Lines (min. 2)</h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addLine()"><i class="fas fa-plus me-1"></i>Add Line</button>
          </div>

          <!-- Balance indicator -->
          <div class="alert alert-info py-2 mb-3" id="balanceAlert">
            <div class="d-flex justify-content-between">
              <span>Total Debits: <strong id="sumDebits">0.00</strong></span>
              <span>Total Credits: <strong id="sumCredits">0.00</strong></span>
              <span id="balanceStatus"><i class="fas fa-circle text-warning"></i> Not balanced</span>
            </div>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle border" id="linesTable">
              <thead class="table-light">
                <tr>
                  <th style="width:35%">Account</th>
                  <th>Line Description</th>
                  <th class="text-end" style="width:15%">Debit</th>
                  <th class="text-end" style="width:15%">Credit</th>
                  <th style="width:40px"></th>
                </tr>
              </thead>
              <tbody id="linesBody">
                <!-- lines injected by JS -->
              </tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Post Journal Entry</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$accountsJson = json_encode(array_map(fn($a) => [
    'id'   => $a['id'],
    'name' => $a['account_code'] . ' — ' . $a['account_name'],
    'type' => $a['account_type'],
], $accounts));
?>
<script>
const finAccounts = <?= $accountsJson ?>;
</script>
<?php $extraJs = <<<'JS'
<script>
$(document).ready(function() {
    $("#journalsTable").DataTable({pageLength:15, order:[[0,"desc"]]});
    // Add initial 2 blank lines
    addLine(); addLine();
});

function buildAccountOptions(selected) {
    let opts = '<option value="">-- Select Account --</option>';
    finAccounts.forEach(a => {
        opts += `<option value="${a.id}" ${selected == a.id ? 'selected' : ''}>${a.name}</option>`;
    });
    return opts;
}

let lineIdx = 0;
function addLine() {
    const idx = lineIdx++;
    const row = `<tr id="line_${idx}">
        <td>
            <select name="lines[${idx}][account_id]" class="form-select form-select-sm">
                ${buildAccountOptions(0)}
            </select>
        </td>
        <td><input type="text" name="lines[${idx}][description]" class="form-control form-control-sm" placeholder="Optional"></td>
        <td><input type="number" step="0.01" name="lines[${idx}][debit]" class="form-control form-control-sm text-end line-amount" min="0" value="0" onchange="recalc()"></td>
        <td><input type="number" step="0.01" name="lines[${idx}][credit]" class="form-control form-control-sm text-end line-amount" min="0" value="0" onchange="recalc()"></td>
        <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(${idx})"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $("#linesBody").append(row);
}

function removeLine(idx) {
    if ($("#linesBody tr").length <= 2) { alert("Minimum 2 lines required."); return; }
    $("#line_" + idx).remove();
    recalc();
}

function recalc() {
    let sumD = 0, sumC = 0;
    $("input[name*='[debit]']").each(function() { sumD += parseFloat($(this).val()) || 0; });
    $("input[name*='[credit]']").each(function() { sumC += parseFloat($(this).val()) || 0; });
    $("#sumDebits").text(sumD.toFixed(2));
    $("#sumCredits").text(sumC.toFixed(2));
    const balanced = Math.abs(sumD - sumC) < 0.01;
    $("#balanceStatus").html(balanced
        ? '<i class="fas fa-check-circle text-success"></i> <span class="text-success">Balanced</span>'
        : '<i class="fas fa-exclamation-circle text-danger"></i> <span class="text-danger">Not balanced</span>');
    $("#balanceAlert").removeClass("alert-info alert-success alert-danger")
        .addClass(balanced ? "alert-success" : "alert-danger");
}

function viewLines(id) {
    $("#linesContent").html('<p class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin"></i> Loading…</p>');
    $("#linesModal").modal("show");
    $.get("journals.php?fetch_lines=" + id, function(data) {
        $("#linesContent").html(data);
    }).fail(function() {
        $("#linesContent").html('<p class="text-danger text-center py-3">Failed to load lines.</p>');
    });
}
</script>
JS;

// AJAX: fetch journal lines
if (isset($_GET['fetch_lines'])) {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $jid   = (int)$_GET['fetch_lines'];
    header('Content-Type: text/html; charset=utf-8');

    try {
        $stmt = $pdo->prepare("
            SELECT l.*, a.account_name, a.account_code
            FROM fin_journal_lines l
            LEFT JOIN fin_accounts a ON a.id = l.account_id
            WHERE l.journal_id = ?
        ");
        $stmt->execute([$jid]);
        $lines = $stmt->fetchAll();

        // Verify journal belongs to org
        $chk = $pdo->prepare("SELECT journal_no, description FROM fin_journal_entries WHERE id=? AND org_id=?");
        $chk->execute([$jid, $orgId]);
        $jrnl = $chk->fetch();
        if (!$jrnl) { echo '<p class="text-danger p-3">Not found.</p>'; exit; }

        echo '<div class="px-3 pt-2 pb-1"><p class="mb-1 fw-semibold">' . e($jrnl['journal_no']) . ': ' . e($jrnl['description']) . '</p></div>';
        echo '<table class="table table-sm table-hover align-middle mb-0">';
        echo '<thead class="table-light"><tr><th>Account</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>';
        $totD = 0; $totC = 0;
        foreach ($lines as $l) {
            $totD += (float)$l['debit']; $totC += (float)$l['credit'];
            echo '<tr><td>' . e($l['account_code'] . ' — ' . $l['account_name']) . '</td>'
               . '<td class="text-muted small">' . e($l['description'] ?: '—') . '</td>'
               . '<td class="text-end">' . ((float)$l['debit'] > 0 ? formatCurrency((float)$l['debit']) : '—') . '</td>'
               . '<td class="text-end">' . ((float)$l['credit'] > 0 ? formatCurrency((float)$l['credit']) : '—') . '</td></tr>';
        }
        echo '<tr class="table-light fw-bold"><td colspan="2">Totals</td><td class="text-end">' . formatCurrency($totD) . '</td><td class="text-end">' . formatCurrency($totC) . '</td></tr>';
        echo '</tbody></table>';
    } catch (Exception $e) {
        echo '<p class="text-danger p-3">' . e($e->getMessage()) . '</p>';
    }
    exit;
}

require_once __DIR__ . '/../../includes/footer.php';
?>
