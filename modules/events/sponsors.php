<?php
// ── EVENTS: Sponsor Management ──────────────────────────────────
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
    ['url' => 'vendors.php',   'icon' => 'fas fa-store',          'label' => 'Vendors'],
    ['url' => 'sponsors.php',  'icon' => 'fas fa-handshake',      'label' => 'Sponsors'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-tasks',          'label' => 'Tasks'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); $user = currentUser(); $orgId = (int)$user['org_id']; $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $eventId      = (int)($_POST['event_id'] ?? 0) ?: null;
        $company      = sanitize($_POST['company_name'] ?? '');
        $tier         = in_array($_POST['tier'] ?? '', ['platinum','gold','silver','bronze','media','in_kind']) ? $_POST['tier'] : 'silver';
        $contact      = sanitize($_POST['contact_person'] ?? '');
        $phone        = sanitize($_POST['phone'] ?? '');
        $email        = sanitize($_POST['email'] ?? '');
        $pledgeAmount = (float)($_POST['pledge_amount'] ?? 0);
        $received     = (float)($_POST['amount_received'] ?? 0);
        $benefits     = sanitize($_POST['benefits'] ?? '');
        $status       = in_array($_POST['status'] ?? '', ['prospect','confirmed','paid','completed','declined']) ? $_POST['status'] : 'prospect';
        $notes        = sanitize($_POST['notes'] ?? '');
        if (!$company) { setFlash('error', 'Company name is required.'); redirect('sponsors.php'); }
        if ($id > 0) {
            $pdo->prepare("UPDATE event_sponsors SET event_id=?,company_name=?,tier=?,contact_person=?,phone=?,email=?,pledge_amount=?,amount_received=?,benefits=?,status=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$eventId,$company,$tier,$contact,$phone,$email,$pledgeAmount,$received,$benefits,$status,$notes,$id,$orgId]);
            setFlash('success', 'Sponsor updated.');
        } else {
            $pdo->prepare("INSERT INTO event_sponsors(org_id,event_id,company_name,tier,contact_person,phone,email,pledge_amount,amount_received,benefits,status,notes)VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$eventId,$company,$tier,$contact,$phone,$email,$pledgeAmount,$received,$benefits,$status,$notes]);
            setFlash('success', "Sponsor $company added.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'events', "Sponsor: $company");
        redirect('sponsors.php');
    }
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM event_sponsors WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Sponsor removed.'); redirect('sponsors.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$fEvent = (int)($_GET['event_id'] ?? 0);
$fTier  = $_GET['tier'] ?? '';

$where = 's.org_id=?'; $params = [$orgId];
if ($fEvent) { $where .= ' AND s.event_id=?'; $params[] = $fEvent; }
if ($fTier)  { $where .= ' AND s.tier=?'; $params[] = $fTier; }

$sponsors = [];
try {
    $s = $pdo->prepare("SELECT s.*,e.title AS event_title FROM event_sponsors s LEFT JOIN events e ON s.event_id=e.id WHERE $where ORDER BY FIELD(s.tier,'platinum','gold','silver','bronze','media','in_kind'),s.company_name");
    $s->execute($params); $sponsors = $s->fetchAll();
} catch (Exception $e) {}

$events = [];
try { $s = $pdo->prepare("SELECT id,title FROM events WHERE org_id=? ORDER BY start_date DESC LIMIT 50"); $s->execute([$orgId]); $events = $s->fetchAll(); } catch (Exception $e) {}

$totalSponsors = 0; $totalPledged = 0; $totalReceived = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*),COALESCE(SUM(pledge_amount),0),COALESCE(SUM(amount_received),0) FROM event_sponsors WHERE org_id=?");
    $s->execute([$orgId]); $r = $s->fetch(PDO::FETCH_NUM); $totalSponsors=(int)$r[0]; $totalPledged=(float)$r[1]; $totalReceived=(float)$r[2];
} catch (Exception $e) {}

$tierColors  = ['platinum'=>'dark','gold'=>'warning','silver'=>'secondary','bronze'=>'danger','media'=>'info','in_kind'=>'success'];
$tierLabels  = ['platinum'=>'Platinum','gold'=>'Gold','silver'=>'Silver','bronze'=>'Bronze','media'=>'Media Partner','in_kind'=>'In-Kind'];
$statusColors = ['prospect'=>'secondary','confirmed'=>'primary','paid'=>'success','completed'=>'info','declined'=>'danger'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-handshake me-2" style="color:<?=$moduleColor?>"></i>Sponsors</h4>
    <p class="text-muted mb-0">Manage event sponsors, pledges and partnership tiers</p>
  </div>
  <button class="btn" style="background:<?=$moduleColor?>;color:#fff" data-bs-toggle="modal" data-bs-target="#spModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>Add Sponsor
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon" style="background:#f3e5f5;color:<?=$moduleColor?>"><i class="fas fa-handshake"></i></div><div class="stat-body"><div class="stat-value"><?=$totalSponsors?></div><div class="stat-label">Total Sponsors</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-file-signature"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalPledged)?></div><div class="stat-label">Total Pledged</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-coins"></i></div><div class="stat-body"><div class="stat-value"><?=formatCurrency($totalReceived)?></div><div class="stat-label">Received</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-percent"></i></div><div class="stat-body"><div class="stat-value"><?=$totalPledged>0?round($totalReceived/$totalPledged*100).'%':'0%'?></div><div class="stat-label">Collection Rate</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-5"><label class="form-label small fw-semibold mb-1">Event</label>
      <select name="event_id" class="form-select form-select-sm">
        <option value="">All Events</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>" <?=$fEvent==$ev['id']?'selected':''?>><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Tier</label>
      <select name="tier" class="form-select form-select-sm">
        <option value="">All Tiers</option>
        <?php foreach ($tierLabels as $k=>$v): ?><option value="<?=$k?>" <?=$fTier===$k?'selected':''?>><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="sponsors.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-handshake me-2" style="color:<?=$moduleColor?>"></i>Sponsor List</h6>
    <span class="badge bg-secondary"><?=count($sponsors)?> sponsors</span>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="table table-hover data-table mb-0">
    <thead class="table-light"><tr><th>Company</th><th>Event</th><th>Tier</th><th>Contact</th><th>Pledged</th><th>Received</th><th>Status</th><th class="text-center">Actions</th></tr></thead>
    <tbody>
    <?php if (empty($sponsors)): ?>
      <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-handshake fa-2x mb-2 d-block"></i>No sponsors added yet.</td></tr>
    <?php else: foreach ($sponsors as $sp): $outstanding = $sp['pledge_amount'] - $sp['amount_received']; ?>
      <tr>
        <td class="fw-semibold"><?=e($sp['company_name'])?></td>
        <td class="small"><?=e($sp['event_title'] ?? 'General')?></td>
        <td><span class="badge bg-<?=$tierColors[$sp['tier']]??'secondary'?>"><?=$tierLabels[$sp['tier']]??e($sp['tier'])?></span></td>
        <td class="small"><?=e($sp['contact_person']??'—')?><?=$sp['phone']?'<br><span class="text-muted">'.e($sp['phone']).'</span>':''?></td>
        <td class="fw-semibold"><?=formatCurrency($sp['pledge_amount'])?></td>
        <td><?=formatCurrency($sp['amount_received'])?><?=$outstanding>0?'<br><small class="text-danger">Pending: '.formatCurrency($outstanding).'</small>':''?></td>
        <td><span class="badge bg-<?=$statusColors[$sp['status']]??'secondary'?>"><?=ucfirst($sp['status'])?></span></td>
        <td class="text-center" style="white-space:nowrap">
          <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?=htmlspecialchars(json_encode($sp),ENT_QUOTES)?>)' title="Edit"><i class="fas fa-edit"></i></button>
          <button class="btn btn-sm btn-outline-danger ms-1" onclick="delSponsor(<?=$sp['id']?>,<?=json_encode($sp['company_name'])?>)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div></div>
</div>

<!-- Modal -->
<div class="modal fade" id="spModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-xl"><div class="modal-content">
  <form method="POST"><?=csrfField()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="spId" value="0">
  <div class="modal-header" style="background:<?=$moduleColor?>;color:#fff">
    <h5 class="modal-title" id="spTitle"><i class="fas fa-handshake me-2"></i>Add Sponsor</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body"><div class="row g-3">
    <div class="col-md-8"><label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
      <input type="text" name="company_name" id="spCompany" class="form-control" required maxlength="200"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Sponsorship Tier</label>
      <select name="tier" id="spTier" class="form-select">
        <?php foreach ($tierLabels as $k=>$v): ?><option value="<?=$k?>"><?=$v?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Event</label>
      <select name="event_id" id="spEvent" class="form-select">
        <option value="">— General —</option>
        <?php foreach ($events as $ev): ?><option value="<?=$ev['id']?>"><?=e($ev['title'])?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Status</label>
      <select name="status" id="spStatus" class="form-select">
        <?php foreach (array_keys($statusColors) as $s): ?><option value="<?=$s?>"><?=ucfirst($s)?></option><?php endforeach; ?>
      </select></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Contact Person</label>
      <input type="text" name="contact_person" id="spContact" class="form-control" maxlength="150"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Phone</label>
      <input type="text" name="phone" id="spPhone" class="form-control" maxlength="50"></div>
    <div class="col-md-4"><label class="form-label fw-semibold">Email</label>
      <input type="email" name="email" id="spEmail" class="form-control"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Pledge Amount</label>
      <input type="number" name="pledge_amount" id="spPledge" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-md-6"><label class="form-label fw-semibold">Amount Received</label>
      <input type="number" name="amount_received" id="spReceived" class="form-control" step="0.01" min="0" value="0"></div>
    <div class="col-12"><label class="form-label fw-semibold">Benefits / Deliverables</label>
      <textarea name="benefits" id="spBenefits" class="form-control" rows="2" placeholder="Logo placement, MC mention, booth space…"></textarea></div>
    <div class="col-12"><label class="form-label fw-semibold">Notes</label>
      <textarea name="notes" id="spNotes" class="form-control" rows="2"></textarea></div>
  </div></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?=$moduleColor?>;color:#fff"><i class="fas fa-save me-1"></i>Save Sponsor</button>
  </div></form>
</div></div></div>
<form method="POST" id="delSpForm" style="display:none"><?=csrfField()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delSpId"></form>

<?php $extraJs = <<<'JS'
<script>
function openAdd(){
  document.getElementById('spTitle').innerHTML='<i class="fas fa-handshake me-2"></i>Add Sponsor';
  document.getElementById('spId').value='0';
  ['spCompany','spContact','spPhone','spEmail','spBenefits','spNotes'].forEach(x=>document.getElementById(x).value='');
  document.getElementById('spTier').value='silver';
  document.getElementById('spEvent').value='';
  document.getElementById('spStatus').value='prospect';
  document.getElementById('spPledge').value='0';
  document.getElementById('spReceived').value='0';
}
function openEdit(s){
  document.getElementById('spTitle').innerHTML='<i class="fas fa-edit me-2"></i>Edit Sponsor';
  document.getElementById('spId').value=s.id;
  document.getElementById('spCompany').value=s.company_name||'';
  document.getElementById('spTier').value=s.tier||'silver';
  document.getElementById('spEvent').value=s.event_id||'';
  document.getElementById('spStatus').value=s.status||'prospect';
  document.getElementById('spContact').value=s.contact_person||'';
  document.getElementById('spPhone').value=s.phone||'';
  document.getElementById('spEmail').value=s.email||'';
  document.getElementById('spPledge').value=s.pledge_amount||'0';
  document.getElementById('spReceived').value=s.amount_received||'0';
  document.getElementById('spBenefits').value=s.benefits||'';
  document.getElementById('spNotes').value=s.notes||'';
  new bootstrap.Modal(document.getElementById('spModal')).show();
}
function delSponsor(id,name){
  Swal.fire({title:'Remove Sponsor?',text:name+' will be deleted.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r=>{if(r.isConfirmed){document.getElementById('delSpId').value=id;document.getElementById('delSpForm').submit();}});
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
