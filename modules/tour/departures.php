<?php
// ── TOUR: Scheduled Departures & Seat Inventory ────────────────
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/_lib.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id']            ?? 0);
        $packageId    = (int)($_POST['package_id']    ?? 0);
        $startDate    = $_POST['start_date']          ?? '';
        $endDate      = ($_POST['end_date'] ?? '')  ?: null;
        $seatsTotal   = (int)($_POST['seats_total']   ?? 0);
        $minPax       = max(1, (int)($_POST['min_pax'] ?? 1));
        $priceAdult   = ($_POST['price_adult'] === '' || !isset($_POST['price_adult'])) ? null : (float)$_POST['price_adult'];
        $priceChild   = ($_POST['price_child'] === '' || !isset($_POST['price_child'])) ? null : (float)$_POST['price_child'];
        $guideId      = (int)($_POST['guide_id']      ?? 0) ?: null;
        $vehicleId    = (int)($_POST['vehicle_id']    ?? 0) ?: null;
        $meetingPoint = sanitize($_POST['meeting_point'] ?? '');
        $notes        = sanitize($_POST['notes']      ?? '');
        $status       = sanitize($_POST['status']     ?? 'scheduled');

        if ($packageId <= 0 || $startDate === '' || $seatsTotal <= 0) {
            setFlash('danger', 'Package, Start Date and Seat Capacity are required.');
            redirect('departures.php');
        }
        if ($endDate && $endDate < $startDate) {
            setFlash('danger', 'The return date cannot fall before the departure date.');
            redirect('departures.php');
        }
        // Package must belong to this org
        if (!assertOrgOwnership('tour_packages', $packageId, $orgId)) {
            setFlash('danger', 'Selected package is invalid.');
            redirect('departures.php');
        }

        if ($id === 0) {
            $code = tourNextNumber($orgId, 'tour_departures', 'departure_code', tourConf($orgId, 't_departure_prefix'));
            $stmt = $pdo->prepare("
                INSERT INTO tour_departures
                    (org_id, package_id, departure_code, start_date, end_date, seats_total, min_pax,
                     price_adult, price_child, guide_id, vehicle_id, meeting_point, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([$orgId, $packageId, $code, $startDate, $endDate, $seatsTotal, $minPax,
                            $priceAdult, $priceChild, $guideId, $vehicleId, $meetingPoint, $notes, $status]);
            setFlash('success', 'Departure ' . $code . ' scheduled successfully.');
            logActivity('create', 'tour', "Scheduled departure $code for package #$packageId");
        } else {
            // Never shrink capacity below seats already sold
            $sold = tourSeatsBooked($orgId, $id);
            if ($seatsTotal < $sold) {
                setFlash('danger', 'Capacity cannot be set below the ' . $sold . ' seats already booked on this departure.');
                redirect('departures.php');
            }
            $stmt = $pdo->prepare("
                UPDATE tour_departures
                SET package_id=?, start_date=?, end_date=?, seats_total=?, min_pax=?, price_adult=?, price_child=?,
                    guide_id=?, vehicle_id=?, meeting_point=?, notes=?, status=?, updated_at=NOW()
                WHERE id=? AND org_id=?
            ");
            $stmt->execute([$packageId, $startDate, $endDate, $seatsTotal, $minPax, $priceAdult, $priceChild,
                            $guideId, $vehicleId, $meetingPoint, $notes, $status, $id, $orgId]);
            setFlash('success', 'Departure updated successfully.');
            logActivity('update', 'tour', "Updated departure #$id");
        }
        redirect('departures.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $linked = countRows('tour_bookings', 'departure_id = ? AND org_id = ?', [$id, $orgId]);
        if ($linked > 0) {
            setFlash('danger', 'Cannot delete this departure — ' . $linked . ' booking(s) are already assigned to it. Cancel it instead.');
        } else {
            $pdo->prepare("DELETE FROM tour_departures WHERE id=? AND org_id=?")->execute([$id, $orgId]);
            setFlash('success', 'Departure removed.');
            logActivity('delete', 'tour', "Deleted departure #$id");
        }
        redirect('departures.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
require_once __DIR__ . '/_lib.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Filters ───────────────────────────────────────────────────
$filterStatus = sanitize($_GET['status'] ?? '');
$filterView   = sanitize($_GET['view']   ?? 'upcoming');

$where  = 'd.org_id = ?';
$params = [$orgId];
if ($filterStatus !== '' && in_array($filterStatus, ['scheduled','guaranteed','full','departed','completed','cancelled'], true)) {
    $where .= ' AND d.status = ?';
    $params[] = $filterStatus;
}
if ($filterView === 'upcoming') {
    $where .= " AND d.start_date >= CURDATE() AND d.status NOT IN ('cancelled','completed')";
} elseif ($filterView === 'past') {
    $where .= ' AND d.start_date < CURDATE()';
}

$departures = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, p.name AS package_name, p.duration_days, p.price_per_adult, p.price_per_child,
               dest.name AS dest_name, g.name AS guide_name, v.name AS vehicle_name, v.reg_no
        FROM tour_departures d
        JOIN tour_packages p        ON p.id = d.package_id
        LEFT JOIN tour_destinations dest ON dest.id = p.destination_id
        LEFT JOIN tour_guides g     ON g.id = d.guide_id
        LEFT JOIN tour_vehicles v   ON v.id = d.vehicle_id
        WHERE $where
        ORDER BY d.start_date ASC
    ");
    $stmt->execute($params);
    $departures = $stmt->fetchAll();
} catch (Throwable $e) {}

// Live seat position per departure
$seatMap = [];
foreach ($departures as $d) {
    $seatMap[$d['id']] = tourSeatStatus($orgId, (int)$d['id'], (int)$d['seats_total']);
}

// Selectors
$packages = $guides = $vehicles = [];
try {
    $stmt = $pdo->prepare("SELECT id, name, max_pax, duration_days, price_per_adult, price_per_child FROM tour_packages WHERE org_id=? AND status='active' ORDER BY name");
    $stmt->execute([$orgId]);
    $packages = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name FROM tour_guides WHERE org_id=? AND status <> 'inactive' ORDER BY name");
    $stmt->execute([$orgId]);
    $guides = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, name, reg_no, capacity FROM tour_vehicles WHERE org_id=? AND status <> 'retired' ORDER BY name");
    $stmt->execute([$orgId]);
    $vehicles = $stmt->fetchAll();
} catch (Throwable $e) {}

$packageMeta = [];
foreach ($packages as $p) {
    $packageMeta[$p['id']] = [
        'max_pax'  => (int)$p['max_pax'],
        'days'     => (int)$p['duration_days'],
        'adult'    => (float)$p['price_per_adult'],
        'child'    => (float)$p['price_per_child'],
    ];
}

// ── Headline stats ────────────────────────────────────────────
$statUpcoming = $statSeatsOpen = $statSeatsSold = 0;
$statGuaranteed = 0;
foreach ($departures as $d) {
    $s = $seatMap[$d['id']];
    if ($d['start_date'] >= date('Y-m-d') && !in_array($d['status'], ['cancelled','completed'], true)) {
        $statUpcoming++;
        $statSeatsOpen += $s['available'];
    }
    $statSeatsSold += $s['booked'];
    if ($d['status'] === 'guaranteed') $statGuaranteed++;
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-plane-departure me-2" style="color:<?= $moduleColor ?>"></i>Scheduled Departures</h4>
    <p class="text-muted mb-0">Publish fixed departure dates, control seat capacity and watch load factor in real time</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" onclick="openDeparture()">
    <i class="fas fa-plus me-1"></i>Schedule Departure
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon" style="background:rgba(41,128,185,.15);color:#2980b9"><i class="fas fa-calendar-day"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statUpcoming ?></div><div class="stat-label">Upcoming Departures</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-chair"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statSeatsOpen ?></div><div class="stat-label">Seats Still Open</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statSeatsSold ?></div><div class="stat-label">Seats Sold</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $statGuaranteed ?></div><div class="stat-label">Guaranteed To Run</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-3">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Period</label>
        <select name="view" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="upcoming" <?= $filterView === 'upcoming' ? 'selected' : '' ?>>Upcoming only</option>
          <option value="all"      <?= $filterView === 'all'      ? 'selected' : '' ?>>All departures</option>
          <option value="past"     <?= $filterView === 'past'     ? 'selected' : '' ?>>Past departures</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (['scheduled','guaranteed','full','departed','completed','cancelled'] as $st): ?>
          <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= tourLabel($st) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <a href="departures.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate-left me-1"></i>Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Departure board -->
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="departuresTable">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Package</th>
            <th>Dates</th>
            <th style="min-width:180px">Seat Load</th>
            <th>Crew</th>
            <th class="text-end">Lead Price</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($departures)): ?>
          <tr>
            <td colspan="8" class="text-center py-5 text-muted">
              <i class="fas fa-plane-slash fa-3x mb-3 d-block"></i>No departures match this view.
            </td>
          </tr>
          <?php else: foreach ($departures as $d):
              $s    = $seatMap[$d['id']];
              $bar  = $s['percent'] >= 100 ? 'bg-danger' : ($s['percent'] >= 70 ? 'bg-warning' : 'bg-success');
              $lead = $d['price_adult'] !== null ? (float)$d['price_adult'] : (float)$d['price_per_adult'];
              $shortfall = max(0, (int)$d['min_pax'] - $s['booked']);
          ?>
          <tr>
            <td><code class="text-dark bg-light px-2 py-1 rounded"><?= e($d['departure_code']) ?></code></td>
            <td>
              <div class="fw-semibold text-dark"><?= e($d['package_name']) ?></div>
              <div class="small text-muted"><i class="fas fa-map-marker-alt me-1"></i><?= e($d['dest_name'] ?: '—') ?></div>
            </td>
            <td>
              <div class="fw-semibold"><?= formatDate($d['start_date']) ?></div>
              <div class="small text-muted">
                <?= $d['end_date'] ? 'returns ' . formatDate($d['end_date']) : (int)$d['duration_days'] . ' days' ?>
              </div>
            </td>
            <td>
              <div class="d-flex justify-content-between small mb-1">
                <span class="fw-semibold"><?= $s['booked'] ?> / <?= $s['total'] ?></span>
                <span class="text-muted"><?= $s['available'] ?> open</span>
              </div>
              <div class="progress" style="height:6px">
                <div class="progress-bar <?= $bar ?>" style="width:<?= $s['percent'] ?>%"></div>
              </div>
              <?php if ($shortfall > 0 && !in_array($d['status'], ['cancelled','completed','departed'], true)): ?>
              <div class="small text-warning mt-1"><i class="fas fa-triangle-exclamation me-1"></i><?= $shortfall ?> more to reach minimum</div>
              <?php endif; ?>
            </td>
            <td class="small">
              <div><i class="fas fa-hiking me-1 text-muted"></i><?= e($d['guide_name'] ?: 'Unassigned') ?></div>
              <div class="text-muted"><i class="fas fa-bus me-1"></i><?= e($d['vehicle_name'] ? $d['vehicle_name'] . ' (' . $d['reg_no'] . ')' : 'Unassigned') ?></div>
            </td>
            <td class="text-end fw-bold"><?= formatCurrency($lead) ?></td>
            <td><?= statusBadge($d['status']) ?></td>
            <td class="text-end" style="white-space:nowrap">
              <a href="bookings.php?departure_id=<?= (int)$d['id'] ?>" class="btn btn-sm btn-outline-primary" title="Manifest">
                <i class="fas fa-clipboard-list"></i>
              </a>
              <button class="btn btn-sm btn-outline-secondary ms-1" onclick="editDeparture(<?= e(json_encode($d)) ?>)" title="Edit">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this departure permanently?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Departure Modal -->
<div class="modal fade" id="departureModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="depId" value="">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="depTitle"><i class="fas fa-plus me-2"></i>Schedule Departure</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Tour Package <span class="text-danger">*</span></label>
              <select name="package_id" id="depPackage" class="form-select" required onchange="applyPackageDefaults()">
                <option value="">-- Select Package --</option>
                <?php foreach ($packages as $p): ?>
                <option value="<?= (int)$p['id'] ?>"><?= e($p['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="form-text">Capacity and pricing prefill from the package; override below if this run differs.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Departure Date <span class="text-danger">*</span></label>
              <input type="date" name="start_date" id="depStart" class="form-control" required value="<?= date('Y-m-d') ?>" onchange="autoEndDate()">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Return Date</label>
              <input type="date" name="end_date" id="depEnd" class="form-control">
            </div>

            <div class="col-md-3">
              <label class="form-label fw-semibold">Seat Capacity <span class="text-danger">*</span></label>
              <input type="number" name="seats_total" id="depSeats" class="form-control" min="1" required value="10">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Minimum Pax</label>
              <input type="number" name="min_pax" id="depMin" class="form-control" min="1" value="1">
              <div class="form-text">Below this, the trip is not viable.</div>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Adult Price (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" min="0" name="price_adult" id="depAdult" class="form-control" placeholder="Package default">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Child Price (<?= CURRENCY ?>)</label>
              <input type="number" step="0.01" min="0" name="price_child" id="depChild" class="form-control" placeholder="Package default">
            </div>

            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned Guide</label>
              <select name="guide_id" id="depGuide" class="form-select">
                <option value="">-- Unassigned --</option>
                <?php foreach ($guides as $g): ?>
                <option value="<?= (int)$g['id'] ?>"><?= e($g['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Assigned Vehicle</label>
              <select name="vehicle_id" id="depVehicle" class="form-select">
                <option value="">-- Unassigned --</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= (int)$v['id'] ?>"><?= e($v['name'] . ' — ' . ($v['reg_no'] ?: 'no reg') . ' (' . (int)$v['capacity'] . ' seats)') ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="depStatus" class="form-select">
                <option value="scheduled">Scheduled</option>
                <option value="guaranteed">Guaranteed</option>
                <option value="full">Full</option>
                <option value="departed">Departed</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
            </div>

            <div class="col-md-12">
              <label class="form-label fw-semibold">Meeting Point</label>
              <input type="text" name="meeting_point" id="depMeeting" class="form-control" placeholder="e.g. Wilson Airport, Domestic Terminal — 06:30">
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Operations Notes</label>
              <textarea name="notes" id="depNotes" class="form-control" rows="2" placeholder="Internal notes for the ops team"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Departure</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
const packageMeta = ' . json_encode($packageMeta) . ';
let depModal;

$(document).ready(function(){
  $("#departuresTable").DataTable({pageLength:15, order:[[2,"asc"]]});
  depModal = new bootstrap.Modal(document.getElementById("departureModal"));
});

function openDeparture() {
  $("#depTitle").html("<i class=\"fas fa-plus me-2\"></i>Schedule Departure");
  $("#depId").val("");
  $("#depPackage").val("");
  $("#depStart").val("' . date('Y-m-d') . '");
  $("#depEnd").val("");
  $("#depSeats").val("10");
  $("#depMin").val("1");
  $("#depAdult").val("");
  $("#depChild").val("");
  $("#depGuide").val("");
  $("#depVehicle").val("");
  $("#depMeeting").val("");
  $("#depNotes").val("");
  $("#depStatus").val("scheduled");
  depModal.show();
}

function editDeparture(d) {
  $("#depTitle").html("<i class=\"fas fa-edit me-2\"></i>Edit " + (d.departure_code || "Departure"));
  $("#depId").val(d.id);
  $("#depPackage").val(d.package_id || "");
  $("#depStart").val(d.start_date || "");
  $("#depEnd").val(d.end_date || "");
  $("#depSeats").val(d.seats_total || 0);
  $("#depMin").val(d.min_pax || 1);
  $("#depAdult").val(d.price_adult === null ? "" : d.price_adult);
  $("#depChild").val(d.price_child === null ? "" : d.price_child);
  $("#depGuide").val(d.guide_id || "");
  $("#depVehicle").val(d.vehicle_id || "");
  $("#depMeeting").val(d.meeting_point || "");
  $("#depNotes").val(d.notes || "");
  $("#depStatus").val(d.status || "scheduled");
  depModal.show();
}

/* Prefill capacity + duration from the chosen package (new departures only) */
function applyPackageDefaults() {
  if ($("#depId").val()) return;           // editing: leave operator overrides alone
  const meta = packageMeta[$("#depPackage").val()];
  if (!meta) return;
  if (meta.max_pax > 0) $("#depSeats").val(meta.max_pax);
  autoEndDate();
}

/* Derive the return date from package duration */
function autoEndDate() {
  const meta = packageMeta[$("#depPackage").val()];
  const start = $("#depStart").val();
  if (!meta || !start || !meta.days) return;
  const d = new Date(start + "T00:00:00");
  d.setDate(d.getDate() + Math.max(0, meta.days - 1));
  $("#depEnd").val(d.toISOString().slice(0, 10));
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
