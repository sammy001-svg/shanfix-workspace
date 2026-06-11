<?php
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
    $user = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $staffId = (int)($_POST['staff_id'] ?? 0);
        $date = $_POST['appointment_date'] ?? date('Y-m-d');
        $time = $_POST['appointment_time'] ?? date('H:i:s');
        $status = in_array($_POST['status'] ?? '', ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show']) ? $_POST['status'] : 'scheduled';
        $amount = (float)($_POST['total_amount'] ?? 0);
        $paid = isset($_POST['paid']) ? 1 : 0;
        $notes = sanitize($_POST['notes'] ?? '');

        // Fallback pricing from service if amount is 0
        if ($amount <= 0 && $serviceId > 0) {
            $stmt = $pdo->prepare("SELECT price FROM salon_services WHERE id = ? AND org_id = ?");
            $stmt->execute([$serviceId, $orgId]);
            $amount = (float)$stmt->fetchColumn();
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE salon_appointments SET client_id = ?, service_id = ?, staff_id = ?, appointment_date = ?, appointment_time = ?, status = ?, total_amount = ?, paid = ?, notes = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$clientId, $serviceId, $staffId, $date, $time, $status, $amount, $paid, $notes, $id, $orgId]);
            setFlash('success', 'Appointment updated successfully.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO salon_appointments (org_id, client_id, service_id, staff_id, appointment_date, appointment_time, status, total_amount, paid, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$orgId, $clientId, $serviceId, $staffId, $date, $time, $status, $amount, $paid, $notes]);
            setFlash('success', 'Appointment booked successfully.');

            // SMS confirmation to client on new appointment
            try {
                $cl = $pdo->prepare("SELECT phone, name FROM salon_clients WHERE id = ? AND org_id = ?");
                $cl->execute([$clientId, $orgId]);
                $client = $cl->fetch();
                if ($client && !empty($client['phone'])) {
                    $apptDT = date('d/m/Y', strtotime($date)) . ' at ' . date('H:i', strtotime($time));
                    notifySms($client['phone'], APP_NAME . ": Hi {$client['name']}, your appointment on {$apptDT} is confirmed. Please arrive 5 mins early.", $orgId, 'appointment_confirmed');
                }
            } catch (Throwable $e) {}
        }
        logActivity($id > 0 ? 'update' : 'create', 'salon', "Appointment ID: $id / Client ID: $clientId");
        redirect('appointments.php');
    }

    if ($action === 'status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = sanitize($_POST['status'] ?? '');
        if (in_array($st, ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'])) {
            $stmt = $pdo->prepare("UPDATE salon_appointments SET status = ? WHERE id = ? AND org_id = ?");
            $stmt->execute([$st, $id, $orgId]);
            setFlash('success', 'Appointment status updated.');
        }
        redirect('appointments.php');
    }

    if ($action === 'pay') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE salon_appointments SET paid = 1 WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Appointment marked as PAID.');
        redirect('appointments.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM salon_appointments WHERE id = ? AND org_id = ?");
        $stmt->execute([$id, $orgId]);
        setFlash('success', 'Appointment deleted.');
        redirect('appointments.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fStaff = $_GET['staff_id'] ?? '';
$fDate = $_GET['date'] ?? '';

$where = 'a.org_id = ?';
$params = [$orgId];

if ($fStatus !== '') {
    $where .= ' AND a.status = ?';
    $params[] = $fStatus;
}
if ($fStaff !== '') {
    $where .= ' AND a.staff_id = ?';
    $params[] = (int)$fStaff;
}
if ($fDate !== '') {
    $where .= ' AND a.appointment_date = ?';
    $params[] = $fDate;
}

$appointments = [];
try {
    $stmt = $pdo->prepare("SELECT a.*, c.name AS client_name, c.phone AS client_phone, s.name AS service_name, s.duration_min, CONCAT(st.first_name, ' ', st.last_name) AS stylist_name 
                           FROM salon_appointments a 
                           LEFT JOIN salon_clients c ON a.client_id = c.id 
                           LEFT JOIN salon_services s ON a.service_id = s.id 
                           LEFT JOIN salon_staff st ON a.staff_id = st.id 
                           WHERE $where 
                           ORDER BY a.appointment_date DESC, a.appointment_time DESC");
    $stmt->execute($params);
    $appointments = $stmt->fetchAll();
} catch (Exception $e) {}

$clients = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, phone FROM salon_clients WHERE org_id = ? ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $clients = $stmt->fetchAll();
} catch (Exception $e) {}

$services = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, price, duration_min FROM salon_services WHERE org_id = ? AND status = 'active' ORDER BY name ASC");
    $stmt->execute([$orgId]);
    $services = $stmt->fetchAll();
} catch (Exception $e) {}

$staff = [];
try {
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, speciality FROM salon_staff WHERE org_id = ? AND status = 'active' ORDER BY first_name ASC");
    $stmt->execute([$orgId]);
    $staff = $stmt->fetchAll();
} catch (Exception $e) {}

$totalAppts = countRows('salon_appointments', 'org_id = ?', [$orgId]);
$pendingAppts = countRows('salon_appointments', "org_id = ? AND status IN ('scheduled', 'in_progress')", [$orgId]);
$completedAppts = countRows('salon_appointments', "org_id = ? AND status = 'completed'", [$orgId]);
$cancelledAppts = countRows('salon_appointments', "org_id = ? AND status = 'cancelled'", [$orgId]);
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Appointments</h4>
    <p class="text-muted mb-0">Book clients, track treatment progress, check-in and checkout</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#aModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Book Appointment</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon info-bg"><i class="fas fa-calendar-check"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalAppts ?></div>
        <div class="stat-label">Total Bookings</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $pendingAppts ?></div>
        <div class="stat-label">Active / Upcoming</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $completedAppts ?></div>
        <div class="stat-label">Completed Sessions</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg" style="background-color:rgba(192,57,43,0.1);color:#c0392b"><i class="fas fa-times-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $cancelledAppts ?></div>
        <div class="stat-label">Cancelled / No-shows</div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm">
          <option value="">All Statuses</option>
          <?php foreach (['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'] as $s): ?>
          <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Stylist / Staff</label>
        <select name="staff_id" class="form-select form-select-sm">
          <option value="">All Staff</option>
          <?php foreach ($staff as $st): ?>
          <option value="<?= $st['id'] ?>" <?= $fStaff == $st['id'] ? 'selected' : '' ?>><?= e($st['first_name'] . ' ' . $st['last_name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3">
        <label class="form-label small fw-semibold mb-1">Date</label>
        <input type="date" name="date" class="form-control form-control-sm" value="<?= e($fDate) ?>">
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="appointments.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-calendar-check me-2" style="color:<?= $moduleColor ?>"></i>Booking Schedule</h6>
    <span class="badge bg-secondary"><?= count($appointments) ?> bookings</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Date & Time</th>
            <th>Client</th>
            <th>Service</th>
            <th>Stylist</th>
            <th class="text-end">Amount</th>
            <th>Paid</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($appointments)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-calendar-times fa-2x mb-2 d-block"></i>No appointments found.</td></tr>
          <?php else: foreach ($appointments as $a): 
            $statusColors = ['scheduled' => 'info', 'in_progress' => 'warning text-dark', 'completed' => 'success', 'cancelled' => 'danger', 'no_show' => 'secondary'];
            $sc = $statusColors[$a['status']] ?? 'secondary';
          ?>
          <tr>
            <td class="fw-semibold">
              <?= formatDate($a['appointment_date']) ?> at <?= date('h:i A', strtotime($a['appointment_time'])) ?>
            </td>
            <td>
              <div class="fw-semibold"><?= e($a['client_name'] ?? 'Walk-in / Cash') ?></div>
              <small class="text-muted"><?= e($a['client_phone'] ?? '') ?></small>
            </td>
            <td>
              <div class="fw-semibold"><?= e($a['service_name'] ?? '—') ?></div>
              <small class="text-muted"><i class="far fa-clock me-1"></i><?= $a['duration_min'] ?> mins</small>
            </td>
            <td><?= e($a['stylist_name'] ?? '—') ?></td>
            <td class="text-end fw-semibold text-success"><?= formatCurrency((float)$a['total_amount']) ?></td>
            <td>
              <?php if ($a['paid']): ?>
              <span class="badge bg-success"><i class="fas fa-check-double me-1"></i>Paid</span>
              <?php else: ?>
              <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="pay">
                <?= csrfField() ?>
                <input type="hidden" name="id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-warning text-dark px-2 fw-semibold" title="Mark as Paid">Unpaid</button>
              </form>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-<?= $sc ?>"><?= ucfirst(str_replace('_', ' ', $a['status'])) ?></span>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <div class="btn-group btn-group-sm">
                <?php if ($a['status'] === 'scheduled'): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="status" value="in_progress">
                  <?= csrfField() ?>
                  <button type="submit" class="btn btn-outline-warning" title="Check In"><i class="fas fa-sign-in-alt"></i></button>
                </form>
                <?php elseif ($a['status'] === 'in_progress'): ?>
                <form method="POST" class="d-inline">
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $a['id'] ?>">
                  <input type="hidden" name="status" value="completed">
                  <?= csrfField() ?>
                  <button type="submit" class="btn btn-outline-success" title="Checkout / Complete"><i class="fas fa-check"></i></button>
                </form>
                <?php endif; ?>
                
                <button class="btn btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger ms-1" onclick="delAppt(<?= $a['id'] ?>)"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="aModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="aId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="aTitle"><i class="fas fa-calendar-check me-2"></i>Book Appointment</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Client <span class="text-danger">*</span></label>
        <select name="client_id" id="aClient" class="form-select" required>
          <option value="">-- select client --</option>
          <?php foreach ($clients as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= e($c['phone']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Service <span class="text-danger">*</span></label>
        <select name="service_id" id="aService" class="form-select" required onchange="updateServicePrice()">
          <option value="">-- select service --</option>
          <?php foreach ($services as $s): ?>
          <option value="<?= $s['id'] ?>" data-price="<?= $s['price'] ?>"><?= e($s['name']) ?> (<?= formatCurrency((float)$s['price']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Stylist / Staff <span class="text-danger">*</span></label>
        <select name="staff_id" id="aStaff" class="form-select" required>
          <option value="">-- select specialist --</option>
          <?php foreach ($staff as $st): ?>
          <option value="<?= $st['id'] ?>"><?= e($st['first_name'] . ' ' . $st['last_name']) ?> — <?= e($st['speciality']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
        <input type="date" name="appointment_date" id="aDate" class="form-control" required value="<?= date('Y-m-d') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label fw-semibold">Time <span class="text-danger">*</span></label>
        <input type="time" name="appointment_time" id="aTime" class="form-control" required value="<?= date('H:i') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Total Amount (<?= CURRENCY_SYMBOL ?>)</label>
        <input type="number" name="total_amount" id="aAmount" class="form-control" step="0.01" min="0" value="0">
      </div>
      <div class="col-md-4">
        <label class="form-label fw-semibold">Booking Status</label>
        <select name="status" id="aStatus" class="form-select">
          <option value="scheduled">Scheduled</option>
          <option value="in_progress">In Progress</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
          <option value="no_show">No Show</option>
        </select>
      </div>
      <div class="col-md-4 d-flex align-items-end">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" name="paid" id="aPaid" value="1">
          <label class="form-check-label fw-semibold" for="aPaid">Mark as Paid</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Notes / Special Instructions</label>
        <textarea name="notes" id="aNotes" class="form-control" rows="2" placeholder="e.g. Client prefers warm water, has sensitive scalp…"></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Appointment</button>
  </div>
  </form>
</div></div></div>

<form method="POST" id="delAForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delAId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function updateServicePrice() {
  const sel = document.getElementById('aService');
  const opt = sel.options[sel.selectedIndex];
  if (opt.dataset.price) {
    document.getElementById('aAmount').value = opt.dataset.price;
  }
}
function openAdd() {
  document.getElementById('aTitle').innerHTML = '<i class="fas fa-calendar-check me-2"></i>Book Appointment';
  ['aId', 'aNotes'].forEach(i => document.getElementById(i).value = i === 'aId' ? '0' : '');
  document.getElementById('aClient').value = '';
  document.getElementById('aService').value = '';
  document.getElementById('aStaff').value = '';
  document.getElementById('aAmount').value = 0;
  document.getElementById('aStatus').value = 'scheduled';
  document.getElementById('aPaid').checked = false;
  
  // Set current date & time
  const now = new Date();
  document.getElementById('aDate').value = now.toISOString().split('T')[0];
  document.getElementById('aTime').value = now.toTimeString().substring(0, 5);
}
function openEdit(a) {
  document.getElementById('aTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Appointment';
  document.getElementById('aId').value = a.id;
  document.getElementById('aClient').value = a.client_id || '';
  document.getElementById('aService').value = a.service_id || '';
  document.getElementById('aStaff').value = a.staff_id || '';
  document.getElementById('aDate').value = a.appointment_date || '';
  document.getElementById('aTime').value = a.appointment_time ? a.appointment_time.substring(0, 5) : '';
  document.getElementById('aAmount').value = a.total_amount || 0;
  document.getElementById('aStatus').value = a.status || 'scheduled';
  document.getElementById('aPaid').checked = parseInt(a.paid) === 1;
  document.getElementById('aNotes').value = a.notes || '';
  new bootstrap.Modal(document.getElementById('aModal')).show();
}
function delAppt(id) {
  Swal.fire({
    title: 'Delete Appointment?',
    text: 'This action will permanently delete the selected appointment scheduling slot.',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delAId').value = id;
      document.getElementById('delAForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
