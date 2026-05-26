<?php
// ── TOUR: Trip Itineraries ──────────────────────────────────────
$moduleSlug  = 'tour';
$moduleName  = 'Tour & Travel';
$moduleIcon  = 'fas fa-plane';
$moduleColor = '#2980b9';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'packages.php',     'icon' => 'fas fa-box-open',       'label' => 'Packages'],
    ['url' => 'destinations.php', 'icon' => 'fas fa-map-marker-alt', 'label' => 'Destinations'],
    ['url' => 'bookings.php',     'icon' => 'fas fa-calendar-check', 'label' => 'Bookings'],
    ['url' => 'customers.php',    'icon' => 'fas fa-user-friends',   'label' => 'Customers'],
    ['url' => 'guides.php',       'icon' => 'fas fa-hiking',         'label' => 'Guides'],
    ['url' => 'itineraries.php', 'icon' => 'fas fa-route',           'label' => 'Itineraries'],
    ['url' => 'vehicles.php',    'icon' => 'fas fa-bus',             'label' => 'Vehicles'],
    ['url' => 'payments.php',    'icon' => 'fas fa-money-bill-wave', 'label' => 'Payments'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $packageId   = (int)($_POST['package_id'] ?? 0) ?: null;
        $bookingId   = (int)($_POST['booking_id'] ?? 0) ?: null;
        $title       = sanitize($_POST['title'] ?? '');
        $dayNumber   = (int)($_POST['day_number'] ?? 1);
        $tripDate    = $_POST['trip_date'] ?? null;
        $description = sanitize($_POST['description'] ?? '');
        $location    = sanitize($_POST['location'] ?? '');
        $activity    = sanitize($_POST['activity_type'] ?? '');
        $startTime   = $_POST['start_time'] ?? null;
        $endTime     = $_POST['end_time'] ?? null;
        $notes       = sanitize($_POST['notes'] ?? '');
        if (!$title) { setFlash('error', 'Itinerary title is required.'); redirect('itineraries.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE tour_itineraries SET package_id=?,booking_id=?,title=?,day_number=?,trip_date=?,description=?,location=?,activity_type=?,start_time=?,end_time=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$packageId,$bookingId,$title,$dayNumber,$tripDate?:null,$description,$location,$activity,$startTime,$endTime,$notes,$id,$orgId]);
            setFlash('success', 'Itinerary updated.');
        } else {
            $pdo->prepare("INSERT INTO tour_itineraries(org_id,package_id,booking_id,title,day_number,trip_date,description,location,activity_type,start_time,end_time,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$packageId,$bookingId,$title,$dayNumber,$tripDate?:null,$description,$location,$activity,$startTime,$endTime,$notes]);
            setFlash('success', "Day $dayNumber: '$title' added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'tour', "Itinerary: $title");
        redirect('itineraries.php' . ($packageId ? "?package_id=$packageId" : ''));
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM tour_itineraries WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Itinerary item deleted.'); redirect('itineraries.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fPackage = (int)($_GET['package_id'] ?? 0);
$fBooking = (int)($_GET['booking_id'] ?? 0);

$where = 'i.org_id=?'; $params = [$orgId];
if ($fPackage) { $where .= ' AND i.package_id=?'; $params[] = $fPackage; }
if ($fBooking) { $where .= ' AND i.booking_id=?'; $params[] = $fBooking; }

$items = [];
try {
    $s = $pdo->prepare("
        SELECT i.*,p.name AS package_name,
               CONCAT(c.first_name,' ',c.last_name) AS customer_name
        FROM tour_itineraries i
        LEFT JOIN tour_packages p ON i.package_id=p.id
        LEFT JOIN tour_bookings b ON i.booking_id=b.id
        LEFT JOIN tour_customers c ON b.customer_id=c.id
        WHERE $where ORDER BY i.package_id, i.day_number ASC, i.start_time ASC
    ");
    $s->execute($params); $items = $s->fetchAll();
} catch (Exception $e) {}

$packages = [];
try { $s = $pdo->prepare("SELECT id,name FROM tour_packages WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $packages = $s->fetchAll(); } catch (Exception $e) {}
$bookings = [];
try { $s = $pdo->prepare("SELECT b.id,CONCAT(c.first_name,' ',c.last_name,' — ',b.booking_reference) AS label FROM tour_bookings b LEFT JOIN tour_customers c ON b.customer_id=c.id WHERE b.org_id=? ORDER BY b.id DESC LIMIT 50"); $s->execute([$orgId]); $bookings = $s->fetchAll(); } catch (Exception $e) {}

$activityTypes = ['Sightseeing','Safari','Hiking','Beach','Cultural Visit','Museum','Adventure Sports','Dinner/Lunch','Transfer','Free Time','Other'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-route me-2" style="color:<?=$moduleColor?>"></i>Itineraries</h4>
    <p class="text-muted mb-0">Build day-by-day tour itineraries for packages and bookings</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#itModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Itinerary Item
  </button>
</div>

<!-- Package filter -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Package</label>
      <select name="package_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Packages</option>
        <?php foreach ($packages as $p): ?><option value="<?=$p['id']?>" <?=$fPackage==$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Booking</label>
      <select name="booking_id" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">All Bookings</option>
        <?php foreach ($bookings as $b): ?><option value="<?=$b['id']?>" <?=$fBooking==$b['id']?'selected':''?>><?=e($b['label'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><a href="itineraries.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
  </form>
</div></div>

<?php
// Group by package/day
$grouped = [];
foreach ($items as $it) {
    $key = ($it['package_name'] ?? 'No Package') . ($it['booking_id'] ? ' — Booking #'.$it['booking_id'] : '');
    $grouped[$key][$it['day_number']][] = $it;
}
?>

<?php if (empty($items)): ?>
<div class="card"><div class="card-body text-center text-muted py-5">
  <i class="fas fa-route fa-3x mb-3 d-block"></i>No itinerary items found. Add day-by-day activities for your packages.
</div></div>
<?php else: foreach ($grouped as $groupName => $days): ?>
<div class="card mb-4">
  <div class="card-header" style="background:<?=$moduleColor?>22;border-left:4px solid <?=$moduleColor?>">
    <h6 class="mb-0 fw-bold" style="color:<?=$moduleColor?>"><i class="fas fa-box-open me-2"></i><?=e($groupName)?></h6>
  </div>
  <div class="card-body p-0">
    <?php foreach ($days as $dayNum => $dayItems): ?>
    <div class="p-3 border-bottom bg-light"><strong>Day <?=$dayNum?></strong><?=isset($dayItems[0]['trip_date'])&&$dayItems[0]['trip_date']?' — '.formatDate($dayItems[0]['trip_date']):''?></div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Time</th><th>Activity</th><th>Location</th><th>Type</th><th class="text-center">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($dayItems as $it): ?>
        <tr>
          <td class="small text-muted"><?=($it['start_time']?substr($it['start_time'],0,5):'').(($it['start_time']&&$it['end_time'])?'–'.substr($it['end_time'],0,5):'')?></td>
          <td><div class="fw-semibold"><?=e($it['title'])?></div><?=$it['description']?'<small class="text-muted">'.e(mb_substr($it['description'],0,80)).'</small>':''?></td>
          <td class="small"><?=e($it['location']??'—')?></td>
          <td><span class="badge bg-info text-dark"><?=e($it['activity_type']??'—')?></span></td>
          <td class="text-center" style="white-space:nowrap">
            <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($it),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
            <button class="btn btn-sm btn-outline-danger ms-1" onclick="delIt(<?=$it['id']?>,<?=json_encode($it['title'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; endif; ?>

<!-- Modal -->
<div class="modal fade" id="itModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="itId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="itTitle"><i class="fas fa-route me-2"></i>Add Itinerary Item</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-6"><label class="form-label fw-semibold">Package</label>
      <select name="package_id" id="itPkg" class="form-select">
        <option value="">— None —</option>
        <?php foreach ($packages as $p): ?><option value="<?=$p['id']?>" <?=$fPackage==$p['id']?'selected':''?>><?=e($p['name'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Booking</label>
      <select name="booking_id" id="itBooking" class="form-select">
        <option value="">— None —</option>
        <?php foreach ($bookings as $b): ?><option value="<?=$b['id']?>"><?=e($b['label'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-8"><label class="form-label fw-semibold">Item Title <span class="text-danger">*</span></label>
      <input type="text" name="title" id="itItemTitle" class="form-control" required maxlength="255"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Day #</label>
      <input type="number" name="day_number" id="itDay" class="form-control" min="1" value="1"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Date</label>
      <input type="date" name="trip_date" id="itDate" class="form-control"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Location</label>
      <input type="text" name="location" id="itLocation" class="form-control" maxlength="200"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Activity Type</label>
      <input type="text" name="activity_type" id="itActivity" class="form-control" list="actList" maxlength="100">
      <datalist id="actList"><?php foreach ($activityTypes as $at): ?><option value="<?=e($at)?>"><?php endforeach; ?></datalist></div>
    <div class="col-md-2"><label class="form-label fw-semibold">Start</label>
      <input type="time" name="start_time" id="itStart" class="form-control"></div>
    <div class="col-md-2"><label class="form-label fw-semibold">End</label>
      <input type="time" name="end_time" id="itEnd" class="form-control"></div>
    <div class="col-12"><label class="form-label fw-semibold">Description</label>
      <textarea name="description" id="itDesc" class="form-control" rows="3"></textarea></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="itNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Item</button>
  </div></form>
</div></div></div>
<form method="POST" id="delItForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delItId"></form>

<?php
$fPackageJ = json_encode($fPackage ?: '');
$extraJs = <<<JS
<script>
var defPkg={$fPackageJ};
function openAdd(){
  document.getElementById('itTitle').innerHTML='<i class="fas fa-route me-2"></i>Add Itinerary Item';
  document.getElementById('itId').value='0';
  document.getElementById('itPkg').value=defPkg||'';
  document.getElementById('itBooking').value='';
  document.getElementById('itItemTitle').value='';
  document.getElementById('itDay').value='1';
  document.getElementById('itDate').value='';
  document.getElementById('itLocation').value='';
  document.getElementById('itActivity').value='';
  document.getElementById('itStart').value='';
  document.getElementById('itEnd').value='';
  document.getElementById('itDesc').value='';
  document.getElementById('itNotes').value='';
}
function openEdit(i){
  document.getElementById('itTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Itinerary Item';
  document.getElementById('itId').value=i.id;
  document.getElementById('itPkg').value=i.package_id||'';
  document.getElementById('itBooking').value=i.booking_id||'';
  document.getElementById('itItemTitle').value=i.title||'';
  document.getElementById('itDay').value=i.day_number||1;
  document.getElementById('itDate').value=i.trip_date?i.trip_date.substring(0,10):'';
  document.getElementById('itLocation').value=i.location||'';
  document.getElementById('itActivity').value=i.activity_type||'';
  document.getElementById('itStart').value=i.start_time?i.start_time.substring(0,5):'';
  document.getElementById('itEnd').value=i.end_time?i.end_time.substring(0,5):'';
  document.getElementById('itDesc').value=i.description||'';
  document.getElementById('itNotes').value=i.notes||'';
  new bootstrap.Modal(document.getElementById('itModal')).show();
}
function delIt(id,title){
  Swal.fire({title:'Delete Itinerary Item?',text:'"'+title+'" will be removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delItId').value=id;document.getElementById('delItForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
