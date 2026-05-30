<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
require_once __DIR__ . '/_nav.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── POST Handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_room') {
        $id       = (int)($_POST['id'] ?? 0);
        $roomNo   = sanitize($_POST['room_no'] ?? '');
        $roomType = in_array($_POST['room_type']??'',['dormitory','private','semi-private'])?$_POST['room_type']:'dormitory';
        $floor    = sanitize($_POST['floor'] ?? '');
        $block    = sanitize($_POST['block'] ?? '');
        $capacity = max(1,(int)($_POST['capacity']??4));
        $termFee  = (float)($_POST['term_fee']??0);
        $status   = in_array($_POST['status']??'',['available','full','maintenance'])?$_POST['status']:'available';
        if (!$roomNo) { setFlash('danger','Room number is required.'); redirect('hostel.php'); }
        if ($id > 0) {
            requireOrgOwnership('sch_hostel_rooms', $id, $orgId);
            $pdo->prepare("UPDATE sch_hostel_rooms SET room_no=?,room_type=?,floor=?,block=?,capacity=?,term_fee=?,status=? WHERE id=? AND org_id=?")->execute([$roomNo,$roomType,$floor,$block,$capacity,$termFee,$status,$id,$orgId]);
            setFlash('success','Room updated.');
        } else {
            $pdo->prepare("INSERT INTO sch_hostel_rooms (org_id,room_no,room_type,floor,block,capacity,occupied,term_fee,status) VALUES (?,?,?,?,?,?,0,?,?)")->execute([$orgId,$roomNo,$roomType,$floor,$block,$capacity,$termFee,$status]);
            setFlash('success',"Room '$roomNo' added.");
        }
        redirect('hostel.php');
    }

    if ($action === 'checkin') {
        $roomId    = (int)($_POST['room_id']??0);
        $studentId = (int)($_POST['student_id']??0);
        $checkIn   = $_POST['check_in']??date('Y-m-d');
        requireOrgOwnership('sch_hostel_rooms', $roomId, $orgId);
        // Check room capacity
        $room=$pdo->prepare("SELECT capacity,occupied FROM sch_hostel_rooms WHERE id=? AND org_id=?");$room->execute([$roomId,$orgId]);$r=$room->fetch();
        if (!$r||$r['occupied']>=$r['capacity']){setFlash('danger','Room is full or not found.');redirect('hostel.php');}
        // Check student not already assigned
        $ex=$pdo->prepare("SELECT id FROM sch_hostel_students WHERE student_id=? AND status='active'");$ex->execute([$studentId]);
        if($ex->fetch()){setFlash('danger','Student already has an active hostel assignment.');redirect('hostel.php');}
        $pdo->prepare("INSERT INTO sch_hostel_students (org_id,room_id,student_id,check_in,status) VALUES (?,?,?,?,'active')")->execute([$orgId,$roomId,$studentId,$checkIn]);
        $pdo->prepare("UPDATE sch_hostel_rooms SET occupied=occupied+1 WHERE id=? AND org_id=?")->execute([$roomId,$orgId]);
        // Auto-mark full
        $pdo->prepare("UPDATE sch_hostel_rooms SET status=CASE WHEN occupied>=capacity THEN 'full' ELSE 'available' END WHERE id=? AND org_id=?")->execute([$roomId,$orgId]);
        setFlash('success','Student checked in successfully.');redirect('hostel.php');
    }

    if ($action === 'checkout') {
        $id       = (int)($_POST['id']??0);
        $checkout = $_POST['check_out']??date('Y-m-d');
        $hs=$pdo->prepare("SELECT * FROM sch_hostel_students WHERE id=? AND org_id=?");$hs->execute([$id,$orgId]);$assignment=$hs->fetch();
        if(!$assignment){setFlash('danger','Assignment not found.');redirect('hostel.php');}
        $pdo->prepare("UPDATE sch_hostel_students SET check_out=?,status='vacated' WHERE id=? AND org_id=?")->execute([$checkout,$id,$orgId]);
        $pdo->prepare("UPDATE sch_hostel_rooms SET occupied=GREATEST(0,occupied-1) WHERE id=? AND org_id=?")->execute([$assignment['room_id'],$orgId]);
        $pdo->prepare("UPDATE sch_hostel_rooms SET status=CASE WHEN occupied<capacity THEN 'available' ELSE 'full' END WHERE id=? AND org_id=?")->execute([$assignment['room_id'],$orgId]);
        setFlash('success','Student checked out.');redirect('hostel.php');
    }

    if ($action === 'delete_room') {
        $id=(int)($_POST['id']??0);
        requireOrgOwnership('sch_hostel_rooms', $id, $orgId);
        $pdo->prepare("DELETE FROM sch_hostel_students WHERE room_id=? AND org_id=?")->execute([$id,$orgId]);
        $pdo->prepare("DELETE FROM sch_hostel_rooms WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Room deleted.');redirect('hostel.php');
    }
}

// AJAX fetch
if (isset($_GET['fetch_room'])) {
    $r=$pdo->prepare("SELECT * FROM sch_hostel_rooms WHERE id=? AND org_id=?");$r->execute([(int)$_GET['fetch_room'],$orgId]);
    header('Content-Type: application/json');echo json_encode($r->fetch()?:[]);exit;
}

// ── Load data ─────────────────────────────────────────────────────
$rooms=[];
try{$s=$pdo->prepare("SELECT * FROM sch_hostel_rooms WHERE org_id=? ORDER BY block,floor,room_no");$s->execute([$orgId]);$rooms=$s->fetchAll();}catch(Exception $e){}

$assignments=[];
try{
    $s=$pdo->prepare("SELECT hs.*,r.room_no,r.block,r.floor,CONCAT(st.first_name,' ',st.last_name) AS student_name,st.admission_no,c.name AS class_name FROM sch_hostel_students hs JOIN sch_hostel_rooms r ON hs.room_id=r.id JOIN sch_students st ON hs.student_id=st.id LEFT JOIN sch_classes c ON st.class_id=c.id WHERE hs.org_id=? AND hs.status='active' ORDER BY r.room_no,st.first_name");
    $s->execute([$orgId]);$assignments=$s->fetchAll();
}catch(Exception $e){}

$studentsList=[];
try{$s=$pdo->prepare("SELECT s.id,s.admission_no,s.first_name,s.last_name FROM sch_students s LEFT JOIN sch_hostel_students hs ON hs.student_id=s.id AND hs.status='active' WHERE s.org_id=? AND hs.id IS NULL AND s.status='active' ORDER BY s.first_name");$s->execute([$orgId]);$studentsList=$s->fetchAll();}catch(Exception $e){}

$totCap=array_sum(array_column($rooms,'capacity'));
$totOcc=array_sum(array_column($rooms,'occupied'));
$totRooms=count($rooms);
$vacancyRate = $totCap > 0 ? round(($totCap-$totOcc)/$totCap*100) : 0;

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bed me-2" style="color:<?=$moduleColor?>"></i>Hostel & Dormitory Management</h4>
    <p class="text-muted mb-0">Manage rooms, student check-ins, and occupancy at a glance</p>
  </div>
  <div class="d-flex gap-2">
    <button class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#checkinModal"><i class="fas fa-sign-in-alt me-1"></i>Check-In Student</button>
    <button class="btn text-white" style="background:<?=$moduleColor?>" data-bs-toggle="modal" data-bs-target="#roomModal" onclick="openAddRoom()"><i class="fas fa-plus me-2"></i>Add Room</button>
  </div>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-door-open"></i></div><div class="stat-body"><div class="stat-value"><?=$totRooms?></div><div class="stat-label">Total Rooms</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-user-check"></i></div><div class="stat-body"><div class="stat-value"><?=$totOcc?></div><div class="stat-label">Occupied Beds</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-bed"></i></div><div class="stat-body"><div class="stat-value"><?=$totCap-$totOcc?></div><div class="stat-label">Vacant Beds</div></div></div></div>
  <div class="col-6 col-sm-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-percent"></i></div><div class="stat-body"><div class="stat-value"><?=$vacancyRate?>%</div><div class="stat-label">Vacancy Rate</div></div></div></div>
</div>

<!-- Room Grid -->
<div class="card mb-4">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-th me-2 text-success"></i>Room Inventory</h6></div>
  <div class="card-body">
  <?php if(empty($rooms)): ?>
  <div class="text-center text-muted py-5"><i class="fas fa-bed fa-3x mb-2 opacity-25 d-block"></i>No rooms configured. Add rooms to start managing hostel.</div>
  <?php else: ?>
  <div class="row g-3">
  <?php foreach($rooms as $rm):
    $occPct = $rm['capacity']>0 ? ($rm['occupied']/$rm['capacity'])*100 : 0;
    $cardColor=['available'=>'border-success','full'=>'border-danger','maintenance'=>'border-warning'][$rm['status']]??'border-secondary';
    $badgeColor=['available'=>'success','full'=>'danger','maintenance'=>'warning'][$rm['status']]??'secondary';
  ?>
  <div class="col-md-3 col-sm-4 col-6">
    <div class="card border-2 <?=$cardColor?> h-100">
      <div class="card-body p-3">
        <div class="d-flex align-items-center justify-content-between mb-1">
          <div class="fw-bold fs-5">Room <?=e($rm['room_no'])?></div>
          <span class="badge bg-<?=$badgeColor?>"><?=ucfirst($rm['status'])?></span>
        </div>
        <div class="text-muted small mb-2">
          <?=e($rm['block']?'Block '.$rm['block']:'')?>
          <?=e($rm['floor']?' · Floor '.$rm['floor']:'')?>
          · <?=ucfirst(str_replace('-',' ',$rm['room_type']))?>
        </div>
        <div class="progress mb-1" style="height:6px">
          <div class="progress-bar bg-<?=$occPct>=100?'danger':($occPct>=70?'warning':'success')?>" style="width:<?=min(100,$occPct)?>%"></div>
        </div>
        <div class="small text-muted mb-2"><?=$rm['occupied']?>/<?=$rm['capacity']?> beds occupied</div>
        <div class="small text-success fw-semibold"><?=CURRENCY_SYMBOL?><?=number_format($rm['term_fee'],2)?>/term</div>
        <div class="d-flex gap-1 mt-2">
          <button class="btn btn-sm btn-outline-primary flex-fill" onclick="editRoom(<?=$rm['id']?>)" data-bs-toggle="modal" data-bs-target="#roomModal" title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="delRoom(<?=$rm['id']?>,'<?=e($rm['room_no'])?>')" title="Delete"><i class="fas fa-trash"></i></button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
  </div>
</div>

<!-- Current Assignments -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-user-check me-2 text-success"></i>Current Residents</h6>
    <span class="badge bg-secondary"><?=count($assignments)?> students</span>
  </div>
  <div class="card-body p-0">
  <div class="table-responsive">
  <table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Student</th><th>Class</th><th>Room</th><th>Block/Floor</th><th>Check-In Date</th><th class="text-center">Action</th></tr></thead>
    <tbody>
    <?php if(empty($assignments)): ?>
    <tr><td colspan="6" class="text-center text-muted py-4">No students currently checked in.</td></tr>
    <?php else: foreach($assignments as $a): ?>
    <tr>
      <td><div class="fw-semibold"><?=e($a['student_name'])?></div><small class="text-muted"><?=e($a['admission_no'])?></small></td>
      <td><?=e($a['class_name']??'—')?></td>
      <td class="fw-semibold">Room <?=e($a['room_no'])?></td>
      <td class="small text-muted"><?=e($a['block']?'Block '.$a['block']:'—')?>, Floor <?=e($a['floor']??'—')?></td>
      <td><?=formatDate($a['check_in'])?></td>
      <td class="text-center">
        <form method="POST" class="d-inline" onsubmit="return confirm('Check out this student?')">
          <?=csrfField()?><input type="hidden" name="action" value="checkout"><input type="hidden" name="id" value="<?=$a['id']?>"><input type="hidden" name="check_out" value="<?=date('Y-m-d')?>">
          <button type="submit" class="btn btn-sm btn-outline-warning"><i class="fas fa-sign-out-alt me-1"></i>Check Out</button>
        </form>
      </td>
    </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>
  </div>
</div>

<!-- Room Modal -->
<div class="modal fade" id="roomModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save_room"><input type="hidden" name="id" id="roomId" value="0">
  <div class="modal-header text-white" style="background:<?=$moduleColor?>"><h5 class="modal-title" id="roomTitle"><i class="fas fa-bed me-2"></i>Add Room</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-6"><label class="form-label fw-semibold">Room Number <span class="text-danger">*</span></label><input type="text" name="room_no" id="roomNo" class="form-control" required placeholder="e.g. A101, D3"></div>
    <div class="col-6"><label class="form-label fw-semibold">Room Type</label>
      <select name="room_type" id="roomType" class="form-select"><option value="dormitory">Dormitory</option><option value="semi-private">Semi-Private</option><option value="private">Private</option></select>
    </div>
    <div class="col-4"><label class="form-label fw-semibold">Block / Wing</label><input type="text" name="block" id="roomBlock" class="form-control" placeholder="e.g. A, B, East"></div>
    <div class="col-4"><label class="form-label fw-semibold">Floor</label><input type="text" name="floor" id="roomFloor" class="form-control" placeholder="e.g. G, 1, 2"></div>
    <div class="col-4"><label class="form-label fw-semibold">Capacity</label><input type="number" name="capacity" id="roomCap" class="form-control" min="1" max="20" value="4"></div>
    <div class="col-6"><label class="form-label fw-semibold">Term Fee (<?=CURRENCY_SYMBOL?>)</label><input type="number" name="term_fee" id="roomFee" class="form-control" min="0" step="0.01" value="0"></div>
    <div class="col-6"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="roomStatus" class="form-select"><option value="available">Available</option><option value="full">Full</option><option value="maintenance">Maintenance</option></select>
    </div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-save me-1"></i>Save Room</button></div>
  </form>
</div></div></div>

<!-- Check-In Modal -->
<div class="modal fade" id="checkinModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="checkin">
  <div class="modal-header text-white" style="background:<?=$moduleColor?>"><h5 class="modal-title"><i class="fas fa-sign-in-alt me-2"></i>Check-In Student</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-12"><label class="form-label fw-semibold">Select Room <span class="text-danger">*</span></label>
      <select name="room_id" class="form-select" required>
        <option value="">— select available room —</option>
        <?php foreach($rooms as $rm): if($rm['status']==='available'): ?>
        <option value="<?=$rm['id']?>">Room <?=e($rm['room_no'])?> <?=$rm['block'] ? e("(Block " . $rm['block'] . ")") : ''?>  — <?=$rm['occupied']?>/<?=$rm['capacity']?> beds</option>
        <?php endif; endforeach; ?>
      </select>
    </div>
    <div class="col-12"><label class="form-label fw-semibold">Select Student <span class="text-danger">*</span></label>
      <select name="student_id" class="form-select select2-enable" required>
        <option value="">— select student —</option>
        <?php foreach($studentsList as $s): ?><option value="<?=$s['id']?>"><?=e($s['first_name'].' '.$s['last_name'])?> (<?=e($s['admission_no'])?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12"><label class="form-label fw-semibold">Check-In Date</label><input type="date" name="check_in" class="form-control" value="<?=date('Y-m-d')?>"></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-sign-in-alt me-1"></i>Check In</button></div>
  </form>
</div></div></div>

<form method="POST" id="delRoomForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete_room"><input type="hidden" name="id" id="delRoomId"></form>
<?php ob_start(); ?>
<script>
function openAddRoom(){document.getElementById('roomTitle').innerHTML='<i class="fas fa-bed me-2"></i>Add Room';['roomId','roomNo','roomBlock','roomFloor'].forEach(f=>document.getElementById(f).value='');document.getElementById('roomId').value='0';document.getElementById('roomCap').value='4';document.getElementById('roomFee').value='0';document.getElementById('roomType').value='dormitory';document.getElementById('roomStatus').value='available';}
function editRoom(id){fetch('hostel.php?fetch_room='+id).then(r=>r.json()).then(d=>{document.getElementById('roomTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Room';document.getElementById('roomId').value=d.id;document.getElementById('roomNo').value=d.room_no;document.getElementById('roomType').value=d.room_type;document.getElementById('roomBlock').value=d.block||'';document.getElementById('roomFloor').value=d.floor||'';document.getElementById('roomCap').value=d.capacity;document.getElementById('roomFee').value=d.term_fee;document.getElementById('roomStatus').value=d.status;});}
function delRoom(id,no){if(confirm('Delete Room '+no+'? All assignments for this room will also be deleted.')){document.getElementById('delRoomId').value=id;document.getElementById('delRoomForm').submit();}}
</script>
<?php $extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php'; ?>
