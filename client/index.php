<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../includes/header-client.php';

$orgId         = (int)$user['org_id'];
$sub           = getOrgSubscription($orgId);
$activeModules = getOrgModules($orgId);
$activeSlugs   = array_column($activeModules, 'slug');

// Trial progress
$trialDaysLeft = null;
$trialPct      = 0;
if ($sub && $sub['status'] === 'trial' && !empty($sub['trial_ends_at'])) {
    $trialDaysLeft = max(0, (int)ceil((strtotime($sub['trial_ends_at']) - time()) / 86400));
    $trialPct      = max(0, min(100, (int)round(((14 - $trialDaysLeft) / 14) * 100)));
}

// KPI counts
$userCount     = countRows('users', 'org_id = ? AND role != ?', [$orgId, 'super_admin']);
$openTickets   = 0;
$todayActivity = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM support_tickets WHERE org_id=? AND status IN ('open','in_progress')");
    $s->execute([$orgId]);
    $openTickets = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE org_id=? AND DATE(created_at)=CURDATE()");
    $s->execute([$orgId]);
    $todayActivity = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Per-module stats ─────────────────────────────────────────────
$modStats = [];

if (in_array('pos', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(total_amount),0) FROM pos_sales WHERE org_id=? AND DATE(created_at)=CURDATE() AND status='completed'");
        $s->execute([$orgId]);
        $modStats['pos'] = ['value' => formatCurrency((float)$s->fetchColumn()), 'label' => "Today's Sales",      'icon' => 'fas fa-cash-register', 'color' => '#1A8A4E', 'slug' => 'pos'];
    } catch (Exception $e) {}
}
if (in_array('salon', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM salon_appointments WHERE org_id=? AND appointment_date=CURDATE()");
        $s->execute([$orgId]);
        $modStats['salon'] = ['value' => (int)$s->fetchColumn(), 'label' => "Appts Today",         'icon' => 'fas fa-cut',           'color' => '#8b5cf6', 'slug' => 'salon'];
    } catch (Exception $e) {}
}
if (in_array('health', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM health_appointments WHERE org_id=? AND date=CURDATE()");
        $s->execute([$orgId]);
        $modStats['health'] = ['value' => (int)$s->fetchColumn(), 'label' => "Patients Today",      'icon' => 'fas fa-stethoscope',   'color' => '#ef4444', 'slug' => 'health'];
    } catch (Exception $e) {}
}
if (in_array('hotel', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM hotel_bookings WHERE org_id=? AND status='checked_in'");
        $s->execute([$orgId]);
        $modStats['hotel'] = ['value' => (int)$s->fetchColumn(), 'label' => "Current Guests",       'icon' => 'fas fa-bed',           'color' => '#0ea5e9', 'slug' => 'hotel'];
    } catch (Exception $e) {}
}
if (in_array('hrm', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM hrm_leave_requests WHERE org_id=? AND status='pending'");
        $s->execute([$orgId]);
        $v = (int)$s->fetchColumn();
        $modStats['hrm'] = ['value' => $v, 'label' => "Pending Leave",       'icon' => 'fas fa-user-clock',    'color' => '#f59e0b', 'slug' => 'hrm',    'alert' => $v > 0];
    } catch (Exception $e) {}
}
if (in_array('retail', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM retail_products WHERE org_id=? AND quantity <= reorder_level AND quantity >= 0");
        $s->execute([$orgId]);
        $v = (int)$s->fetchColumn();
        $modStats['retail'] = ['value' => $v, 'label' => "Low Stock Items",     'icon' => 'fas fa-box-open',      'color' => '#f97316', 'slug' => 'retail', 'alert' => $v > 0];
    } catch (Exception $e) {}
}
if (in_array('events', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM events WHERE org_id=? AND start_date >= CURDATE() AND status != 'cancelled'");
        $s->execute([$orgId]);
        $modStats['events'] = ['value' => (int)$s->fetchColumn(), 'label' => "Upcoming Events",     'icon' => 'fas fa-calendar-alt',  'color' => '#06b6d4', 'slug' => 'events'];
    } catch (Exception $e) {}
}
if (in_array('meetings', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM meetings WHERE org_id=? AND meeting_date=CURDATE() AND status != 'cancelled'");
        $s->execute([$orgId]);
        $modStats['meetings'] = ['value' => (int)$s->fetchColumn(), 'label' => "Meetings Today",      'icon' => 'fas fa-users',         'color' => '#10b981', 'slug' => 'meetings'];
    } catch (Exception $e) {}
}
if (in_array('sacco', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM sacco_loans WHERE org_id=? AND status='pending'");
        $s->execute([$orgId]);
        $v = (int)$s->fetchColumn();
        $modStats['sacco'] = ['value' => $v, 'label' => "Loan Applications",   'icon' => 'fas fa-hand-holding-usd', 'color' => '#6366f1', 'slug' => 'sacco', 'alert' => $v > 0];
    } catch (Exception $e) {}
}
if (in_array('tour', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM tour_bookings WHERE org_id=? AND status='confirmed'");
        $s->execute([$orgId]);
        $modStats['tour'] = ['value' => (int)$s->fetchColumn(), 'label' => "Active Bookings",     'icon' => 'fas fa-map-marked-alt', 'color' => '#059669', 'slug' => 'tour'];
    } catch (Exception $e) {}
}
if (in_array('rental', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM rental_payments WHERE org_id=? AND status='pending'");
        $s->execute([$orgId]);
        $v = (int)$s->fetchColumn();
        $modStats['rental'] = ['value' => $v, 'label' => "Pending Payments",   'icon' => 'fas fa-building',      'color' => '#dc2626', 'slug' => 'rental', 'alert' => $v > 0];
    } catch (Exception $e) {}
}
if (in_array('manufacturing', $activeSlugs)) {
    try {
        $s = $pdo->prepare("SELECT COUNT(*) FROM manufacturing_production WHERE org_id=? AND status='in_progress'");
        $s->execute([$orgId]);
        $modStats['manufacturing'] = ['value' => (int)$s->fetchColumn(), 'label' => "In Production",       'icon' => 'fas fa-industry',      'color' => '#475569', 'slug' => 'manufacturing'];
    } catch (Exception $e) {}
}

// ── Upcoming items (unified feed from all active modules) ────────
$upcoming = [];

if (in_array('salon', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT a.appointment_date AS udate, a.appointment_time AS utime,
                   COALESCE(c.name,'Walk-in') AS label, sv.name AS sub
            FROM salon_appointments a
            LEFT JOIN salon_clients  c  ON a.client_id  = c.id
            LEFT JOIN salon_services sv ON a.service_id = sv.id
            WHERE a.org_id=? AND a.appointment_date >= CURDATE()
              AND a.status NOT IN ('cancelled','completed')
            ORDER BY a.appointment_date, a.appointment_time LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate'] . ' ' . ($r['utime'] ?: '00:00')),
                'date'  => $r['udate'], 'time' => $r['utime'],
                'label' => $r['label'] . ($r['sub'] ? ' — ' . $r['sub'] : ''),
                'icon'  => 'fas fa-cut', 'color' => '#8b5cf6', 'type' => 'Salon',
                'url'   => APP_URL . '/modules/salon/appointments.php',
            ];
        }
    } catch (Exception $e) {}
}

if (in_array('health', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT a.date AS udate, a.time AS utime,
                   CONCAT(p.first_name,' ',p.last_name) AS label,
                   CONCAT(d.first_name,' ',d.last_name) AS sub
            FROM health_appointments a
            LEFT JOIN health_patients p ON a.patient_id = p.id
            LEFT JOIN health_doctors  d ON a.doctor_id  = d.id
            WHERE a.org_id=? AND a.date >= CURDATE()
              AND a.status NOT IN ('cancelled','completed')
            ORDER BY a.date, a.time LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate'] . ' ' . ($r['utime'] ?: '00:00')),
                'date'  => $r['udate'], 'time' => $r['utime'],
                'label' => trim($r['label']) . ($r['sub'] ? ' — Dr. ' . trim($r['sub']) : ''),
                'icon'  => 'fas fa-stethoscope', 'color' => '#ef4444', 'type' => 'Health',
                'url'   => APP_URL . '/modules/health/appointments.php',
            ];
        }
    } catch (Exception $e) {}
}

if (in_array('hotel', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT b.check_in AS udate, g.name AS label, r.room_number AS sub
            FROM hotel_bookings b
            LEFT JOIN hotel_guests g ON b.guest_id = g.id
            LEFT JOIN hotel_rooms  r ON b.room_id  = r.id
            WHERE b.org_id=? AND b.check_in >= CURDATE()
              AND b.status IN ('confirmed','pending')
            ORDER BY b.check_in LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate'] . ' 14:00'),
                'date'  => $r['udate'], 'time' => '14:00:00',
                'label' => ($r['label'] ?: 'Guest') . ' — Room ' . ($r['sub'] ?: '?'),
                'icon'  => 'fas fa-bed', 'color' => '#0ea5e9', 'type' => 'Hotel Check-in',
                'url'   => APP_URL . '/modules/hotel/bookings.php',
            ];
        }
    } catch (Exception $e) {}
}

if (in_array('events', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT start_date AS udate, title AS label, venue AS sub
            FROM events
            WHERE org_id=? AND start_date >= CURDATE() AND status != 'cancelled'
            ORDER BY start_date LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate']),
                'date'  => $r['udate'], 'time' => null,
                'label' => $r['label'] . ($r['sub'] ? ' @ ' . $r['sub'] : ''),
                'icon'  => 'fas fa-calendar-alt', 'color' => '#06b6d4', 'type' => 'Event',
                'url'   => APP_URL . '/modules/events/events.php',
            ];
        }
    } catch (Exception $e) {}
}

if (in_array('meetings', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT meeting_date AS udate, start_time AS utime, title AS label, location AS sub
            FROM meetings
            WHERE org_id=? AND meeting_date >= CURDATE() AND status != 'cancelled'
            ORDER BY meeting_date, start_time LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate'] . ' ' . ($r['utime'] ?: '00:00')),
                'date'  => $r['udate'], 'time' => $r['utime'],
                'label' => $r['label'] . ($r['sub'] ? ' @ ' . $r['sub'] : ''),
                'icon'  => 'fas fa-users', 'color' => '#10b981', 'type' => 'Meeting',
                'url'   => APP_URL . '/modules/meetings/meetings.php',
            ];
        }
    } catch (Exception $e) {}
}

if (in_array('tour', $activeSlugs)) {
    try {
        $s = $pdo->prepare("
            SELECT b.departure_date AS udate, c.name AS label, p.name AS sub
            FROM tour_bookings b
            LEFT JOIN tour_customers c ON b.customer_id = c.id
            LEFT JOIN tour_packages  p ON b.package_id  = p.id
            WHERE b.org_id=? AND b.departure_date >= CURDATE()
              AND b.status IN ('confirmed','pending')
            ORDER BY b.departure_date LIMIT 6");
        $s->execute([$orgId]);
        foreach ($s->fetchAll() as $r) {
            $upcoming[] = [
                'ts'    => strtotime($r['udate'] . ' 08:00'),
                'date'  => $r['udate'], 'time' => '08:00:00',
                'label' => ($r['label'] ?: 'Guest') . ($r['sub'] ? ' — ' . $r['sub'] : ''),
                'icon'  => 'fas fa-map-marked-alt', 'color' => '#059669', 'type' => 'Tour Departure',
                'url'   => APP_URL . '/modules/tour/bookings.php',
            ];
        }
    } catch (Exception $e) {}
}

usort($upcoming, fn($a, $b) => $a['ts'] <=> $b['ts']);
$upcoming = array_slice($upcoming, 0, 10);

// ── Recent activity ───────────────────────────────────────────────
$recentActivity = [];
try {
    $s = $pdo->prepare("
        SELECT al.*, u.name AS user_name
        FROM activity_log al
        LEFT JOIN users u ON al.user_id = u.id
        WHERE al.org_id=?
        ORDER BY al.created_at DESC LIMIT 10");
    $s->execute([$orgId]);
    $recentActivity = $s->fetchAll();
} catch (Exception $e) {}

// ── Unpaid invoices ───────────────────────────────────────────────
$unpaidInvoices = [];
$unpaidTotal    = 0.0;
try {
    $s = $pdo->prepare("
        SELECT id, invoice_number, total, due_date, status
        FROM   invoices
        WHERE  org_id=? AND status IN ('sent','pending','overdue')
        ORDER BY due_date ASC LIMIT 5");
    $s->execute([$orgId]);
    $unpaidInvoices = $s->fetchAll();
    $unpaidTotal    = array_sum(array_column($unpaidInvoices, 'total'));
} catch (Exception $e) {}

// ── Renewal countdown ─────────────────────────────────────────────
$renewalDaysLeft = null;
$renewalUrgency  = 'success';
if ($sub && !empty($sub['ends_at']) && in_array($sub['status'], ['active'])) {
    $renewalDaysLeft = max(0, (int)ceil((strtotime($sub['ends_at']) - time()) / 86400));
    $renewalUrgency  = $renewalDaysLeft <= 7 ? 'danger' : ($renewalDaysLeft <= 14 ? 'warning' : 'success');
}

// ── Unread notifications ──────────────────────────────────────────
$unreadNotifs = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE org_id=? AND is_read=0");
    $s->execute([$orgId]);
    $unreadNotifs = (int)$s->fetchColumn();
} catch (Exception $e) {}

// ── Recent open tickets ───────────────────────────────────────────
$recentTickets = [];
try {
    $s = $pdo->prepare("
        SELECT id, subject, status, priority, created_at
        FROM   support_tickets
        WHERE  org_id=? AND status IN ('open','in_progress')
        ORDER BY created_at DESC LIMIT 5");
    $s->execute([$orgId]);
    $recentTickets = $s->fetchAll();
} catch (Exception $e) {}

// ── Recent invoices (all statuses) ───────────────────────────────
$recentInvoices = [];
try {
    $s = $pdo->prepare("
        SELECT id, invoice_number, total, status, due_date, created_at
        FROM   invoices
        WHERE  org_id=?
        ORDER BY created_at DESC LIMIT 5");
    $s->execute([$orgId]);
    $recentInvoices = $s->fetchAll();
} catch (Exception $e) {}

// ── Quick-action links ────────────────────────────────────────────
$quickActions = [];
if (in_array('pos', $activeSlugs))
    $quickActions[] = ['label' => 'POS Terminal',    'icon' => 'fas fa-cash-register', 'color' => '#1A8A4E', 'url' => APP_URL . '/modules/pos/terminal.php'];
if (in_array('salon', $activeSlugs))
    $quickActions[] = ['label' => 'New Appointment', 'icon' => 'fas fa-calendar-plus', 'color' => '#8b5cf6', 'url' => APP_URL . '/modules/salon/appointments.php'];
if (in_array('health', $activeSlugs))
    $quickActions[] = ['label' => 'New Patient',     'icon' => 'fas fa-user-plus',     'color' => '#ef4444', 'url' => APP_URL . '/modules/health/patients.php'];
if (in_array('hotel', $activeSlugs))
    $quickActions[] = ['label' => 'New Booking',     'icon' => 'fas fa-bed',           'color' => '#0ea5e9', 'url' => APP_URL . '/modules/hotel/bookings.php'];
if (in_array('events', $activeSlugs))
    $quickActions[] = ['label' => 'Create Event',    'icon' => 'fas fa-calendar-plus', 'color' => '#06b6d4', 'url' => APP_URL . '/modules/events/events.php'];
if (in_array('meetings', $activeSlugs))
    $quickActions[] = ['label' => 'Schedule Meeting','icon' => 'fas fa-users',         'color' => '#10b981', 'url' => APP_URL . '/modules/meetings/meetings.php'];
$quickActions[] = ['label' => 'Billing',         'icon' => 'fas fa-file-invoice-dollar', 'color' => '#f59e0b', 'url' => APP_URL . '/client/billing.php'];
$quickActions[] = ['label' => 'Support',         'icon' => 'fas fa-headset',         'color' => '#6366f1', 'url' => APP_URL . '/client/support.php'];
$quickActions = array_slice($quickActions, 0, 6);

$actIcons = [
    'create'  => ['i' => 'fas fa-plus-circle', 'c' => '#1A8A4E'],
    'update'  => ['i' => 'fas fa-edit',         'c' => '#3b82f6'],
    'delete'  => ['i' => 'fas fa-trash',        'c' => '#ef4444'],
    'login'   => ['i' => 'fas fa-sign-in-alt',  'c' => '#6366f1'],
    'payment' => ['i' => 'fas fa-credit-card',  'c' => '#f59e0b'],
    'export'  => ['i' => 'fas fa-download',     'c' => '#06b6d4'],
];
?>

<!-- Page header -->
<div class="page-header d-flex align-items-start justify-content-between mb-3">
  <div>
    <h4 class="mb-1"><i class="fas fa-home me-2 text-green"></i>Welcome back, <?= e($user['name']) ?></h4>
    <p class="text-muted mb-0 small"><?= e($user['org_name']) ?> &nbsp;·&nbsp; <?= date('l, d F Y') ?></p>
  </div>
</div>

<?php if (!empty($unpaidInvoices)): ?>
<!-- Unpaid invoice alert -->
<div class="alert mb-4 d-flex align-items-center gap-3 p-3" style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px">
  <div style="width:40px;height:40px;border-radius:10px;background:#f97316;color:white;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">
    <i class="fas fa-file-invoice-dollar"></i>
  </div>
  <div class="flex-grow-1">
    <div class="fw-700 text-dark" style="font-size:.92rem">
      <?= count($unpaidInvoices) === 1 ? '1 unpaid invoice' : count($unpaidInvoices) . ' unpaid invoices' ?>
      &nbsp;— <?= formatCurrency($unpaidTotal) ?> outstanding
    </div>
    <div class="text-muted small">
      <?php foreach ($unpaidInvoices as $i => $inv):
          $isOverdue = $inv['status'] === 'overdue' || (!empty($inv['due_date']) && strtotime($inv['due_date']) < time());
      ?>
        <?php if ($i > 0): ?>&nbsp;·&nbsp;<?php endif; ?>
        <span class="<?= $isOverdue ? 'text-danger fw-600' : '' ?>">
          <?= e($inv['invoice_number']) ?><?= $isOverdue ? ' (overdue)' : '' ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-sm btn-warning fw-700 flex-shrink-0">
    <i class="fas fa-credit-card me-1"></i>Pay Now
  </a>
</div>
<?php endif; ?>

<!-- Quick action pill buttons -->
<?php if (!empty($quickActions)): ?>
<div class="d-flex flex-wrap gap-2 mb-4">
  <?php foreach ($quickActions as $qa): ?>
  <a href="<?= $qa['url'] ?>" class="btn btn-sm d-flex align-items-center gap-2 fw-600"
     style="background:<?= $qa['color'] ?>12;color:<?= $qa['color'] ?>;border:1.5px solid <?= $qa['color'] ?>30;border-radius:20px;padding:5px 14px">
    <i class="<?= $qa['icon'] ?>" style="font-size:.8rem"></i>
    <span style="font-size:.82rem"><?= $qa['label'] ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- KPI stat row -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-puzzle-piece"></i></div>
      <div><div class="stat-value" id="kpi-modules"><?= count($activeModules) ?></div><div class="stat-label">Active Modules</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div><div class="stat-value" id="kpi-users"><?= $userCount ?></div><div class="stat-label">Team Members</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $openTickets > 0 ? 'warning' : 'navy' ?>">
      <div class="stat-icon <?= $openTickets > 0 ? 'warning' : 'navy' ?>-bg"><i class="fas fa-headset"></i></div>
      <div><div class="stat-value" id="kpi-tickets"><?= $openTickets ?></div><div class="stat-label">Open Tickets</div></div>
    </div>
  </div>
  <div class="col-6 col-md-3">
    <a href="<?= APP_URL ?>/client/notifications.php" class="text-decoration-none">
      <div class="stat-card <?= $unreadNotifs > 0 ? 'warning' : 'green' ?>">
        <div class="stat-icon <?= $unreadNotifs > 0 ? 'warning' : 'green' ?>-bg"><i class="fas fa-bell<?= $unreadNotifs > 0 ? '' : '-slash' ?>"></i></div>
        <div><div class="stat-value" id="kpi-notif-badge"><?= $unreadNotifs ?></div><div class="stat-label">Unread Alerts</div></div>
      </div>
    </a>
  </div>
</div>

<!-- Module live stats -->
<?php if (!empty($modStats)): ?>
<div class="row g-2 mb-4">
  <?php foreach ($modStats as $slug => $ms): ?>
  <div class="col-6 col-sm-4 col-lg-3">
    <a href="<?= APP_URL ?>/modules/<?= $slug ?>/index.php" class="text-decoration-none">
      <div class="card border-0 shadow-sm h-100 p-3" style="border-radius:10px;<?= ($ms['alert'] ?? false) ? 'border-left:3px solid #f59e0b!important' : '' ?>">
        <div class="d-flex align-items-center gap-3">
          <div style="width:40px;height:40px;border-radius:10px;background:<?= $ms['color'] ?>1a;color:<?= $ms['color'] ?>;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0">
            <i class="<?= $ms['icon'] ?>"></i>
          </div>
          <div>
            <div class="fw-800 lh-1 text-dark" style="font-size:1.25rem"><?= $ms['value'] ?></div>
            <div class="text-muted" style="font-size:.72rem;margin-top:2px"><?= $ms['label'] ?></div>
          </div>
        </div>
      </div>
    </a>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Main two-column layout -->
<div class="row g-4">

  <!-- LEFT: Upcoming + Modules grid -->
  <div class="col-lg-7">

    <!-- Upcoming events feed -->
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-calendar-check text-green me-2"></i>Upcoming</span>
        <?php if (!empty($upcoming)): ?>
        <span class="badge bg-secondary rounded-pill"><?= count($upcoming) ?></span>
        <?php endif; ?>
      </div>
      <?php if (empty($upcoming)): ?>
      <div class="card-body text-center py-4 text-muted small">
        <i class="fas fa-calendar fa-2x mb-2 d-block opacity-40"></i>
        Nothing scheduled yet. Upcoming appointments, events, and bookings will appear here.
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php
        $today    = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        foreach ($upcoming as $u):
            $isToday    = substr($u['date'], 0, 10) === $today;
            $isTomorrow = substr($u['date'], 0, 10) === $tomorrow;
            $dateLabel  = $isToday ? 'Today' : ($isTomorrow ? 'Tomorrow' : date('D d M', $u['ts']));
            $timeStr    = !empty($u['time']) ? date('H:i', strtotime($u['time'])) : '';
        ?>
        <a href="<?= $u['url'] ?>" class="list-group-item list-group-item-action py-2 px-3">
          <div class="d-flex align-items-center gap-3">
            <div style="width:34px;height:34px;border-radius:8px;background:<?= $u['color'] ?>18;color:<?= $u['color'] ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.85rem">
              <i class="<?= $u['icon'] ?>"></i>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-600 small text-dark text-truncate"><?= e($u['label']) ?></div>
              <div class="text-muted" style="font-size:.7rem">
                <span class="badge me-1 fw-500" style="background:<?= $u['color'] ?>18;color:<?= $u['color'] ?>;font-size:.63rem;border-radius:10px"><?= $u['type'] ?></span>
                <?= $dateLabel ?><?= $timeStr ? ' · ' . $timeStr : '' ?>
              </div>
            </div>
            <?php if ($isToday): ?>
            <span class="badge bg-success flex-shrink-0" style="font-size:.63rem">Today</span>
            <?php endif; ?>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Active modules compact grid -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-th text-green me-2"></i>Your Modules</span>
        <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-sm btn-outline-primary btn-xs">Manage</a>
      </div>
      <div class="card-body">
        <?php if (empty($activeModules)): ?>
        <div class="text-center py-4">
          <i class="fas fa-puzzle-piece fa-2x text-muted mb-2 d-block opacity-50"></i>
          <p class="text-muted small mb-2">No modules active yet.</p>
          <a href="<?= APP_URL ?>/client/modules.php" class="btn btn-sm btn-primary">Browse Modules</a>
        </div>
        <?php else: ?>
        <div class="row g-2">
          <?php foreach ($activeModules as $m): ?>
          <div class="col-4 col-sm-3 col-md-2">
            <a href="<?= APP_URL ?>/modules/<?= e($m['slug']) ?>/index.php" class="text-decoration-none d-block">
              <div class="text-center p-2 rounded-2" style="border:1.5px solid <?= e($m['color']) ?>22;transition:.15s"
                   onmouseover="this.style.borderColor='<?= e($m['color']) ?>'" onmouseout="this.style.borderColor='<?= e($m['color']) ?>22'">
                <div class="mx-auto mb-1 d-flex align-items-center justify-content-center rounded-2"
                     style="width:34px;height:34px;background:<?= e($m['color']) ?>18;color:<?= e($m['color']) ?>">
                  <i class="<?= e($m['icon']) ?>" style="font-size:.8rem"></i>
                </div>
                <div class="fw-600 text-dark text-truncate" style="font-size:.65rem;line-height:1.3"><?= e($m['name']) ?></div>
              </div>
            </a>
          </div>
          <?php endforeach; ?>
          <div class="col-4 col-sm-3 col-md-2">
            <a href="<?= APP_URL ?>/client/modules.php" class="text-decoration-none d-block">
              <div class="text-center p-2 rounded-2" style="border:1.5px dashed #d1d5db;transition:.15s"
                   onmouseover="this.style.borderColor='#94a3b8'" onmouseout="this.style.borderColor='#d1d5db'">
                <div class="mx-auto mb-1 d-flex align-items-center justify-content-center rounded-2"
                     style="width:34px;height:34px;background:#f1f5f9;color:#94a3b8">
                  <i class="fas fa-plus" style="font-size:.8rem"></i>
                </div>
                <div class="text-muted text-truncate" style="font-size:.65rem;line-height:1.3">Add More</div>
              </div>
            </a>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- RIGHT: Tickets + Invoices + Subscription + Activity -->
  <div class="col-lg-5">

    <!-- Open support tickets -->
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-headset text-green me-2"></i>Open Tickets
          <?php if ($openTickets > 0): ?><span class="badge bg-danger ms-1 rounded-pill" style="font-size:.65rem"><?= $openTickets ?></span><?php endif; ?>
        </span>
        <a href="<?= APP_URL ?>/client/support.php" class="btn btn-xs btn-outline-primary btn-sm">New Ticket</a>
      </div>
      <?php if (empty($recentTickets)): ?>
      <div class="card-body text-center py-3 text-muted small">
        <i class="fas fa-check-circle text-success fa-2x mb-2 d-block opacity-60"></i>
        No open tickets — all clear!
      </div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php
        $pColors = ['high'=>'#ef4444','medium'=>'#f59e0b','low'=>'#6366f1','urgent'=>'#dc2626'];
        foreach ($recentTickets as $tk):
            $pc = $pColors[$tk['priority']] ?? '#94a3b8';
        ?>
        <a href="<?= APP_URL ?>/client/support.php?id=<?= $tk['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
          <div class="d-flex align-items-center gap-2">
            <span style="width:8px;height:8px;border-radius:50%;background:<?= $pc ?>;flex-shrink:0"></span>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-600 text-dark text-truncate small"><?= e($tk['subject']) ?></div>
              <div class="text-muted" style="font-size:.68rem">
                <?= ucfirst(str_replace('_',' ',$tk['status'])) ?> &nbsp;·&nbsp; <?= timeAgo($tk['created_at']) ?>
              </div>
            </div>
            <span class="badge rounded-pill flex-shrink-0" style="background:<?= $pc ?>18;color:<?= $pc ?>;font-size:.6rem"><?= ucfirst($tk['priority']) ?></span>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Recent invoices -->
    <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-file-invoice-dollar text-green me-2"></i>Recent Invoices</span>
        <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-xs btn-outline-secondary btn-sm">View All</a>
      </div>
      <?php if (empty($recentInvoices)): ?>
      <div class="card-body text-center py-3 text-muted small">No invoices yet.</div>
      <?php else: ?>
      <div class="list-group list-group-flush">
        <?php
        $invStatusColors = ['paid'=>'#1A8A4E','sent'=>'#3b82f6','pending'=>'#f59e0b','overdue'=>'#ef4444','draft'=>'#94a3b8'];
        foreach ($recentInvoices as $inv):
            $ic = $invStatusColors[$inv['status']] ?? '#94a3b8';
        ?>
        <a href="<?= APP_URL ?>/client/billing.php?inv=<?= $inv['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
          <div class="d-flex align-items-center gap-2">
            <div class="flex-grow-1 overflow-hidden">
              <div class="d-flex justify-content-between">
                <span class="fw-600 small text-dark"><?= e($inv['invoice_number']) ?></span>
                <span class="fw-700 small" style="color:<?= $ic ?>"><?= formatCurrency((float)$inv['total']) ?></span>
              </div>
              <div class="text-muted" style="font-size:.68rem">
                <span class="badge rounded-pill me-1" style="background:<?= $ic ?>18;color:<?= $ic ?>;font-size:.6rem"><?= ucfirst($inv['status']) ?></span>
                <?= !empty($inv['due_date']) ? 'Due ' . date('d M Y', strtotime($inv['due_date'])) : timeAgo($inv['created_at']) ?>
              </div>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Subscription snapshot -->
    <div class="card mb-4">
      <div class="card-header fw-bold"><i class="fas fa-layer-group text-green me-2"></i>Subscription</div>
      <div class="card-body">
        <?php if ($sub): ?>

        <?php if ($sub['status'] === 'trial' && $trialDaysLeft !== null): ?>
        <div class="mb-3 p-2 rounded-2 bg-warning bg-opacity-10">
          <div class="d-flex justify-content-between small mb-1">
            <span class="fw-600 text-warning">Free Trial</span>
            <span class="fw-600 text-warning"><?= $trialDaysLeft ?> day<?= $trialDaysLeft != 1 ? 's' : '' ?> left</span>
          </div>
          <div class="progress mb-1" style="height:5px">
            <div class="progress-bar bg-warning" style="width:<?= $trialPct ?>%"></div>
          </div>
          <div class="text-muted" style="font-size:.7rem">Ends <?= date('d M Y', strtotime($sub['trial_ends_at'])) ?></div>
        </div>
        <?php endif; ?>

        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Plan</span>
          <strong><?= e($sub['plan_name'] ?? 'Custom') ?></strong>
        </div>
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Status</span>
          <?= statusBadge($sub['status']) ?>
        </div>
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Amount</span>
          <strong class="text-green"><?= formatCurrency((float)$sub['amount']) ?>/<?= $sub['billing_cycle'] === 'annual' ? 'yr' : 'mo' ?></strong>
        </div>
        <?php if ($sub['ends_at']): ?>
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Renews</span>
          <div class="text-end">
            <strong><?= formatDate($sub['ends_at']) ?></strong>
            <?php if ($renewalDaysLeft !== null): ?>
            <div>
              <span class="badge bg-<?= $renewalUrgency ?> mt-1" style="font-size:.65rem">
                <?= $renewalDaysLeft === 0 ? 'Due today' : $renewalDaysLeft . ' day' . ($renewalDaysLeft != 1 ? 's' : '') . ' left' ?>
              </span>
            </div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($unpaidTotal > 0): ?>
        <div class="d-flex justify-content-between mb-2 small">
          <span class="text-muted">Outstanding</span>
          <strong class="text-danger"><?= formatCurrency($unpaidTotal) ?></strong>
        </div>
        <?php endif; ?>
        <div class="d-grid gap-2 mt-3">
          <a href="<?= APP_URL ?>/client/billing.php" class="btn btn-sm <?= $unpaidTotal > 0 ? 'btn-warning' : 'btn-outline-primary' ?>">
            <i class="fas fa-file-invoice-dollar me-1"></i><?= $unpaidTotal > 0 ? 'Pay Outstanding Balance' : 'Billing &amp; Invoices' ?>
          </a>
          <?php if (in_array($sub['status'], ['trial'])): ?>
          <a href="<?= APP_URL ?>/client/billing.php?tab=plans" class="btn btn-sm btn-success">
            <i class="fas fa-arrow-up me-1"></i>Upgrade Plan
          </a>
          <?php endif; ?>
        </div>

        <?php else: ?>
        <div class="text-center py-3 text-muted small">
          <i class="fas fa-layer-group fa-2x mb-2 d-block opacity-40"></i>
          No subscription found.
          <div class="mt-2"><a href="<?= APP_URL ?>/client/billing.php?tab=plans" class="btn btn-sm btn-primary">Choose a Plan</a></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Recent activity timeline -->
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between fw-bold">
        <span><i class="fas fa-history text-green me-2"></i>Recent Activity</span>
        <a href="<?= APP_URL ?>/client/search.php" class="btn btn-xs btn-outline-secondary btn-sm">Search</a>
      </div>
      <div style="max-height:260px;overflow-y:auto">
        <?php if (empty($recentActivity)): ?>
        <div class="text-center py-4 text-muted small">No activity recorded yet.</div>
        <?php else: foreach ($recentActivity as $act):
            $ai = $actIcons[$act['action']] ?? ['i' => 'fas fa-circle', 'c' => '#94a3b8'];
        ?>
        <div class="d-flex gap-3 px-3 py-2 border-bottom" style="font-size:.8rem">
          <div class="flex-shrink-0 pt-1">
            <i class="<?= $ai['i'] ?>" style="color:<?= $ai['c'] ?>;width:14px;text-align:center;font-size:.75rem"></i>
          </div>
          <div class="flex-grow-1 overflow-hidden">
            <div class="text-dark text-truncate"><?= e($act['description'] ?: ucfirst($act['action'])) ?></div>
            <div class="text-muted" style="font-size:.68rem">
              <?= e($act['user_name'] ?? 'System') ?> &nbsp;·&nbsp; <?= timeAgo($act['created_at']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

  </div><!-- /col right -->
</div><!-- /row -->

<?php
// Inject live-refresh IDs on the KPI stat cards
$extraJs = '<script>
/* ── Live dashboard KPI refresh every 60 seconds ──────────────── */
(function() {
  function refreshKpis() {
    fetch("' . APP_URL . '/api/dashboard-kpis.php")
      .then(r => r.json())
      .then(data => {
        if (!data.success) return;
        // Update each KPI if element exists
        Object.keys(data.kpis).forEach(key => {
          const el = document.getElementById("kpi-" + key);
          if (el) {
            const old = el.textContent;
            if (old !== String(data.kpis[key])) {
              el.textContent = data.kpis[key];
              el.classList.add("kpi-flash");
              setTimeout(() => el.classList.remove("kpi-flash"), 600);
            }
          }
        });
        // Unread notifications badge
        if (data.unread_notifs !== undefined) {
          const badge = document.getElementById("kpi-notif-badge");
          if (badge) badge.textContent = data.unread_notifs;
        }
      })
      .catch(() => {});
  }
  // Kick off after 60 seconds, then every 60 seconds
  setInterval(refreshKpis, 60000);
  // Soft refresh after 15 seconds on first load
  setTimeout(refreshKpis, 15000);
})();
</script>
<style>
@keyframes kpi-flash-anim { 0%{background:rgba(26,138,78,.15)} 100%{background:transparent} }
.kpi-flash { animation: kpi-flash-anim .6s ease-out; border-radius:6px; }
</style>';
require_once __DIR__ . '/../includes/footer.php'; ?>
