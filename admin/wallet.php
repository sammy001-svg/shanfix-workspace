<?php
$pageTitle = 'Wallet Management';
require_once __DIR__ . '/../includes/header-admin.php';

// ── POST: Credit / Debit wallet ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action  = $_POST['action'] ?? '';
    $orgId   = (int)($_POST['org_id']     ?? 0);
    $amount  = (float)($_POST['amount']   ?? 0);
    $type    = in_array($_POST['type'] ?? '', ['topup','deduction','refund']) ? $_POST['type'] : 'topup';
    $note    = sanitize($_POST['note']    ?? '');

    if ($action === 'wallet_adjust' && $orgId && $amount > 0) {
        $pdo->beginTransaction();
        try {
            $wb = $pdo->prepare("SELECT wallet_balance FROM organizations WHERE id=? FOR UPDATE");
            $wb->execute([$orgId]);
            $currentBal = (float)($wb->fetchColumn() ?: 0);

            $newBal = ($type === 'deduction') ? ($currentBal - $amount) : ($currentBal + $amount);
            if ($newBal < 0) {
                $pdo->rollBack();
                setFlash('danger', 'Deduction would bring balance below zero. Current balance: ' . formatCurrency($currentBal));
            } else {
                $pdo->prepare("UPDATE organizations SET wallet_balance=? WHERE id=?")->execute([$newBal, $orgId]);
                $pdo->prepare("INSERT INTO wallet_transactions (org_id,type,amount,balance_after,description,status) VALUES (?,?,?,?,?,'completed')")
                    ->execute([$orgId, $type, $amount, $newBal, $note ?: ucfirst($type) . ' by admin']);
                $pdo->commit();
                logActivity('wallet_' . $type, 'admin', "Org #{$orgId}: {$type} KES {$amount}. New balance: {$newBal}");
                setFlash('success', "Wallet updated. New balance: <strong>" . formatCurrency($newBal) . "</strong>");
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Transaction failed: ' . $e->getMessage());
        }
        redirect(APP_URL . '/admin/wallet.php' . ($orgId ? '?org=' . $orgId : ''));
    }
}

// ── Data ──────────────────────────────────────────────────────────
$orgFilter = (int)($_GET['org'] ?? 0);
$search    = sanitize($_GET['q'] ?? '');

// Org list with wallet balances
$orgsQuery = "
    SELECT o.id, o.name, o.email, o.status,
           COALESCE(o.wallet_balance, 0.00) AS wallet_balance,
           (SELECT COUNT(*) FROM wallet_transactions wt WHERE wt.org_id = o.id) AS txn_count
    FROM organizations o
    WHERE 1=1
";
$params = [];
if ($search) {
    $orgsQuery .= " AND (o.name LIKE ? OR o.email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$orgsQuery .= " ORDER BY o.wallet_balance DESC, o.name ASC LIMIT 100";
$stmt = $pdo->prepare($orgsQuery);
$stmt->execute($params);
$orgs = $stmt->fetchAll();

// Totals
$totalWallet = $pdo->query("SELECT COALESCE(SUM(wallet_balance),0) FROM organizations")->fetchColumn();
$totalTopups = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='topup' AND status='completed'")->fetchColumn();
$totalDeductions = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM wallet_transactions WHERE type='deduction' AND status='completed'")->fetchColumn();

// Recent transactions
$recentTxns = $pdo->query("
    SELECT wt.*, o.name AS org_name
    FROM wallet_transactions wt
    JOIN organizations o ON wt.org_id = o.id
    ORDER BY wt.created_at DESC LIMIT 50
")->fetchAll();

// Single org view
$focusOrg = null;
$focusTxns = [];
if ($orgFilter) {
    $s = $pdo->prepare("SELECT id, name, email, COALESCE(wallet_balance,0) AS wallet_balance FROM organizations WHERE id=?");
    $s->execute([$orgFilter]);
    $focusOrg = $s->fetch();
    if ($focusOrg) {
        $s2 = $pdo->prepare("SELECT * FROM wallet_transactions WHERE org_id=? ORDER BY created_at DESC LIMIT 100");
        $s2->execute([$orgFilter]);
        $focusTxns = $s2->fetchAll();
    }
}

$allOrgsForSelect = $pdo->query("SELECT id, name FROM organizations WHERE status='active' ORDER BY name")->fetchAll();
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-wallet me-2 text-green"></i>Wallet Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Wallet</li>
    </ol></nav>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#adjustModal">
    <i class="fas fa-plus-minus me-2"></i>Adjust Wallet
  </button>
</div>

<!-- Summary stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-wallet"></i></div>
      <div><div class="stat-value"><?= formatCurrency((float)$totalWallet) ?></div><div class="stat-label">Total Wallet Balances</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-arrow-down"></i></div>
      <div><div class="stat-value"><?= formatCurrency((float)$totalTopups) ?></div><div class="stat-label">Total Topped Up</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-arrow-up"></i></div>
      <div><div class="stat-value"><?= formatCurrency((float)$totalDeductions) ?></div><div class="stat-label">Total Deducted</div></div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Left: Org wallets table -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span><i class="fas fa-building text-green me-2"></i>Organization Wallets</span>
        <form class="d-flex gap-2" method="GET">
          <input type="text" name="q" class="form-control form-control-sm" placeholder="Search orgs…" value="<?= e($search) ?>">
          <button class="btn btn-sm btn-outline-secondary">Go</button>
        </form>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0 data-table">
            <thead>
              <tr>
                <th>Organization</th>
                <th>Balance</th>
                <th>Transactions</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($orgs as $org): ?>
              <tr class="<?= $orgFilter === (int)$org['id'] ? 'table-success' : '' ?>">
                <td>
                  <div class="fw-600"><?= e($org['name']) ?></div>
                  <div class="text-muted small"><?= e($org['email']) ?></div>
                </td>
                <td>
                  <span class="fw-700 <?= (float)$org['wallet_balance'] > 0 ? 'text-success' : 'text-muted' ?>">
                    <?= formatCurrency((float)$org['wallet_balance']) ?>
                  </span>
                </td>
                <td><span class="badge bg-secondary"><?= $org['txn_count'] ?></span></td>
                <td>
                  <div class="d-flex gap-1">
                    <a href="?org=<?= $org['id'] ?>" class="btn btn-xs btn-outline-primary">
                      <i class="fas fa-eye"></i>
                    </a>
                    <button class="btn btn-xs btn-outline-success"
                            onclick="openAdjust(<?= $org['id'] ?>, '<?= addslashes($org['name']) ?>', <?= $org['wallet_balance'] ?>)">
                      <i class="fas fa-plus-minus"></i>
                    </button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Transactions -->
  <div class="col-lg-5">
    <?php if ($focusOrg): ?>
    <div class="card mb-3" style="background:linear-gradient(135deg,#0B2D4E,#1A8A4E);color:white;border:none">
      <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between">
          <div>
            <div style="font-size:.8rem;opacity:.75"><i class="fas fa-building me-1"></i><?= e($focusOrg['name']) ?></div>
            <div style="font-size:1.8rem;font-weight:800"><?= formatCurrency((float)$focusOrg['wallet_balance']) ?></div>
            <div style="font-size:.75rem;opacity:.7">Current wallet balance</div>
          </div>
          <div class="d-flex flex-column gap-2">
            <button class="btn btn-sm btn-success"
                    onclick="openAdjust(<?= $focusOrg['id'] ?>, '<?= addslashes($focusOrg['name']) ?>', <?= $focusOrg['wallet_balance'] ?>)">
              <i class="fas fa-plus me-1"></i>Adjust
            </button>
            <a href="wallet.php" class="btn btn-sm btn-outline-light">Clear</a>
          </div>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-history text-green me-2"></i>Transaction History (<?= count($focusTxns) ?>)</div>
      <div class="card-body p-0" style="max-height:520px;overflow-y:auto">
        <?php if (empty($focusTxns)): ?>
        <div class="text-center py-4 text-muted small">No transactions yet.</div>
        <?php else: ?>
        <?php foreach ($focusTxns as $tx):
            $tc = match($tx['type']) { 'topup' => 'text-success', 'deduction' => 'text-danger', 'refund' => 'text-info', default => '' };
            $ti = match($tx['type']) { 'topup' => 'fa-arrow-down', 'deduction' => 'fa-arrow-up', 'refund' => 'fa-undo', default => 'fa-circle' };
            $ts = $tx['type'] === 'deduction' ? '-' : '+';
        ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="flex-shrink-0">
            <i class="fas <?= $ti ?> <?= $tc ?>" style="width:14px;text-align:center"></i>
          </div>
          <div class="flex-grow-1">
            <div class="small fw-600"><?= e($tx['description'] ?: ucfirst($tx['type'])) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= formatDate($tx['created_at']) ?> · <?= $tx['status'] === 'completed' ? 'Balance: ' . formatCurrency((float)$tx['balance_after']) : ucfirst($tx['status']) ?></div>
          </div>
          <div class="fw-700 <?= $tc ?>"><?= $ts ?><?= formatCurrency((float)$tx['amount']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header fw-bold"><i class="fas fa-history text-green me-2"></i>Recent Transactions</div>
      <div class="card-body p-0" style="max-height:580px;overflow-y:auto">
        <?php if (empty($recentTxns)): ?>
        <div class="text-center py-5 text-muted small">No wallet transactions yet.</div>
        <?php else: ?>
        <?php foreach ($recentTxns as $tx):
            $tc = match($tx['type']) { 'topup' => 'text-success', 'deduction' => 'text-danger', 'refund' => 'text-info', default => '' };
            $ti = match($tx['type']) { 'topup' => 'fa-arrow-down', 'deduction' => 'fa-arrow-up', 'refund' => 'fa-undo', default => 'fa-circle' };
            $ts = $tx['type'] === 'deduction' ? '-' : '+';
        ?>
        <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom">
          <div class="flex-shrink-0">
            <i class="fas <?= $ti ?> <?= $tc ?>" style="width:14px;text-align:center"></i>
          </div>
          <div class="flex-grow-1">
            <div class="small fw-600"><?= e($tx['org_name']) ?></div>
            <div class="text-muted" style="font-size:.7rem"><?= e($tx['description'] ?: ucfirst($tx['type'])) ?> · <?= formatDate($tx['created_at']) ?></div>
          </div>
          <div class="fw-700 <?= $tc ?>"><?= $ts ?><?= formatCurrency((float)$tx['amount']) ?></div>
          <a href="?org=<?= $tx['org_id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fas fa-eye"></i></a>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Adjust Wallet Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-wallet me-2"></i>Adjust Wallet — <span id="adjOrgName">Select Org</span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="wallet_adjust">
        <input type="hidden" name="org_id" id="adjOrgId">
        <div class="modal-body">
          <!-- Org selector (when not pre-filled) -->
          <div class="mb-3" id="adjOrgPickerWrap">
            <label class="form-label">Organization</label>
            <select name="org_id" id="adjOrgPicker" class="form-select" onchange="updateAdjOrg(this)">
              <option value="">— Select Organization —</option>
              <?php foreach ($allOrgsForSelect as $o): ?>
              <option value="<?= $o['id'] ?>"><?= e($o['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Current Balance</label>
            <div class="fw-800 text-success" id="adjCurrentBal" style="font-size:1.3rem">—</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Transaction Type</label>
            <div class="d-flex gap-2">
              <label class="flex-fill">
                <input type="radio" name="type" value="topup" checked class="btn-check" id="typeTopup" autocomplete="off">
                <span class="btn btn-outline-success w-100" onclick="document.getElementById('typeTopup').checked=true">
                  <i class="fas fa-arrow-down me-1"></i>Top Up
                </span>
              </label>
              <label class="flex-fill">
                <input type="radio" name="type" value="deduction" class="btn-check" id="typeDeduct" autocomplete="off">
                <span class="btn btn-outline-danger w-100" onclick="document.getElementById('typeDeduct').checked=true">
                  <i class="fas fa-arrow-up me-1"></i>Deduct
                </span>
              </label>
              <label class="flex-fill">
                <input type="radio" name="type" value="refund" class="btn-check" id="typeRefund" autocomplete="off">
                <span class="btn btn-outline-info w-100" onclick="document.getElementById('typeRefund').checked=true">
                  <i class="fas fa-undo me-1"></i>Refund
                </span>
              </label>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Amount (KES) *</label>
            <div class="input-group">
              <span class="input-group-text">KES</span>
              <input type="number" name="amount" class="form-control" min="1" step="1" required placeholder="e.g. 1000">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label">Note / Reason</label>
            <input type="text" name="note" class="form-control" placeholder="e.g. Manual top-up per request, refund for overpayment…">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Apply Adjustment</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdjust(orgId, orgName, balance) {
  document.getElementById("adjOrgId").value      = orgId;
  document.getElementById("adjOrgName").textContent = orgName;
  document.getElementById("adjCurrentBal").textContent = "KES " + parseFloat(balance).toLocaleString("en-KE", {minimumFractionDigits:2});
  const picker = document.getElementById("adjOrgPicker");
  if (picker) { picker.value = orgId; document.getElementById("adjOrgPickerWrap").style.display = "none"; }
  new bootstrap.Modal(document.getElementById("adjustModal")).show();
}
function updateAdjOrg(sel) {
  const orgId = sel.value;
  if (!orgId) return;
  document.getElementById("adjOrgId").value = orgId;
  document.getElementById("adjOrgName").textContent = sel.options[sel.selectedIndex].text;
}
document.getElementById("adjustModal").addEventListener("hidden.bs.modal", function() {
  document.getElementById("adjOrgPickerWrap").style.display = "";
  document.getElementById("adjOrgName").textContent = "Select Org";
  document.getElementById("adjCurrentBal").textContent = "—";
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
