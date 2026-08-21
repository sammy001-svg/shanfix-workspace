<?php
// ── TOUR: Operations Dashboard ─────────────────────────────────
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';

$orgId = (int)$user['org_id'];

// ── Headline counters ─────────────────────────────────────────
$totalPackages  = countRows('tour_packages', 'org_id = ?', [$orgId]);
$totalBookings  = countRows('tour_bookings', 'org_id = ?', [$orgId]);
$upcomingTravel = countRows('tour_bookings', 'org_id = ? AND travel_date >= CURDATE() AND status != ?', [$orgId, 'cancelled']);

$totalRevenue = $outstanding = $tripCosts = 0.0;
$openQuotes   = 0;
$openQuoteValue = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM tour_bookings WHERE org_id=? AND status IN ('confirmed','completed')");
    $stmt->execute([$orgId]);
    $totalRevenue = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(GREATEST(total_amount - paid_amount, 0)),0)
        FROM tour_bookings WHERE org_id=? AND status IN ('pending','confirmed')
    ");
    $stmt->execute([$orgId]);
    $outstanding = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM tour_supplier_bookings WHERE org_id=? AND status <> 'cancelled'");
    $stmt->execute([$orgId]);
    $tripCosts = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM tour_expenses WHERE org_id=?");
    $stmt->execute([$orgId]);
    $tripCosts += (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(total_amount),0) FROM tour_quotations WHERE org_id=? AND status IN ('draft','sent','accepted')");
    $stmt->execute([$orgId]);
    [$openQuotes, $openQuoteValue] = array_values($stmt->fetch(PDO::FETCH_NUM) ?: [0, 0]);
} catch (Throwable $e) {}

$grossMargin  = $totalRevenue - $tripCosts;
$marginPct    = $totalRevenue > 0 ? round(($grossMargin / $totalRevenue) * 100) : 0;

// ── Next departures with their seat load ──────────────────────
$nextDepartures = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, p.name AS package_name, dest.name AS dest_name, g.name AS guide_name
        FROM tour_departures d
        JOIN tour_packages p             ON p.id = d.package_id
        LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
        LEFT JOIN tour_guides g          ON g.id = d.guide_id
        WHERE d.org_id = ? AND d.start_date >= CURDATE() AND d.status NOT IN ('cancelled','completed')
        ORDER BY d.start_date
        LIMIT 5
    ");
    $stmt->execute([$orgId]);
    $nextDepartures = $stmt->fetchAll();
} catch (Throwable $e) {}

// ── Recent bookings ───────────────────────────────────────────
$bookings = [];
try {
    $stmt = $pdo->prepare("
        SELECT b.*, p.name AS package_name, dest.name AS dest_name, d.departure_code
        FROM tour_bookings b
        LEFT JOIN tour_packages p        ON p.id = b.package_id
        LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
        LEFT JOIN tour_departures d      ON d.id = b.departure_id
        WHERE b.org_id = ?
        ORDER BY b.created_at DESC
        LIMIT 8
    ");
    $stmt->execute([$orgId]);
    $bookings = $stmt->fetchAll();
} catch (Throwable $e) {}

// ── Money needing attention ───────────────────────────────────
$overdueInvoices = [];
try {
    $stmt = $pdo->prepare("
        SELECT i.invoice_no, i.customer_name, i.due_date, i.total_amount, i.amount_paid, b.booking_no
        FROM tour_invoices i
        LEFT JOIN tour_bookings b ON b.id = i.booking_id
        WHERE i.org_id = ? AND i.status NOT IN ('draft','paid','cancelled')
          AND i.total_amount > i.amount_paid
        ORDER BY i.due_date IS NULL, i.due_date
        LIMIT 5
    ");
    $stmt->execute([$orgId]);
    $overdueInvoices = $stmt->fetchAll();
} catch (Throwable $e) {}

$supplierOwed = 0.0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(GREATEST(cost - amount_paid, 0)),0) FROM tour_supplier_bookings WHERE org_id=? AND status <> 'cancelled'");
    $stmt->execute([$orgId]);
    $supplierOwed = (float)$stmt->fetchColumn();
} catch (Throwable $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="<?= $moduleIcon ?> me-2" style="color:<?= $moduleColor ?>"></i><?= $moduleName ?></h4>
    <p class="text-muted mb-0">Departures, sales pipeline and trip margin at a glance</p>
  </div>
  <div class="d-flex gap-2">
    <a href="quotations.php" class="btn btn-outline-secondary"><i class="fas fa-file-signature me-1"></i>New Quote</a>
    <a href="bookings.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-plus me-1"></i>New Booking</a>
  </div>
</div>

<!-- ── Top row ──────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-suitcase"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalPackages ?></div><div class="stat-label">Tour Packages</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalBookings ?></div><div class="stat-label">Total Bookings</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-plane-departure"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $upcomingTravel ?></div><div class="stat-label">Upcoming Travel</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-sack-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenue) ?></div><div class="stat-label">Sales Booked</div></div>
    </div>
  </div>
</div>

<!-- ── Money row ────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(231,76,60,.15);color:#e74c3c"><i class="fas fa-hourglass-half"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($outstanding) ?></div><div class="stat-label">Owed By Clients</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-hand-holding-dollar"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($supplierOwed) ?></div><div class="stat-label">Owed To Suppliers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($tripCosts) ?></div><div class="stat-label">Cost Of Sales</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon <?= $grossMargin >= 0 ? 'green-bg' : '' ?>" style="<?= $grossMargin < 0 ? 'background:rgba(231,76,60,.15);color:#e74c3c' : '' ?>">
        <i class="fas fa-chart-line"></i>
      </div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($grossMargin) ?></div>
        <div class="stat-label">Gross Margin (<?= $marginPct ?>%)</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <!-- ── Next departures ────────────────────────────────────── -->
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-plane-departure me-2" style="color:<?= $moduleColor ?>"></i>Next Departures</h6>
        <a href="departures.php" class="btn btn-sm btn-outline-primary">Manage</a>
      </div>
      <div class="card-body">
        <?php if (empty($nextDepartures)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-calendar-plus fa-2x mb-2 d-block"></i>
          No departures scheduled. <a href="departures.php">Publish your first one</a>.
        </div>
        <?php else: foreach ($nextDepartures as $d):
            $s   = tourSeatStatus($orgId, (int)$d['id'], (int)$d['seats_total']);
            $bar = $s['percent'] >= 100 ? 'bg-danger' : ($s['percent'] >= 70 ? 'bg-warning' : 'bg-success');
        ?>
        <div class="d-flex align-items-center gap-3 mb-3">
          <div class="text-center flex-shrink-0" style="width:56px">
            <div class="fw-bold" style="font-size:1.25rem;line-height:1;color:<?= $moduleColor ?>"><?= date('d', strtotime($d['start_date'])) ?></div>
            <div class="small text-muted text-uppercase"><?= date('M', strtotime($d['start_date'])) ?></div>
          </div>
          <div class="flex-grow-1">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div>
                <div class="fw-semibold text-dark"><?= e($d['package_name']) ?></div>
                <div class="small text-muted">
                  <code class="bg-light px-1 rounded"><?= e($d['departure_code']) ?></code>
                  <?= $d['dest_name'] ? ' · ' . e($d['dest_name']) : '' ?>
                  <?= $d['guide_name'] ? ' · ' . e($d['guide_name']) : '' ?>
                </div>
              </div>
              <div class="text-end small text-nowrap">
                <span class="fw-semibold"><?= $s['booked'] ?>/<?= $s['total'] ?></span>
                <div class="text-muted"><?= $s['available'] ?> open</div>
              </div>
            </div>
            <div class="progress mt-2" style="height:5px">
              <div class="progress-bar <?= $bar ?>" style="width:<?= $s['percent'] ?>%"></div>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>

  <!-- ── Pipeline + receivables ─────────────────────────────── -->
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-file-signature me-2" style="color:<?= $moduleColor ?>"></i>Sales Pipeline</h6>
        <a href="quotations.php" class="btn btn-sm btn-outline-primary">Quotations</a>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <div>
            <div class="fs-4 fw-bold"><?= (int)$openQuotes ?></div>
            <div class="small text-muted">Open quotations</div>
          </div>
          <div class="text-end">
            <div class="fs-5 fw-bold text-success"><?= formatCurrency((float)$openQuoteValue) ?></div>
            <div class="small text-muted">Pipeline value</div>
          </div>
        </div>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex align-items-center justify-content-between">
        <h6 class="mb-0"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Awaiting Payment</h6>
        <a href="invoices.php" class="btn btn-sm btn-outline-primary">Invoices</a>
      </div>
      <div class="card-body p-0">
        <?php if (empty($overdueInvoices)): ?>
        <div class="text-center text-muted py-4">
          <i class="fas fa-circle-check fa-2x mb-2 d-block text-success"></i>Nothing outstanding.
        </div>
        <?php else: ?>
        <div class="list-group list-group-flush">
          <?php foreach ($overdueInvoices as $inv):
              $bal  = (float)$inv['total_amount'] - (float)$inv['amount_paid'];
              $late = $inv['due_date'] && $inv['due_date'] < date('Y-m-d');
          ?>
          <div class="list-group-item d-flex justify-content-between align-items-center">
            <div class="small">
              <div class="fw-semibold"><?= e($inv['customer_name']) ?></div>
              <div class="text-muted">
                <?= e($inv['invoice_no']) ?>
                <?= $inv['due_date'] ? ' · due ' . formatDate($inv['due_date']) : '' ?>
              </div>
            </div>
            <div class="text-end">
              <div class="fw-bold <?= $late ? 'text-danger' : '' ?>"><?= formatCurrency($bal) ?></div>
              <?php if ($late): ?><div class="small text-danger">Overdue</div><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Recent bookings ──────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
  <div class="card-header bg-white d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Recent Bookings</h6>
    <a href="bookings.php" class="btn btn-sm btn-outline-primary">View All</a>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="tourTable">
        <thead class="table-light">
          <tr>
            <th>Ref #</th><th>Client</th><th>Package</th><th>Departure</th>
            <th>Travel Date</th><th class="text-center">Pax</th><th>Status</th>
            <th class="text-end">Amount</th><th class="text-end">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($bookings)): ?>
          <tr><td colspan="9" class="text-center text-muted py-4"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No bookings found</td></tr>
          <?php else: foreach ($bookings as $b):
              $bal = max(0, (float)$b['total_amount'] - (float)$b['paid_amount']);
          ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($b['booking_no']) ?></code></td>
            <td class="fw-semibold"><?= e($b['customer_name']) ?></td>
            <td>
              <div><?= e($b['package_name'] ?: '—') ?></div>
              <div class="small text-muted"><?= e($b['dest_name'] ?: '') ?></div>
            </td>
            <td class="small"><?= e($b['departure_code'] ?: 'Private') ?></td>
            <td><?= formatDate($b['travel_date']) ?></td>
            <td class="text-center"><?= (int)$b['adults'] + (int)$b['children'] ?></td>
            <td><?= statusBadge($b['status']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$b['total_amount']) ?></td>
            <td class="text-end fw-bold <?= $bal > 0 ? 'text-danger' : 'text-success' ?>">
              <?= $bal > 0 ? formatCurrency($bal) : 'Settled' ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>$("#tourTable").DataTable({pageLength:10, order:[[4,"desc"]]});</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
