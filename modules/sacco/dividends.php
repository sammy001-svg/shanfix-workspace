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
    $stmt  = $pdo->prepare("SELECT * FROM sacco_dividends WHERE id=? AND org_id=?");
    $stmt->execute([$id, $orgId]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
    exit;
}

if (isset($_GET['payouts'])) {
    require_once __DIR__ . '/../../includes/header-module.php';
    $orgId = (int)$user['org_id'];
    $id    = (int)$_GET['payouts'];
    $stmt  = $pdo->prepare("SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no
                             FROM sacco_dividend_payouts p
                             JOIN sacco_members m ON p.member_id=m.id
                             WHERE p.dividend_id=? ORDER BY m.first_name");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_dividend') {
        $period    = sanitize($_POST['period_label'] ?? '');
        $from      = $_POST['period_from'] ?? '';
        $to        = $_POST['period_to']   ?? '';
        $pool      = (float)$_POST['total_pool'];
        $perShare  = (float)$_POST['per_share_rate'];
        $intRate   = (float)$_POST['interest_rate'];
        $notes     = sanitize($_POST['notes'] ?? '');

        if (!$period || !$from || !$to || $pool <= 0) {
            setFlash('danger', 'Period, dates, and pool amount are required.');
        } else {
            $pdo->prepare("INSERT INTO sacco_dividends (org_id,period_label,period_from,period_to,total_pool,per_share_rate,interest_rate,status,created_by)
                           VALUES (?,?,?,?,?,?,?,'draft',?)")
                ->execute([$orgId,$period,$from,$to,$pool,$perShare,$intRate,$user['id']]);
            setFlash('success', "Dividend declaration '$period' created as draft.");
        }
        redirect(APP_URL . '/modules/sacco/dividends.php');
    }

    if ($action === 'declare') {
        $id = (int)$_POST['dividend_id'];
        // Calculate payouts for all active members
        $div = $pdo->prepare("SELECT * FROM sacco_dividends WHERE id=? AND org_id=?");
        $div->execute([$id, $orgId]);
        $div = $div->fetch();
        if ($div && $div['status'] === 'draft') {
            $members = $pdo->prepare("SELECT id, total_shares, total_savings FROM sacco_members WHERE org_id=? AND status='active'");
            $members->execute([$orgId]);
            $pdo->prepare("DELETE FROM sacco_dividend_payouts WHERE dividend_id=?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO sacco_dividend_payouts (dividend_id,member_id,shares_at_decl,savings_at_decl,dividend_amount,interest_amount,total_payout,status)
                                  VALUES (?,?,?,?,?,?,?,'pending')");
            foreach ($members->fetchAll() as $m) {
                $divAmt  = $m['total_shares'] * $div['per_share_rate'];
                $intAmt  = $m['total_savings'] * ($div['interest_rate'] / 100);
                $total   = $divAmt + $intAmt;
                $ins->execute([$id, $m['id'], $m['total_shares'], $m['total_savings'], $divAmt, $intAmt, $total]);
            }
            $pdo->prepare("UPDATE sacco_dividends SET status='declared', declared_at=CURDATE() WHERE id=? AND org_id=?")
                ->execute([$id, $orgId]);
            setFlash('success', 'Dividend declared and payouts calculated for all active members.');
        }
        redirect(APP_URL . '/modules/sacco/dividends.php');
    }

    if ($action === 'mark_paid') {
        $id = (int)$_POST['dividend_id'];
        $pdo->prepare("UPDATE sacco_dividends SET status='paid', paid_at=CURDATE() WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        $pdo->prepare("UPDATE sacco_dividend_payouts SET status='paid', paid_at=NOW() WHERE dividend_id=? AND status='pending'")->execute([$id]);
        setFlash('success', 'All payouts marked as paid.');
        redirect(APP_URL . '/modules/sacco/dividends.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$dividends = [];
try {
    $stmt = $pdo->prepare("SELECT d.*,
                           (SELECT COUNT(*) FROM sacco_dividend_payouts WHERE dividend_id=d.id) AS member_count,
                           (SELECT COALESCE(SUM(total_payout),0) FROM sacco_dividend_payouts WHERE dividend_id=d.id) AS actual_payout
                           FROM sacco_dividends d WHERE d.org_id=? ORDER BY d.created_at DESC");
    $stmt->execute([$orgId]);
    $dividends = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-percentage me-2" style="color:<?= $moduleColor ?>"></i>Dividends</h4>
    <p class="text-muted mb-0">Declare dividends, calculate member payouts, and track distributions</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#divModal">
    <i class="fas fa-plus me-2"></i>New Declaration
  </button>
</div>

<?php if (empty($dividends)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
  <i class="fas fa-percentage fa-3x mb-3 d-block opacity-25"></i>
  No dividend declarations yet. Create your first one above.
</div></div>
<?php else: ?>
<div class="row g-3">
<?php foreach ($dividends as $d):
  $statusColors = ['draft'=>'secondary','declared'=>'info','paid'=>'success','cancelled'=>'danger'];
  $sc = $statusColors[$d['status']] ?? 'secondary';
?>
<div class="col-md-6 col-lg-4">
  <div class="card h-100">
    <div class="card-header d-flex align-items-center justify-content-between">
      <span class="fw-bold text-dark"><?= e($d['period_label']) ?></span>
      <span class="badge bg-<?= $sc ?>"><?= ucfirst($d['status']) ?></span>
    </div>
    <div class="card-body">
      <div class="row g-2 mb-3">
        <div class="col-6">
          <div class="small text-muted">Total Pool</div>
          <div class="fw-bold" style="color:<?= $moduleColor ?>"><?= formatCurrency($d['total_pool']) ?></div>
        </div>
        <div class="col-6">
          <div class="small text-muted">Actual Payout</div>
          <div class="fw-bold"><?= formatCurrency($d['actual_payout']) ?></div>
        </div>
        <div class="col-6">
          <div class="small text-muted">Per Share Rate</div>
          <div class="fw-semibold"><?= formatCurrency($d['per_share_rate']) ?></div>
        </div>
        <div class="col-6">
          <div class="small text-muted">Savings Interest</div>
          <div class="fw-semibold"><?= $d['interest_rate'] ?>%</div>
        </div>
        <div class="col-6">
          <div class="small text-muted">Period</div>
          <div class="small"><?= formatDate($d['period_from']) ?> – <?= formatDate($d['period_to']) ?></div>
        </div>
        <div class="col-6">
          <div class="small text-muted">Members</div>
          <div class="fw-semibold"><?= $d['member_count'] ?></div>
        </div>
      </div>
      <div class="d-flex flex-wrap gap-2">
        <button class="btn btn-xs btn-outline-secondary" onclick="viewPayouts(<?= $d['id'] ?>, '<?= e($d['period_label']) ?>')">
          <i class="fas fa-list me-1"></i>View Payouts
        </button>
        <?php if ($d['status'] === 'draft'): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Calculate and declare dividend for all active members?')">
          <?= csrfField() ?><input type="hidden" name="action" value="declare">
          <input type="hidden" name="dividend_id" value="<?= $d['id'] ?>">
          <button class="btn btn-xs btn-info text-white"><i class="fas fa-check me-1"></i>Declare</button>
        </form>
        <?php elseif ($d['status'] === 'declared'): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Mark all payouts as paid?')">
          <?= csrfField() ?><input type="hidden" name="action" value="mark_paid">
          <input type="hidden" name="dividend_id" value="<?= $d['id'] ?>">
          <button class="btn btn-xs btn-success"><i class="fas fa-money-bill-wave me-1"></i>Mark Paid</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- New Declaration Modal -->
<div class="modal fade" id="divModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-percentage me-2"></i>New Dividend Declaration</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save_dividend">
        <div class="modal-body row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Period Label <span class="text-danger">*</span></label>
            <input type="text" name="period_label" class="form-control" required placeholder="e.g. FY <?= date('Y') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Period From</label>
            <input type="date" name="period_from" class="form-control" value="<?= date('Y-01-01') ?>">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Period To</label>
            <input type="date" name="period_to" class="form-control" value="<?= date('Y-12-31') ?>">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Total Dividend Pool (<?= APP_CURRENCY ?? 'KES' ?>) <span class="text-danger">*</span></label>
            <input type="number" name="total_pool" class="form-control" step="0.01" min="1" required placeholder="e.g. 500000">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Per Share Rate</label>
            <input type="number" name="per_share_rate" class="form-control" step="0.0001" min="0" value="0" placeholder="e.g. 2.50">
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Savings Interest Rate (%)</label>
            <input type="number" name="interest_rate" class="form-control" step="0.01" min="0" value="0" placeholder="e.g. 8">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes</label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-2"></i>Save Draft</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payouts Modal -->
<div class="modal fade" id="payoutsModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title" id="payoutsTitle">Member Payouts</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0" id="payoutsTable">
            <thead class="table-light">
              <tr><th>Member</th><th class="text-end">Shares</th><th class="text-end">Savings</th><th class="text-end">Dividend</th><th class="text-end">Interest</th><th class="text-end">Total</th><th>Status</th></tr>
            </thead>
            <tbody id="payoutsTbody">
              <tr><td colspan="7" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function viewPayouts(id, label) {
    document.getElementById('payoutsTitle').textContent = 'Member Payouts — ' + label;
    document.getElementById('payoutsTbody').innerHTML = '<tr><td colspan="7" class="text-center py-3"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
    new bootstrap.Modal(document.getElementById('payoutsModal')).show();
    fetch('dividends.php?payouts=' + id)
        .then(r => r.json())
        .then(data => {
            if (!data.length) {
                document.getElementById('payoutsTbody').innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">No payouts calculated yet. Declare first.</td></tr>';
                return;
            }
            let html = '';
            for (const p of data) {
                const st = p.status === 'paid' ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-warning">Pending</span>';
                html += `<tr>
                    <td><strong>${p.member_name}</strong><br><small class="text-muted">${p.member_no}</small></td>
                    <td class="text-end">${Number(p.shares_at_decl).toLocaleString()}</td>
                    <td class="text-end">${Number(p.savings_at_decl).toLocaleString('en', {minimumFractionDigits:2})}</td>
                    <td class="text-end fw-semibold">${Number(p.dividend_amount).toLocaleString('en', {minimumFractionDigits:2})}</td>
                    <td class="text-end">${Number(p.interest_amount).toLocaleString('en', {minimumFractionDigits:2})}</td>
                    <td class="text-end fw-bold">${Number(p.total_payout).toLocaleString('en', {minimumFractionDigits:2})}</td>
                    <td>${st}</td>
                </tr>`;
            }
            document.getElementById('payoutsTbody').innerHTML = html;
        });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
