<?php
// ── Salon: Loyalty Program ─────────────────────────────────────
$moduleSlug = 'salon';
$moduleName = 'Salon & Spa';
$moduleIcon = 'fas fa-cut';
$moduleColor = '#c0392b';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'appointments.php', 'icon' => 'fas fa-calendar-check', 'label' => 'Appointments'],
    ['url' => 'clients.php',      'icon' => 'fas fa-users',          'label' => 'Clients'],
    ['url' => 'services.php',     'icon' => 'fas fa-concierge-bell', 'label' => 'Services'],
    ['url' => 'staff.php',        'icon' => 'fas fa-user-tie',       'label' => 'Staff'],
    ['url' => 'packages.php',     'icon' => 'fas fa-gift',           'label' => 'Packages'],
    ['url' => 'inventory.php',    'icon' => 'fas fa-boxes',          'label' => 'Inventory'],
    ['url' => 'loyalty.php',      'icon' => 'fas fa-star',           'label' => 'Loyalty'],
    ['url' => 'payments.php',     'icon' => 'fas fa-money-bill-wave','label' => 'Payments'],
    ['url' => 'expenses.php',     'icon' => 'fas fa-receipt',        'label' => 'Expenses'],
    ['url' => 'promotions.php',   'icon' => 'fas fa-tag',            'label' => 'Promotions'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'add_points') {
        $clientId  = (int)($_POST['client_id']  ?? 0);
        $points    = (int)($_POST['points']      ?? 0);
        $reason    = sanitize($_POST['reason']   ?? '');

        if ($clientId <= 0 || $points == 0) {
            setFlash('danger', 'Client and points are required.');
            redirect('loyalty.php');
        }

        try {
            $pdo->beginTransaction();
            // Upsert loyalty record
            $stmt = $pdo->prepare("SELECT id, points_balance FROM salon_loyalty WHERE org_id=? AND client_id=?");
            $stmt->execute([$orgId, $clientId]);
            $loyalty = $stmt->fetch();

            if ($loyalty) {
                $newBalance = (int)$loyalty['points_balance'] + $points;
                $pdo->prepare("UPDATE salon_loyalty SET points_balance=?, total_earned=total_earned+?, updated_at=NOW() WHERE id=? AND org_id=?")
                    ->execute([$newBalance, max(0, $points), $loyalty['id'], $orgId]);
                $lid = $loyalty['id'];
            } else {
                $newBalance = max(0, $points);
                $pdo->prepare("INSERT INTO salon_loyalty (org_id, client_id, points_balance, total_earned, total_redeemed) VALUES (?,?,?,?,0)")
                    ->execute([$orgId, $clientId, $newBalance, max(0, $points)]);
                $lid = $pdo->lastInsertId();
            }

            $pdo->prepare("INSERT INTO salon_loyalty_log (loyalty_id, points, movement_type, reason) VALUES (?,?,?,?)")
                ->execute([$lid, abs($points), $points > 0 ? 'earn' : 'redeem', $reason]);

            $pdo->commit();
            setFlash('success', ($points > 0 ? 'Added' : 'Redeemed') . ' ' . abs($points) . ' points. New balance: ' . $newBalance);
            logActivity('create', 'salon', "Loyalty: {$points} points for client #{$clientId}");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Error: ' . $e->getMessage());
        }
        redirect('loyalty.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$clients = $loyalty = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name FROM salon_clients WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]); $clients = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT l.*, c.first_name, c.last_name, c.phone
        FROM salon_loyalty l
        JOIN salon_clients c ON c.id = l.client_id
        WHERE l.org_id=?
        ORDER BY l.points_balance DESC
    ");
    $stmt->execute([$orgId]); $loyalty = $stmt->fetchAll();
} catch (Exception $e) {}

$totalPointsOutstanding = array_sum(array_column($loyalty, 'points_balance'));
$topClient = $loyalty[0] ?? null;

// Tier thresholds
function getLoyaltyTier(int $pts): array {
    if ($pts >= 5000) return ['Platinum', 'bg-dark', 'fas fa-crown'];
    if ($pts >= 2000) return ['Gold',     'bg-warning text-dark', 'fas fa-medal'];
    if ($pts >= 500)  return ['Silver',   'bg-secondary', 'fas fa-award'];
    return ['Bronze', 'bg-danger', 'fas fa-star'];
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-star me-2" style="color:<?= $moduleColor ?>"></i>Loyalty Program</h4>
    <p class="text-muted mb-0">Award and redeem client loyalty points, track tier status</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#loyaltyModal">
    <i class="fas fa-plus-circle me-1"></i>Add / Redeem Points
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(192,57,43,0.12);color:#c0392b"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($loyalty) ?></div><div class="stat-label">Enrolled Clients</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-star"></i></div>
      <div class="stat-body"><div class="stat-value"><?= number_format($totalPointsOutstanding) ?></div><div class="stat-label">Points Outstanding</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-crown"></i></div>
      <div class="stat-body">
        <div class="stat-value small"><?= $topClient ? e($topClient['first_name'].' '.$topClient['last_name']) : '—' ?></div>
        <div class="stat-label">Top Client <?= $topClient ? '('.number_format((int)$topClient['points_balance']).' pts)' : '' ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Tier legend -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-2">
    <div class="d-flex gap-3 align-items-center flex-wrap">
      <span class="fw-semibold text-muted small me-2">Tiers:</span>
      <span class="badge bg-danger"><i class="fas fa-star me-1"></i>Bronze (0–499 pts)</span>
      <span class="badge bg-secondary"><i class="fas fa-award me-1"></i>Silver (500–1,999 pts)</span>
      <span class="badge bg-warning text-dark"><i class="fas fa-medal me-1"></i>Gold (2,000–4,999 pts)</span>
      <span class="badge bg-dark"><i class="fas fa-crown me-1"></i>Platinum (5,000+ pts)</span>
    </div>
  </div>
</div>

<!-- Leaderboard -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="loyaltyTable">
        <thead class="table-light">
          <tr><th class="text-center">#</th><th>Client</th><th>Phone</th><th class="text-center">Tier</th><th class="text-center">Balance</th><th class="text-center">Total Earned</th><th class="text-center">Total Redeemed</th></tr>
        </thead>
        <tbody>
          <?php if (empty($loyalty)): ?>
          <tr><td colspan="7" class="text-center py-5 text-muted"><i class="fas fa-star fa-3x mb-3 d-block"></i>No loyalty records yet.</td></tr>
          <?php else: foreach ($loyalty as $i => $l):
            [$tier, $badge, $ico] = getLoyaltyTier((int)$l['points_balance']);
          ?>
          <tr>
            <td class="text-center fw-bold text-muted"><?= $i + 1 ?></td>
            <td>
              <div class="fw-semibold"><?= e($l['first_name'] . ' ' . $l['last_name']) ?></div>
            </td>
            <td class="text-muted small"><?= e($l['phone'] ?? '—') ?></td>
            <td class="text-center"><span class="badge <?= $badge ?>"><i class="<?= $ico ?> me-1"></i><?= $tier ?></span></td>
            <td class="text-center">
              <span class="fw-bold fs-6"><?= number_format((int)$l['points_balance']) ?></span>
              <div class="small text-muted">pts</div>
            </td>
            <td class="text-center text-success fw-semibold"><?= number_format((int)$l['total_earned']) ?></td>
            <td class="text-center text-danger"><?= number_format((int)$l['total_redeemed']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Points Modal -->
<div class="modal fade" id="loyaltyModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_points">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-star me-2"></i>Add / Redeem Points</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
            <select name="client_id" class="form-select" required>
              <option value="">-- Select Client --</option>
              <?php foreach ($clients as $c): ?>
              <option value="<?= $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Points <span class="text-danger">*</span></label>
            <input type="number" name="points" class="form-control form-control-lg fw-bold" required placeholder="Positive = Earn, Negative = Redeem">
            <div class="form-text">Use positive to earn (e.g. 50) or negative to redeem (e.g. -200).</div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Reason</label>
            <input type="text" name="reason" class="form-control" placeholder="e.g. Appointment completed, Points redemption">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
$(document).ready(function(){
    $("#loyaltyTable").DataTable({pageLength:25, order:[[4,"desc"]]});
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
