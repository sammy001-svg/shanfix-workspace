<?php
// ── EVENTS: Analytical Reports & Charts ────────────────────────
$moduleSlug  = 'events';
$moduleName  = 'Events Management';
$moduleIcon  = 'fas fa-calendar-alt';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',   'label' => 'Events'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-ticket-alt',     'label' => 'Tickets'],
    ['url' => 'attendees.php', 'icon' => 'fas fa-users',          'label' => 'Attendees'],
    ['url' => 'schedule.php',  'icon' => 'fas fa-list-ol',        'label' => 'Schedule'],
    ['url' => 'budget.php',    'icon' => 'fas fa-wallet',         'label' => 'Budget'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch Core Totals
$totalAttendeesRegistered = 0;
$totalVerifiedCheckedIn   = 0;
$totalRevenueGenerated    = 0.00;
$avgAttendanceRate        = 0.00;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_attendees WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalAttendeesRegistered = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM event_attendees WHERE org_id = ? AND checked_in = 1");
    $stmt->execute([$orgId]);
    $totalVerifiedCheckedIn = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(sold * price), 0) FROM event_tickets WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $totalRevenueGenerated = (float)$stmt->fetchColumn();

    $avgAttendanceRate = $totalAttendeesRegistered > 0 ? round(($totalVerifiedCheckedIn / $totalAttendeesRegistered) * 100, 1) : 0.00;
} catch (Exception $e) {}

// Monthly trends over last 6 months
$months = [];
$eventTrend = [];
$revenueTrend = [];

for ($i = 5; $i >= 0; $i--) {
    $date = date('Y-m', strtotime("-$i months"));
    $months[] = date('M Y', strtotime("-$i months"));
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id=? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
        $stmt->execute([$orgId, $date]);
        $eventTrend[] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(t.price), 0)
            FROM event_attendees a
            JOIN event_tickets t ON a.ticket_id = t.id
            WHERE a.org_id=? AND a.payment_status = 'paid' AND DATE_FORMAT(a.created_at, '%Y-%m') = ?
        ");
        $stmt->execute([$orgId, $date]);
        $revenueTrend[] = (float)$stmt->fetchColumn();
    } catch (Exception $e) {
        $eventTrend[]   = 0;
        $revenueTrend[] = 0.00;
    }
}

// Ticket Tier distribution
$ticketTierLabels = [];
$ticketTierValues = [];
try {
    $stmt = $pdo->prepare("SELECT ticket_type, SUM(sold) as sold_cnt FROM event_tickets WHERE org_id=? GROUP BY ticket_type");
    $stmt->execute([$orgId]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) {
        $ticketTierLabels[] = $r['ticket_type'];
        $ticketTierValues[] = (int)$r['sold_cnt'];
    }
} catch (Exception $e) {}

// Detailed Event Revenue breakdown table
$eventBreakdown = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.title, e.venue_capacity, e.ticket_price, e.status,
               COALESCE(SUM(t.sold), 0) as total_sold,
               COALESCE(SUM(t.sold * t.price), 0) as total_rev,
               COALESCE((SELECT COUNT(*) FROM event_attendees WHERE event_id = e.id AND checked_in = 1), 0) as checkins
        FROM events e
        LEFT JOIN event_tickets t ON e.id = t.event_id
        WHERE e.org_id = ?
        GROUP BY e.id
        ORDER BY e.start_date DESC
    ");
    $stmt->execute([$orgId]);
    $eventBreakdown = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>Events Analytical Dashboard</h4>
    <p class="text-muted mb-0">Evaluate booking rates, check-in completion, and event venue capacity usage</p>
  </div>
  <a href="report-pdf.php" class="btn btn-outline-secondary"><i class="fas fa-file-pdf me-1"></i>Export PDF</a>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalAttendeesRegistered ?></div><div class="stat-label">Registered Attendees</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalRevenueGenerated) ?></div><div class="stat-label">Total Booking Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalVerifiedCheckedIn ?></div><div class="stat-label">Checked-In Gate Entrants</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-percentage"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $avgAttendanceRate ?>%</div><div class="stat-label">Average Attendance Rate</div></div>
    </div>
  </div>
</div>

<!-- Charts Row -->
<div class="row g-4 mb-4">
  <!-- Monthly Event Booking Trends -->
  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-line me-2 text-primary"></i>Ticketing Sales Booking Trends (Last 6 Months)</h6>
      </div>
      <div class="card-body">
        <div style="height:320px">
          <canvas id="monthlyTrendChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Check-in Conversion Doughnut -->
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-id-card me-2 text-danger"></i>Gate Attendance Completion Rate</h6>
      </div>
      <div class="card-body">
        <div style="height:320px;display:flex;align-items:center;justify-content:center">
          <canvas id="attendanceRateChart"></canvas>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Ticket sales type Pie Chart -->
  <div class="col-lg-4 col-md-6 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-pie-chart me-2 text-success"></i>Ticket Categories Proportion</h6>
      </div>
      <div class="card-body">
        <div style="height:250px;display:flex;align-items:center;justify-content:center">
          <canvas id="ticketCategoriesChart"></canvas>
        </div>
      </div>
    </div>
  </div>

  <!-- Event breakdowns table -->
  <div class="col-lg-8 col-12">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-clipboard-list me-2 text-secondary"></i>Detailed Event Performance Summary</h6>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th class="ps-3">Event Session</th>
                <th class="text-center">Tickets Sold</th>
                <th class="text-center">Gate Checkins</th>
                <th class="text-center">Attendance %</th>
                <th class="text-end pe-3">Revenue Value</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($eventBreakdown)): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">No events created.</td></tr>
              <?php else: foreach ($eventBreakdown as $eb): 
                $soldVal = (int)$eb['total_sold'];
                $attPct  = $soldVal > 0 ? round(((int)$eb['checkins'] / $soldVal) * 100) : 0;
              ?>
              <tr>
                <td class="fw-semibold text-dark ps-3">
                  <?= e($eb['title']) ?>
                  <div class="small text-muted mt-1">Capacity: <?= $eb['venue_capacity'] ?> slots</div>
                </td>
                <td class="text-center fw-bold"><?= $soldVal ?></td>
                <td class="text-center text-success"><i class="fas fa-user-check me-1"></i><?= $eb['checkins'] ?></td>
                <td class="text-center">
                  <span class="badge <?= $attPct >= 75 ? 'bg-success' : 'bg-secondary' ?>"><?= $attPct ?>%</span>
                </td>
                <td class="text-end pe-3 fw-bold text-success"><?= formatCurrency((float)$eb['total_rev']) ?></td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
$(document).ready(function(){
  // 1. Monthly revenue trend
  var trendCtx = document.getElementById("monthlyTrendChart").getContext("2d");
  new Chart(trendCtx, {
    type: "line",
    data: {
      labels: ' . json_encode($months) . ',
      datasets: [
        {
          label: "Ticketing Revenue (KES)",
          data: ' . json_encode($revenueTrend) . ',
          backgroundColor: "rgba(142, 68, 173, 0.15)",
          borderColor: "#8e44ad",
          borderWidth: 3,
          fill: true,
          tension: 0.3
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return "KES " + value.toLocaleString();
            }
          }
        }
      }
    }
  });

  // 2. Attendance rates doughnut
  var attCtx = document.getElementById("attendanceRateChart").getContext("2d");
  new Chart(attCtx, {
    type: "doughnut",
    data: {
      labels: ["Checked-In", "Absent / Expected"],
      datasets: [{
        data: [' . $totalVerifiedCheckedIn . ', ' . ($totalAttendeesRegistered - $totalVerifiedCheckedIn) . '],
        backgroundColor: ["#2ecc71", "#95a5a6"],
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });

  // 3. Ticket proportions pie
  var ticketCtx = document.getElementById("ticketCategoriesChart").getContext("2d");
  new Chart(ticketCtx, {
    type: "pie",
    data: {
      labels: ' . json_encode($ticketTierLabels) . ',
      datasets: [{
        data: ' . json_encode($ticketTierValues) . ',
        backgroundColor: [
          "#8e44ad", "#3498db", "#e67e22", "#2ecc71", "#e74c3c", "#f1c40f"
        ],
        borderWidth: 2
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: "bottom"
        }
      }
    }
  });
});
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
