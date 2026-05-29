<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',       'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',         'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',             'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',               'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',            'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',              'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',         'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',             'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',                'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',         'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',             'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',       'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',              'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text',   'label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',            'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'log') {
        $contactId   = (int)($_POST['contact_id'] ?? 0) ?: null;
        $direction   = in_array($_POST['direction'] ?? '', ['inbound','outbound']) ? $_POST['direction'] : 'outbound';
        $subject     = sanitize($_POST['subject'] ?? '');
        $bodyPreview = mb_substr(sanitize($_POST['body_preview'] ?? ''), 0, 500);
        $channel     = in_array($_POST['channel'] ?? '', ['email','gmail','outlook','smtp']) ? $_POST['channel'] : 'email';
        $status      = in_array($_POST['status'] ?? '', ['sent','delivered','opened','bounced','failed']) ? $_POST['status'] : 'sent';
        $sentAt      = $_POST['sent_at'] ?? date('Y-m-d H:i:s');

        if (empty($subject)) { setFlash('danger', 'Subject is required.'); redirect('email-log.php'); }

        $pdo->prepare("INSERT INTO crm_email_log(org_id,contact_id,direction,subject,body_preview,channel,status,sent_at) VALUES(?,?,?,?,?,?,?,?)")
            ->execute([$orgId,$contactId,$direction,$subject,$bodyPreview,$channel,$status,$sentAt]);
        setFlash('success', 'Email activity logged.');
        logActivity('create', 'crm', "Logged email: $subject");
        redirect('email-log.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_email_log WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Email log entry deleted.');
        redirect('email-log.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fDirection = $_GET['direction'] ?? '';
$fStatus    = $_GET['status'] ?? '';
$where  = 'el.org_id=?';
$params = [$orgId];
if ($fDirection) { $where .= ' AND el.direction=?'; $params[] = $fDirection; }
if ($fStatus)    { $where .= ' AND el.status=?';    $params[] = $fStatus; }

$emails = [];
try {
    $stmt = $pdo->prepare("SELECT el.*,CONCAT(c.first_name,' ',c.last_name) AS contact_name
        FROM crm_email_log el
        LEFT JOIN crm_contacts c ON el.contact_id=c.id
        WHERE $where ORDER BY el.sent_at DESC");
    $stmt->execute($params);
    $emails = $stmt->fetchAll();
} catch (Exception $e) {}

$contacts = [];
try {
    $stmt = $pdo->prepare("SELECT id,first_name,last_name,company FROM crm_contacts WHERE org_id=? ORDER BY first_name");
    $stmt->execute([$orgId]);
    $contacts = $stmt->fetchAll();
} catch (Exception $e) {}

$totalEmails  = countRows('crm_email_log', 'org_id=?', [$orgId]);
$sentToday    = 0;
$bouncedCount = countRows('crm_email_log', 'org_id=? AND status=?', [$orgId,'bounced']);
$openedCount  = countRows('crm_email_log', 'org_id=? AND status=?', [$orgId,'opened']);
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_email_log WHERE org_id=? AND DATE(sent_at)=CURDATE()");
    $stmt->execute([$orgId]);
    $sentToday = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-envelope-open-text me-2" style="color:<?= $moduleColor ?>"></i>Email Log</h4>
    <p class="text-muted mb-0">Immutable record of all inbound and outbound email activity</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#elModal">
    <i class="fas fa-plus me-2"></i>Log Email
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-envelope"></i></div><div class="stat-body"><div class="stat-value"><?= $totalEmails ?></div><div class="stat-label">Total Emails</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-paper-plane"></i></div><div class="stat-body"><div class="stat-value"><?= $sentToday ?></div><div class="stat-label">Logged Today</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-envelope-open"></i></div><div class="stat-body"><div class="stat-value"><?= $openedCount ?></div><div class="stat-label">Opened</div></div></div></div>
  <div class="col-sm-6 col-xl-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-ban"></i></div><div class="stat-body"><div class="stat-value"><?= $bouncedCount ?></div><div class="stat-label">Bounced</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Direction</label><select name="direction" class="form-select form-select-sm"><option value="">All Directions</option><option value="inbound" <?= $fDirection==='inbound'?'selected':'' ?>>Inbound</option><option value="outbound" <?= $fDirection==='outbound'?'selected':'' ?>>Outbound</option></select></div>
    <div class="col-sm-4"><label class="form-label small fw-semibold mb-1">Status</label><select name="status" class="form-select form-select-sm"><option value="">All Statuses</option><?php foreach (['sent','delivered','opened','bounced','failed'] as $s): ?><option value="<?= $s ?>" <?= $fStatus===$s?'selected':'' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
    <div class="col-auto"><button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button><a href="email-log.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a></div>
  </form>
</div></div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-envelope-open-text me-2" style="color:<?= $moduleColor ?>"></i>Email Activity Log</h6>
    <span class="badge bg-secondary"><?= count($emails) ?> entries</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0" id="elTable">
        <thead class="table-light">
          <tr><th>Sent At</th><th>Direction</th><th>Contact</th><th>Subject</th><th>Preview</th><th>Channel</th><th>Status</th><th class="text-center no-print">Delete</th></tr>
        </thead>
        <tbody>
          <?php if (empty($emails)): ?>
          <tr><td colspan="8" class="text-center text-muted py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>No email records found.</td></tr>
          <?php else: foreach ($emails as $em):
            $dirClass = $em['direction'] === 'inbound' ? 'info' : 'primary';
            $stColors = ['sent'=>'secondary','delivered'=>'success','opened'=>'info','bounced'=>'warning','failed'=>'danger'];
            $stColor  = $stColors[$em['status']] ?? 'secondary';
          ?>
          <tr>
            <td><?= formatDate($em['sent_at'] ?? '') ?></td>
            <td><span class="badge bg-<?= $dirClass ?>"><?= ucfirst($em['direction']) ?></span></td>
            <td><?= e($em['contact_name'] ?? '—') ?></td>
            <td class="fw-semibold"><?= e($em['subject']) ?></td>
            <td class="text-muted small" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($em['body_preview'] ?? '') ?></td>
            <td><span class="badge bg-light text-dark border"><?= ucfirst($em['channel']) ?></span></td>
            <td><span class="badge bg-<?= $stColor ?> <?= in_array($stColor,['warning','info'])?'text-dark':'' ?>"><?= ucfirst($em['status']) ?></span></td>
            <td class="text-center no-print">
              <button class="btn btn-sm btn-outline-danger" onclick="delEmail(<?= $em['id'] ?>)" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Log Modal -->
<div class="modal fade" id="elModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="log">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title"><i class="fas fa-envelope-open-text me-2"></i>Log Email Activity</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label fw-semibold">Contact</label><select name="contact_id" class="form-select"><option value="">-- None --</option><?php foreach ($contacts as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name'] . ($c['company'] ? ' (' . $c['company'] . ')' : '')) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label><input type="text" name="subject" class="form-control" required maxlength="255"></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Direction</label><select name="direction" class="form-select"><option value="outbound">Outbound</option><option value="inbound">Inbound</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Channel</label><select name="channel" class="form-select"><option value="email">Email</option><option value="gmail">Gmail</option><option value="outlook">Outlook</option><option value="smtp">SMTP</option></select></div>
            <div class="col-md-4"><label class="form-label fw-semibold">Status</label><select name="status" class="form-select"><option value="sent">Sent</option><option value="delivered">Delivered</option><option value="opened">Opened</option><option value="bounced">Bounced</option><option value="failed">Failed</option></select></div>
            <div class="col-md-6"><label class="form-label fw-semibold">Sent At</label><input type="datetime-local" name="sent_at" class="form-control" value="<?= date('Y-m-d\TH:i') ?>"></div>
            <div class="col-12"><label class="form-label fw-semibold">Body Preview <small class="text-muted">(max 500 chars)</small></label><textarea name="body_preview" class="form-control" rows="4" maxlength="500"></textarea></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Log Email</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delElForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delElId"></form>
<?php
$extraJs = <<<'JS'
<script>
function delEmail(id){
  Swal.fire({title:'Delete Log Entry?',text:'This email record will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'}).then(r=>{if(r.isConfirmed){document.getElementById('delElId').value=id;document.getElementById('delElForm').submit();}});
}
$(document).ready(function(){$('#elTable').DataTable({pageLength:20,order:[[0,'desc']],language:{emptyTable:'No email logs found.'}});});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
