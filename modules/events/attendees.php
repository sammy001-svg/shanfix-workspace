<?php
// ── EVENTS: Attendees Management & Ticketing Check-in ──────────
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $eventId       = (int)($_POST['event_id']       ?? 0);
        $ticketId      = (int)($_POST['ticket_id']      ?? 0);
        $name          = sanitize($_POST['name']        ?? '');
        $email         = sanitize($_POST['email']       ?? '');
        $phone         = sanitize($_POST['phone']       ?? '');
        $paymentStatus = sanitize($_POST['payment_status'] ?? 'pending');

        if ($eventId <= 0 || $ticketId <= 0 || empty($name) || empty($email)) {
            setFlash('danger', 'Event, Ticket Tier, Name, and Email are required.');
            redirect('attendees.php');
        }

        // Fetch capacity limits & ticket status
        $stmt = $pdo->prepare("SELECT * FROM events WHERE id=? AND org_id=?");
        $stmt->execute([$eventId, $orgId]);
        $event = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT * FROM event_tickets WHERE id=? AND event_id=? AND org_id=?");
        $stmt->execute([$ticketId, $eventId, $orgId]);
        $ticket = $stmt->fetch();

        if (!$event || !$ticket) {
            setFlash('danger', 'Selected event or ticket category is invalid.');
            redirect('attendees.php');
        }

        // Capacity and sales quantity validations
        $currentAttendeeCount = countRows('event_attendees', 'event_id=? AND org_id=?', [$eventId, $orgId]);
        if ($currentAttendeeCount >= (int)$event['venue_capacity']) {
            setFlash('danger', 'Registration failed. The total venue capacity limit of ' . $event['venue_capacity'] . ' seats has been reached.');
            redirect('attendees.php');
        }

        if ((int)$ticket['sold'] >= (int)$ticket['quantity']) {
            setFlash('danger', 'Registration failed. The ticket tier ' . e($ticket['ticket_type']) . ' is fully sold out.');
            redirect('attendees.php');
        }

        // Generate high-end ticket serial
        $ticketNo = 'TKT-' . strtoupper(substr(md5(uniqid(microtime(), true)), 0, 8));

        try {
            $pdo->beginTransaction();

            // Insert attendee
            $stmt = $pdo->prepare("
                INSERT INTO event_attendees (org_id, event_id, ticket_id, name, email, phone, ticket_no, checked_in, payment_status)
                VALUES (?,?,?,?,?,?,?, 0, ?)
            ");
            $stmt->execute([$orgId, $eventId, $ticketId, $name, $email, $phone, $ticketNo, $paymentStatus]);

            // Increment sold count
            $stmt = $pdo->prepare("UPDATE event_tickets SET sold = sold + 1 WHERE id=?");
            $stmt->execute([$ticketId]);

            $pdo->commit();
            setFlash('success', 'Attendee successfully registered! Ticket Code: ' . $ticketNo);
            logActivity('create', 'events', "Registered attendee '$name' for event #$eventId");
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', 'Database error registering attendee: ' . $e->getMessage());
        }
        redirect('attendees.php');
    }

    if ($action === 'checkin') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE event_attendees SET checked_in = 1, checked_in_at = CURRENT_TIMESTAMP WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Attendee successfully checked in.');
        logActivity('update', 'events', "Checked-in attendee #$id");
        redirect('attendees.php');
    }

    if ($action === 'checkout') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE event_attendees SET checked_in = 0, checked_in_at = NULL WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Attendee check-in cancelled.');
        redirect('attendees.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Fetch ticket details to decrement
        $stmt = $pdo->prepare("SELECT ticket_id FROM event_attendees WHERE id=? AND org_id=?");
        $stmt->execute([$id, $orgId]);
        $attendee = $stmt->fetch();

        if ($attendee) {
            try {
                $pdo->beginTransaction();

                // Decrement sold count
                $stmt = $pdo->prepare("UPDATE event_tickets SET sold = GREATEST(0, sold - 1) WHERE id=?");
                $stmt->execute([$attendee['ticket_id']]);

                // Delete attendee
                $stmt = $pdo->prepare("DELETE FROM event_attendees WHERE id=? AND org_id=?");
                $stmt->execute([$id, $orgId]);

                $pdo->commit();
                setFlash('success', 'Registration cancelled and ticket slot returned to inventory.');
                logActivity('delete', 'events', "Deleted attendee registration #$id");
            } catch (Exception $e) {
                $pdo->rollBack();
                setFlash('danger', 'Error canceling registration: ' . $e->getMessage());
            }
        }
        redirect('attendees.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Fetch drop-down selectors
$activeEvents = [];
$ticketsGrouped = [];
try {
    // Fetch upcoming and active events
    $stmt = $pdo->prepare("SELECT id, title, venue_capacity FROM events WHERE org_id=? AND status != 'cancelled' ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $activeEvents = $stmt->fetchAll();

    // Fetch tickets list
    $stmt = $pdo->prepare("SELECT id, event_id, ticket_type, price, quantity, sold FROM event_tickets WHERE org_id=?");
    $stmt->execute([$orgId]);
    $allTickets = $stmt->fetchAll();
    foreach ($allTickets as $t) {
        $ticketsGrouped[$t['event_id']][] = [
            'id'       => $t['id'],
            'type'     => $t['ticket_type'],
            'price'    => (float)$t['price'],
            'quantity' => (int)$t['quantity'],
            'sold'     => (int)$t['sold']
        ];
    }
} catch (Exception $e) {}

// Retrieve attendees
$attendees = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, e.title as event_title, t.ticket_type, t.price
        FROM event_attendees a
        JOIN events e ON a.event_id = e.id
        JOIN event_tickets t ON a.ticket_id = t.id
        WHERE a.org_id = ?
        ORDER BY a.created_at DESC
    ");
    $stmt->execute([$orgId]);
    $attendees = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalRegisteredCount = count($attendees);
$checkedInCount       = countRows('event_attendees', 'org_id=? AND checked_in=1', [$orgId]);
$pendingArrivalCount  = $totalRegisteredCount - $checkedInCount;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-users me-2" style="color:<?= $moduleColor ?>"></i>Attendee Registry</h4>
    <p class="text-muted mb-0">Monitor gate entry, register event walk-ins, and manage pass codes</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#registerModal">
    <i class="fas fa-user-plus me-1"></i>Register Attendee
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalRegisteredCount ?></div><div class="stat-label">Total Registered Passholders</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-id-badge"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $checkedInCount ?></div><div class="stat-label">Verified Checked-In</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-user-clock"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $pendingArrivalCount ?></div><div class="stat-label">Expected / Pending Arrival</div></div>
    </div>
  </div>
</div>

<!-- Attendees Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="attendeesTable">
        <thead class="table-light">
          <tr>
            <th>Attendee</th>
            <th>Event Session</th>
            <th>Ticket serial</th>
            <th class="text-end">Paid Fee</th>
            <th>Pay Status</th>
            <th>Status</th>
            <th class="text-center">Gate Action</th>
            <th class="text-end">Cancel</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($attendees)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-users-cog fa-3x mb-3 d-block"></i>No attendee registrations logged.
            </td>
          </tr>
          <?php else: foreach ($attendees as $att): ?>
          <tr>
            <td>
              <div class="fw-semibold text-dark"><?= e($att['name']) ?></div>
              <div class="small text-muted"><i class="fas fa-envelope me-1"></i><?= e($att['email']) ?></div>
              <div class="small text-muted"><i class="fas fa-phone me-1"></i><?= e($att['phone'] ?: '—') ?></div>
            </td>
            <td>
              <span class="badge bg-secondary"><?= e($att['event_title']) ?></span>
              <div class="small text-muted mt-1"><?= e($att['ticket_type']) ?> Pass</div>
            </td>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($att['ticket_no']) ?></code></td>
            <td class="text-end fw-bold"><?= formatCurrency((float)$att['price']) ?></td>
            <td>
              <?php if ($att['payment_status'] === 'paid'): ?>
              <span class="badge bg-success"><i class="fas fa-check me-1"></i>PAID</span>
              <?php elseif ($att['payment_status'] === 'free'): ?>
              <span class="badge bg-info">FREE</span>
              <?php else: ?>
              <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i>PENDING</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($att['checked_in']): ?>
              <span class="badge bg-success"><i class="fas fa-check-double me-1"></i>Checked In</span>
              <div class="small text-muted mt-1" style="font-size:10px"><?= formatDateTime($att['checked_in_at']) ?></div>
              <?php else: ?>
              <span class="badge bg-secondary">Not Checked In</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <?php if ($att['checked_in']): ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="checkout">
                <input type="hidden" name="id" value="<?= $att['id'] ?>">
                <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-sign-out-alt me-1"></i>Checkout</button>
              </form>
              <?php else: ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="checkin">
                <input type="hidden" name="id" value="<?= $att['id'] ?>">
                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-sign-in-alt me-1"></i>Verify & Check-In</button>
              </form>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <form method="POST" class="d-inline" onsubmit="return confirm('Cancel registration? This attendee will be removed and ticket vacancy returned to stock.')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $att['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash-alt"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Register Attendee Modal -->
<div class="modal fade" id="registerModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="register">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Register Attendee</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Event Session <span class="text-danger">*</span></label>
            <select name="event_id" id="regEvent" class="form-select" required onchange="populateEventTickets()">
              <option value="">-- Select Event Session --</option>
              <?php foreach ($activeEvents as $ae): ?>
              <option value="<?= $ae['id'] ?>"><?= e($ae['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Ticket Tier <span class="text-danger">*</span></label>
            <select name="ticket_id" id="regTicket" class="form-select" required>
              <option value="">-- Select Event First --</option>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Jane Doe">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
            <input type="email" name="email" class="form-control" required placeholder="e.g. jane.doe@example.com">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Phone Number</label>
            <input type="text" name="phone" class="form-control" placeholder="e.g. +254 712 345678">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Payment Status</label>
            <select name="payment_status" class="form-select">
              <option value="pending">Pending Payment</option>
              <option value="paid">Paid</option>
              <option value="free">Free / Complimentary</option>
            </select>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Register Attendee</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
const groupedTickets = ' . json_encode($ticketsGrouped) . ';

$(document).ready(function(){
  $("#attendeesTable").DataTable({pageLength:10,order:[[0,"desc"]]});
});

function populateEventTickets() {
  const eventId = $("#regEvent").val();
  const select = $("#regTicket");
  select.empty();

  if (!eventId) {
    select.append("<option value=\"\">-- Select Event First --</option>");
    return;
  }

  const tickets = groupedTickets[eventId] || [];
  if (tickets.length === 0) {
    select.append("<option value=\"\">-- No Tickets Available --</option>");
    return;
  }

  tickets.forEach(function(t) {
    const remaining = t.quantity - t.sold;
    const disabled = remaining <= 0 ? "disabled" : "";
    const label = t.type + " (KES " + t.price.toFixed(2) + ") - " + remaining + " remaining";
    select.append("<option value=\"" + t.id + "\" " + disabled + ">" + label + "</option>");
  });
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
