<?php
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',    'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',             'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',        'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',  'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',       'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',              'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',        'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',      'label' => 'Statements'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',         'label' => 'Reports'],
];

// ── AJAX ──────────────────────────────────────────────────────────────────
if (isset($_GET['fetch'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId = (int)$user['org_id'];
    $id    = (int)$_GET['fetch'];
    $stmt  = $pdo->prepare("SELECT s.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
                             FROM sacco_shares s
                             JOIN sacco_members m ON s.member_id=m.id
                             WHERE s.id=? AND s.org_id=?");
    $stmt->execute([$id, $orgId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_shares') {
        $memberId   = (int)$_POST['member_id'];
        $type       = $_POST['transaction_type'] ?? 'purchase';
        $shares     = (int)$_POST['shares'];
        $shareValue = (float)$_POST['share_value'];
        $date       = sanitize($_POST['transaction_date'] ?? date('Y-m-d'));
        $ref        = sanitize($_POST['reference'] ?? '');
        $notes      = sanitize($_POST['notes'] ?? '');

        if (!$memberId || $shares <= 0 || $shareValue <= 0) {
            setFlash('danger', 'Member, share count, and value are required.');
        } else {
            // Get current balance
            $bal = $pdo->prepare("SELECT COALESCE(total_shares,0) FROM sacco_members WHERE id=? AND org_id=?");
            $bal->execute([$memberId, $orgId]);
            $current = (int)$bal->fetchColumn();
            $balAfter = in_array($type, ['purchase','transfer_in','bonus'])
                ? $current + $shares
                : max(0, $current - $shares);
            $total = $shares * $shareValue;

            // Generate cert no
            $certNo = 'SH-' . date('Y') . '-' . str_pad(
                ($pdo->query("SELECT COUNT(*)+1 FROM sacco_shares WHERE org_id=$orgId")->fetchColumn()), 4, '0', STR_PAD_LEFT);

            $pdo->prepare("INSERT INTO sacco_shares (org_id,member_id,transaction_type,shares,share_value,total_amount,balance_shares,certificate_no,reference,notes,recorded_by,transaction_date)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$memberId,$type,$shares,$shareValue,$total,$balAfter,$certNo,$ref,$notes,$user['id'],$date]);

            $pdo->prepare("UPDATE sacco_members SET total_shares=? WHERE id=? AND org_id=?")
                ->execute([$balAfter, $memberId, $orgId]);

            setFlash('success', "Share transaction recorded. Certificate: $certNo");
        }
        redirect(APP_URL . '/modules/sacco/shares.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no, total_shares FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

// Share summary
$totalShareCapital = 0;
$totalShareholders = 0;
try {
    $r = $pdo->prepare("SELECT COUNT(DISTINCT member_id) AS holders, COALESCE(SUM(total_shares*share_value),0) AS capital
                        FROM sacco_shares WHERE org_id=? AND transaction_type IN ('purchase','transfer_in','bonus')");
    $r->execute([$orgId]);
    $summary = $r->fetch();
    $totalShareholders = (int)$summary['holders'];
    $totalShareCapital = (float)$summary['capital'];
} catch (Exception $e) {}

// Recent transactions
$transactions = [];
try {
    $stmt = $pdo->prepare("SELECT s.*, CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no
                           FROM sacco_shares s JOIN sacco_members m ON s.member_id=m.id
                           WHERE s.org_id=? ORDER BY s.transaction_date DESC, s.id DESC LIMIT 100");
    $stmt->execute([$orgId]);
    $transactions = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-certificate me-2" style="color:<?= $moduleColor ?>"></i>Share Capital</h4>
    <p class="text-muted mb-0">Track member share purchases, transfers, and certificates</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#shareModal">
    <i class="fas fa-plus me-2"></i>Record Transaction
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $moduleColor ?>20;color:<?= $moduleColor ?>"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalShareholders ?></div><div class="stat-label">Shareholders</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $moduleColor ?>20;color:<?= $moduleColor ?>"><i class="fas fa-certificate"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($transactions) ?></div><div class="stat-label">Transactions</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $moduleColor ?>20;color:<?= $moduleColor ?>"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalShareCapital) ?></div><div class="stat-label">Share Capital</div></div>
    </div>
  </div>
</div>

<!-- Member Share Summary -->
<div class="row g-4 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold">Top Shareholders</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Member</th><th class="text-end">Shares</th></tr></thead>
            <tbody>
              <?php
              $topShareholders = array_filter($members, fn($m) => $m['total_shares'] > 0);
              usort($topShareholders, fn($a,$b) => $b['total_shares'] - $a['total_shares']);
              foreach (array_slice($topShareholders, 0, 8) as $m): ?>
              <tr>
                <td><div class="fw-semibold small"><?= e($m['name']) ?></div><small class="text-muted"><?= e($m['member_no']) ?></small></td>
                <td class="text-end fw-bold" style="color:<?= $moduleColor ?>"><?= number_format($m['total_shares']) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($topShareholders)): ?>
              <tr><td colspan="2" class="text-center text-muted py-3">No shares recorded yet.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Share Transactions</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="sharesTable">
            <thead class="table-light">
              <tr><th>Date</th><th>Member</th><th>Type</th><th class="text-end">Shares</th><th class="text-end">Amount</th><th>Cert No</th></tr>
            </thead>
            <tbody>
              <?php foreach ($transactions as $t):
                $typeBadge = ['purchase'=>'success','transfer_in'=>'info','transfer_out'=>'warning','bonus'=>'primary','redemption'=>'danger'];
                $bg = $typeBadge[$t['transaction_type']] ?? 'secondary';
              ?>
              <tr>
                <td class="small"><?= formatDate($t['transaction_date']) ?></td>
                <td><div class="fw-semibold small"><?= e($t['member_name']) ?></div><small class="text-muted"><?= e($t['member_no']) ?></small></td>
                <td><span class="badge bg-<?= $bg ?>"><?= str_replace('_',' ', ucfirst($t['transaction_type'])) ?></span></td>
                <td class="text-end fw-bold"><?= number_format($t['shares']) ?></td>
                <td class="text-end"><?= formatCurrency($t['total_amount']) ?></td>
                <td><code class="small"><?= e($t['certificate_no']) ?></code></td>
              </tr>
              <?php endforeach; ?>
              <?php if (empty($transactions)): ?>
              <tr><td colspan="6" class="text-center text-muted py-4">No share transactions recorded.</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="shareModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-certificate me-2"></i>Record Share Transaction</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_shares">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Member <span class="text-danger">*</span></label>
            <select name="member_id" class="form-select" required>
              <option value="">— Select member —</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>) — <?= number_format($m['total_shares']) ?> shares</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Transaction Type</label>
            <select name="transaction_type" class="form-select">
              <option value="purchase">Purchase</option>
              <option value="transfer_in">Transfer In</option>
              <option value="transfer_out">Transfer Out</option>
              <option value="bonus">Bonus Shares</option>
              <option value="redemption">Redemption</option>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Transaction Date</label>
            <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Number of Shares <span class="text-danger">*</span></label>
            <input type="number" name="shares" class="form-control" min="1" required placeholder="e.g. 10">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Value per Share (<?= APP_CURRENCY ?? 'KES' ?>) <span class="text-danger">*</span></label>
            <input type="number" name="share_value" class="form-control" step="0.01" min="0.01" required placeholder="e.g. 100">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Reference</label>
            <input type="text" name="reference" class="form-control" maxlength="100" placeholder="Receipt no., transfer ref…">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Transaction</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#sharesTable").DataTable({pageLength:25,order:[[0,"desc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
