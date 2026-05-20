<?php
// ── EVENTS: Ticket Tiering & Inventory Control ─────────────────
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

    if ($action === 'add' || $action === 'edit') {
        $id         = (int)($_POST['id'] ?? 0);
        $eventId    = (int)($_POST['event_id']    ?? 0);
        $ticketType = sanitize($_POST['ticket_type'] ?? '');
        $price      = (float)($_POST['price']      ?? 0.00);
        $quantity   = (int)($_POST['quantity']   ?? 100);

        if ($eventId <= 0 || empty($ticketType) || $quantity <= 0) {
            setFlash('danger', 'Event, Ticket Type, and a positive Ticket Quantity are required.');
            redirect('tickets.php');
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO event_tickets (org_id, event_id, ticket_type, price, quantity, sold)
                VALUES (?,?,?,?,?, 0)
            ");
            $stmt->execute([$orgId, $eventId, $ticketType, $price, $quantity]);
            setFlash('success', 'Ticket tier ' . e($ticketType) . ' added successfully.');
            logActivity('create', 'events', "Added ticket tier '$ticketType' for event #$eventId");
        } else {
            // Verify new quantity is not less than already sold units
            $stmt = $pdo->prepare("SELECT sold FROM event_tickets WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            $sold = (int)$stmt->fetchColumn();

            if ($quantity < $sold) {
                setFlash('danger', 'Cannot reduce ticket capacity below the number of tickets already sold (' . $sold . ').');
            } else {
                $stmt = $pdo->prepare("
                    UPDATE event_tickets
                    SET event_id=?, ticket_type=?, price=?, quantity=?
                    WHERE id=? AND org_id=?
                ");
                $stmt->execute([$eventId, $ticketType, $price, $quantity, $id, $orgId]);
                setFlash('success', 'Ticket tier details updated successfully.');
                logActivity('update', 'events', "Updated ticket tier '$ticketType' (#$id)");
            }
        }
        redirect('tickets.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        // Safety Lock: Check if attendees exist for this ticket category
        $attendeeCount = countRows('event_attendees', 'ticket_id = ? AND org_id = ?', [$id, $orgId]);
        if ($attendeeCount > 0) {
            setFlash('danger', 'Cannot delete this ticket category because there are already ' . $attendeeCount . ' registered attendees holding it.');
        } else {
            $stmt = $pdo->prepare("DELETE FROM event_tickets WHERE id=? AND org_id=?");
            $stmt->execute([$id, $orgId]);
            setFlash('success', 'Ticket tier deleted successfully.');
            logActivity('delete', 'events', "Deleted ticket category #$id");
        }
        redirect('tickets.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// Retrieve drop-down selectors
$activeEvents = [];
try {
    $stmt = $pdo->prepare("SELECT id, title FROM events WHERE org_id=? AND status != 'cancelled' ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $activeEvents = $stmt->fetchAll();
} catch (Exception $e) {}

// Retrieve ticket tiers
$tickets = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, e.title as event_title, e.venue_capacity
        FROM event_tickets t
        JOIN events e ON t.event_id = e.id
        WHERE t.org_id = ?
        ORDER BY e.start_date DESC, t.price DESC
    ");
    $stmt->execute([$orgId]);
    $tickets = $stmt->fetchAll();
} catch (Exception $e) {}

// Stats metrics
$totalTicketsCount = count($tickets);
$totalSalesRevenue = 0.00;
$totalSoldTickets  = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(t.price * a.checked_in), 0), COUNT(a.id)
        FROM event_attendees a
        JOIN event_tickets t ON a.ticket_id = t.id
        WHERE a.org_id = ? AND a.payment_status = 'paid'
    ");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    // Let's compute overall ticket sales based on sold counts directly
    $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(price * sold), 0), COALESCE(SUM(sold), 0) FROM event_tickets WHERE org_id = ?");
    $stmt2->execute([$orgId]);
    $row2 = $stmt2->fetch(PDO::FETCH_NUM);
    $totalSalesRevenue = (float)$row2[0];
    $totalSoldTickets  = (int)$row2[1];
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-ticket-alt me-2" style="color:<?= $moduleColor ?>"></i>Ticket Inventory & Category Pricing</h4>
    <p class="text-muted mb-0">Create VIP, Regular, and Early Bird tiers and limit ticket sale capacity blocks</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#ticketModal" onclick="openAddModal()">
    <i class="fas fa-plus me-2"></i>Create Ticket Category
  </button>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon purple-bg" style="background:rgba(142,68,173,0.15);color:#8e44ad"><i class="fas fa-ticket-alt"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalTicketsCount ?></div><div class="stat-label">Ticket Tiers</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-coins"></i></div>
      <div class="stat-body"><div class="stat-value"><?= formatCurrency($totalSalesRevenue) ?></div><div class="stat-label">Total Booking Revenue</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-4">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-clipboard-check"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSoldTickets ?></div><div class="stat-label">Total Passes Sold</div></div>
    </div>
  </div>
</div>

<!-- Tickets Table -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="ticketsTable">
        <thead class="table-light">
          <tr>
            <th>Event Session</th>
            <th>Ticket Category Tier</th>
            <th class="text-end">Unit Price</th>
            <th class="text-end">Sold Units</th>
            <th class="text-end">Total Capacity</th>
            <th>Inventory Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($tickets)): ?>
          <tr>
            <td colspan="7" class="text-center py-5 text-muted">
              <i class="fas fa-ticket-alt fa-3x mb-3 d-block"></i>No ticket categories defined.
            </td>
          </tr>
          <?php else: foreach ($tickets as $t): 
            $sold     = (int)$t['sold'];
            $capacity = (int)$t['quantity'];
            $pct      = $capacity > 0 ? round(($sold / $capacity) * 100) : 0;
            $status   = '';
            if ($sold >= $capacity) {
                $status = '<span class="badge bg-danger">Sold Out</span>';
            } elseif ($pct >= 85) {
                $status = '<span class="badge bg-warning text-dark">Selling Fast</span>';
            } else {
                $status = '<span class="badge bg-success">Active</span>';
            }
          ?>
          <tr>
            <td><div class="fw-semibold text-dark"><?= e($t['event_title']) ?></div></td>
            <td><span class="badge bg-purple" style="background:#8e44ad"><?= e($t['ticket_type']) ?></span></td>
            <td class="text-end fw-bold text-success"><?= formatCurrency((float)$t['price']) ?></td>
            <td class="text-end">
              <strong><?= $sold ?></strong> sold
              <div class="progress mt-1" style="height:5px;width:100px;display:inline-block;vertical-align:middle;margin-left:8px">
                <div class="progress-bar <?= $pct >= 85 ? 'bg-warning' : 'bg-success' ?>" style="width:<?= $pct ?>%"></div>
              </div>
            </td>
            <td class="text-end"><strong><?= $capacity ?></strong> tickets</td>
            <td><?= $status ?></td>
            <td class="text-end" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-secondary" onclick="editTicket(<?= e(json_encode($t)) ?>)" title="Edit Category">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Are you sure you want to delete this ticket category?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete Category">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add / Edit Ticket Modal -->
<div class="modal fade" id="ticketModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" id="modalAction" value="add">
        <input type="hidden" name="id" id="ticketId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="modalTitle"><i class="fas fa-ticket-alt me-2"></i>Create Ticket Category</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Select Event Session <span class="text-danger">*</span></label>
            <select name="event_id" id="ticketEvent" class="form-select" required>
              <option value="">-- Select Event --</option>
              <?php foreach ($activeEvents as $ae): ?>
              <option value="<?= $ae['id'] ?>"><?= e($ae['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Ticket Category Tier <span class="text-danger">*</span></label>
            <input type="text" name="ticket_type" id="ticketType" class="form-control" required placeholder="e.g. VIP Pass, Regular Ticket">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Unit Price (<?= CURRENCY ?>) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="price" id="ticketPrice" class="form-control" required min="0" value="0.00" placeholder="0.00">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold">Maximum Quantity Capacity <span class="text-danger">*</span></label>
            <input type="number" name="quantity" id="ticketQuantity" class="form-control" required min="1" value="100" placeholder="100">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Category</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
$(document).ready(function(){
  $("#ticketsTable").DataTable({pageLength:10,order:[[0,"desc"]]});
});

function openAddModal() {
  $("#modalAction").val("add");
  $("#modalTitle").html("<i class=\"fas fa-ticket-alt me-2\"></i>Create Ticket Category");
  $("#ticketId").val("");
  $("#ticketEvent").val("");
  $("#ticketType").val("");
  $("#ticketPrice").val("0.00");
  $("#ticketQuantity").val("100");
}

function editTicket(t) {
  $("#modalAction").val("edit");
  $("#modalTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit Ticket Category");
  $("#ticketId").val(t.id);
  $("#ticketEvent").val(t.event_id || "");
  $("#ticketType").val(t.ticket_type || "");
  $("#ticketPrice").val(t.price || "0.00");
  $("#ticketQuantity").val(t.quantity || 100);

  new bootstrap.Modal(document.getElementById("ticketModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
