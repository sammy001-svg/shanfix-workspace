<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();
require_once __DIR__ . '/_nav.php';

$user  = currentUser();
$orgId = (int)$user['org_id'];

// Load classes and students for filters
$classesList = [];
try { $s=$pdo->prepare("SELECT id,name,curriculum,level FROM sch_classes WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $classesList=$s->fetchAll(); } catch(Exception $e){}

$fClass  = (int)($_GET['class_id'] ?? 0);
$fSearch = sanitize($_GET['q'] ?? '');
$where   = 's.org_id=? AND s.status=\'active\''; $params = [$orgId];
if ($fClass)  { $where .= ' AND s.class_id=?'; $params[]=$fClass; }
if ($fSearch) { $where .= ' AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.admission_no LIKE ?)'; $q="%$fSearch%"; array_push($params,$q,$q,$q); }

$students = [];
try {
    $stmt=$pdo->prepare("SELECT s.*,c.name AS class_name,c.curriculum AS class_curriculum FROM sch_students s LEFT JOIN sch_classes c ON s.class_id=c.id WHERE $where ORDER BY s.first_name,s.last_name");
    $stmt->execute($params); $students=$stmt->fetchAll();
} catch(Exception $e){}

// Org info
$orgInfo=[];
try { $o=$pdo->prepare("SELECT name,logo,address FROM organizations WHERE id=? LIMIT 1"); $o->execute([$orgId]); $orgInfo=$o->fetch()?:[]; } catch(Exception $e){}

require_once __DIR__ . '/../../includes/header-module.php';

$isPrint = isset($_GET['print']);
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-id-card me-2" style="color:<?=$moduleColor?>"></i>Student ID Cards</h4>
    <p class="text-muted mb-0">Generate and print official student identification cards</p>
  </div>
  <?php if (!empty($students)): ?>
  <a href="id-cards.php?<?=http_build_query(array_merge($_GET,['print'=>1]))?>" target="_blank" class="btn text-white" style="background:<?=$moduleColor?>"><i class="fas fa-print me-2"></i>Print Selected</a>
  <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4"><div class="card-body py-2 d-print-none">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Search Student</label><input type="text" name="q" class="form-control form-control-sm" value="<?=e($fSearch)?>" placeholder="Name or admission no…"></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Class</label>
      <select name="class_id" class="form-select form-select-sm">
        <option value="">All Classes</option>
        <?php foreach($classesList as $c):?><option value="<?=$c['id']?>" <?=$fClass==$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?>
      </select>
    </div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="id-cards.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
      <?php if(!empty($students)):?><a href="id-cards.php?<?=http_build_query(array_merge($_GET,['print'=>1]))?>" target="_blank" class="btn btn-sm btn-primary ms-2"><i class="fas fa-print me-1"></i>Print All (<?=count($students)?>)</a><?php endif;?>
    </div>
  </form>
</div></div>

<!-- Print Styles -->
<style>
@media print {
  .d-print-none { display:none!important; }
  body { background:#fff; }
  .id-card-grid { display:flex; flex-wrap:wrap; gap:8px; padding:0; }
  .id-card { width:85.6mm; height:54mm; page-break-inside:avoid; }
}
.id-card {
  width:340px; height:214px;
  border-radius:10px;
  overflow:hidden;
  font-family:'Inter',sans-serif;
  position:relative;
  border:2px solid #1A8A4E;
  background:#fff;
  box-shadow:0 4px 20px rgba(0,0,0,.12);
  display:flex;
  flex-direction:column;
}
.id-card-header {
  background:linear-gradient(135deg,#1A8A4E,#0d5c32);
  color:#fff;
  padding:8px 12px;
  display:flex;
  align-items:center;
  gap:8px;
}
.id-card-header .school-name {
  font-size:11px;
  font-weight:700;
  line-height:1.2;
  flex:1;
}
.id-card-body {
  display:flex;
  flex:1;
  padding:10px;
  gap:10px;
}
.id-card-photo {
  width:60px;
  height:75px;
  object-fit:cover;
  border-radius:6px;
  border:2px solid #1A8A4E;
  flex-shrink:0;
}
.id-card-photo-placeholder {
  width:60px;
  height:75px;
  border-radius:6px;
  background:linear-gradient(135deg,#1A8A4E,#2ecc71);
  display:flex;
  align-items:center;
  justify-content:center;
  color:#fff;
  font-size:1.6rem;
  font-weight:700;
  border:2px solid #1A8A4E;
  flex-shrink:0;
}
.id-card-info {
  flex:1;
  overflow:hidden;
}
.id-card-name {
  font-size:13px;
  font-weight:700;
  color:#1a1a1a;
  margin-bottom:3px;
  line-height:1.2;
}
.id-card-info .field {
  font-size:10px;
  color:#555;
  margin-bottom:2px;
}
.id-card-info .field span {
  color:#1a1a1a;
  font-weight:600;
}
.id-card-footer {
  background:#f8f9fa;
  border-top:2px solid #1A8A4E;
  padding:5px 12px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-size:9.5px;
  color:#555;
}
.qr-placeholder {
  width:35px;
  height:35px;
  background:#1a1a1a;
  border-radius:3px;
  display:flex;
  align-items:center;
  justify-content:center;
}
.qr-placeholder svg { width:28px; height:28px; }
.blood-badge {
  background:#e74c3c;
  color:#fff;
  border-radius:4px;
  padding:1px 5px;
  font-size:10px;
  font-weight:700;
}
</style>

<?php if (empty($students)): ?>
<div class="text-center text-muted py-5">
  <i class="fas fa-id-card fa-3x mb-3 opacity-25 d-block"></i>
  <p>No students found. Use the filter above to search or select a class.</p>
</div>
<?php else: ?>
<div class="id-card-grid d-flex flex-wrap gap-3">
<?php
$schoolName = $orgInfo['name'] ?? APP_NAME;
foreach ($students as $s):
  $fullName = e($s['first_name'] . ' ' . $s['last_name']);
  $initials  = strtoupper(substr($s['first_name'],0,1) . substr($s['last_name'],0,1));
  $photoUrl  = $s['photo'] ? APP_URL . '/' . $s['photo'] : null;
  $curriculum= $s['curriculum'] ?? $s['class_curriculum'] ?? 'IB';
  $blood     = $s['blood_group'] ?? '';
?>
<div class="id-card">
  <!-- Header -->
  <div class="id-card-header">
    <div class="me-2"><i class="fas fa-school fa-lg"></i></div>
    <div class="school-name">
      <?=e($schoolName)?><br>
      <span style="font-size:9px;font-weight:400;opacity:.85">International School</span>
    </div>
    <div style="font-size:9px;text-align:right;opacity:.85">
      STUDENT ID<br>
      <strong style="font-size:11px"><?=e($s['admission_no']??'N/A')?></strong>
    </div>
  </div>
  <!-- Body -->
  <div class="id-card-body">
    <?php if ($photoUrl): ?>
    <img src="<?=$photoUrl?>" class="id-card-photo" alt="<?=$fullName?>">
    <?php else: ?>
    <div class="id-card-photo-placeholder"><?=$initials?></div>
    <?php endif; ?>
    <div class="id-card-info">
      <div class="id-card-name"><?=$fullName?></div>
      <div class="field">Class: <span><?=e($s['class_name']??'N/A')?></span></div>
      <div class="field">Gender: <span><?=ucfirst($s['gender']??'—')?></span></div>
      <div class="field">Curriculum: <span><?=e($curriculum)?></span></div>
      <div class="field">DOB: <span><?=formatDate($s['dob']??'')?></span></div>
      <?php if($s['nationality']??false):?><div class="field">Nationality: <span><?=e($s['nationality'])?></span></div><?php endif;?>
      <?php if($blood):?><div class="mt-1"><span class="blood-badge"><i class="fas fa-tint me-1"></i><?=e($blood)?></span></div><?php endif;?>
    </div>
  </div>
  <!-- Footer -->
  <div class="id-card-footer">
    <div>
      <div><?=e($s['parent_phone']??'')?></div>
      <div style="color:#1A8A4E;font-weight:600">If found, please return to school</div>
    </div>
    <div class="qr-placeholder">
      <!-- QR placeholder SVG -->
      <svg viewBox="0 0 29 29" fill="white" xmlns="http://www.w3.org/2000/svg">
        <rect x="0" y="0" width="12" height="12"/><rect x="17" y="0" width="12" height="12"/>
        <rect x="0" y="17" width="12" height="12"/><rect x="3" y="3" width="6" height="6" fill="#1a1a1a"/>
        <rect x="20" y="3" width="6" height="6" fill="#1a1a1a"/><rect x="3" y="20" width="6" height="6" fill="#1a1a1a"/>
        <rect x="17" y="17" width="3" height="3"/><rect x="23" y="17" width="6" height="3"/>
        <rect x="17" y="23" width="6" height="6"/><rect x="26" y="20" width="3" height="3"/>
      </svg>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($isPrint): ?>
<script>window.onload = function(){ setTimeout(()=>window.print(), 500); };</script>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php'; ?>
