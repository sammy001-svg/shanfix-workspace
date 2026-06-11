<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'contracts.php', 'icon' => 'fas fa-file-signature',  'label' => 'Contracts'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-headset',          'label' => 'Support Tickets'],
    ['url' => 'email-log.php', 'icon' => 'fas fa-envelope-open-text','label' => 'Email Log'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',        'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $user   = currentUser();
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = sanitize($_POST['name'] ?? '');
        $type    = in_array($_POST['type'] ?? '', ['email','sms','social','event','other']) ? $_POST['type'] : 'email';
        $status  = in_array($_POST['status'] ?? '', ['draft','active','paused','completed','cancelled']) ? $_POST['status'] : 'draft';
        $target  = in_array($_POST['target_type'] ?? '', ['all','customers','leads','partners','vendors']) ? $_POST['target_type'] : 'all';
        $subject = sanitize($_POST['subject'] ?? '');
        $content = sanitize($_POST['content'] ?? '');
        $sDate   = $_POST['start_date'] ?? null;
        $eDate   = $_POST['end_date'] ?? null;
        $budget  = (float)($_POST['budget'] ?? 0);
        $notes   = sanitize($_POST['notes'] ?? '');

        if ($id > 0) {
            $pdo->prepare("UPDATE crm_campaigns SET name=?,type=?,status=?,target_type=?,subject=?,content=?,start_date=?,end_date=?,budget=?,notes=? WHERE id=? AND org_id=?")
                ->execute([$name,$type,$status,$target,$subject,$content,$sDate?:null,$eDate?:null,$budget,$notes,$id,$orgId]);
            setFlash('success', 'Campaign updated.');
        } else {
            $pdo->prepare("INSERT INTO crm_campaigns (org_id,name,type,status,target_type,subject,content,start_date,end_date,budget,notes,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$orgId,$name,$type,$status,$target,$subject,$content,$sDate?:null,$eDate?:null,$budget,$notes,$user['id']??0]);
            setFlash('success', "Campaign '$name' created.");
        }
        logActivity($id > 0 ? 'update' : 'create', 'crm', "Campaign: $name");
        redirect('campaigns.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM crm_campaigns WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Campaign deleted.');
        redirect('campaigns.php');
    }

    // Update sent/open/click counters (manual tracking)
    if ($action === 'update_stats') {
        $id    = (int)($_POST['id'] ?? 0);
        $sent  = (int)($_POST['sent_count']  ?? 0);
        $open  = (int)($_POST['open_count']  ?? 0);
        $click = (int)($_POST['click_count'] ?? 0);
        $pdo->prepare("UPDATE crm_campaigns SET sent_count=?,open_count=?,click_count=? WHERE id=? AND org_id=?")
            ->execute([$sent,$open,$click,$id,$orgId]);
        setFlash('success', 'Campaign stats updated.');
        redirect('campaigns.php');
    }

    // Dispatch email campaign to contacts
    if ($action === 'send_campaign') {
        $id = (int)($_POST['id'] ?? 0);

        $cStmt = $pdo->prepare("SELECT * FROM crm_campaigns WHERE id=? AND org_id=? AND type='email'");
        $cStmt->execute([$id, $orgId]);
        $camp = $cStmt->fetch();

        if (!$camp) {
            setFlash('danger', 'Email campaign not found.');
            redirect('campaigns.php');
        }

        // Build contact query based on target_type
        $target = $camp['target_type'];
        if ($target === 'all') {
            $cq = $pdo->prepare("SELECT email, name FROM crm_contacts WHERE org_id=? AND status='active' AND email IS NOT NULL AND email != ''");
            $cq->execute([$orgId]);
        } else {
            // customers→customer, leads→lead, partners→partner, vendors→vendor
            $typeMap = ['customers'=>'customer','leads'=>'lead','partners'=>'partner','vendors'=>'vendor'];
            $contactType = $typeMap[$target] ?? rtrim($target, 's');
            $cq = $pdo->prepare("SELECT email, name FROM crm_contacts WHERE org_id=? AND status='active' AND type=? AND email IS NOT NULL AND email != ''");
            $cq->execute([$orgId, $contactType]);
        }
        $contacts = $cq->fetchAll();

        $sent = 0;
        $mailInst = mailer();
        foreach ($contacts as $c) {
            $cName = e($c['name'] ?? 'Valued Customer');
            $body  = '<p>Dear ' . $cName . ',</p>' . nl2br(e($camp['content'])) . '<br><br><small style="color:#888;">You are receiving this because you are a contact of ' . e(APP_NAME) . '.</small>';
            try {
                $mailInst->send($c['email'], $camp['subject'], $body);
                $sent++;
            } catch (Throwable $ex) {}
        }

        $pdo->prepare("UPDATE crm_campaigns SET sent_count=sent_count+?, status='active' WHERE id=? AND org_id=?")
            ->execute([$sent, $id, $orgId]);

        setFlash('success', "Campaign dispatched to {$sent} contact(s).");
        logActivity('send', 'crm', "Email campaign '{$camp['name']}' dispatched to {$sent} contacts");
        redirect('campaigns.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$fStatus = $_GET['status'] ?? '';
$fType   = $_GET['type']   ?? '';
$where   = 'org_id=?';
$params  = [$orgId];
if ($fStatus) { $where .= ' AND status=?'; $params[] = $fStatus; }
if ($fType)   { $where .= ' AND type=?';   $params[] = $fType; }

$campaigns = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM crm_campaigns WHERE $where ORDER BY created_at DESC");
    $stmt->execute($params);
    $campaigns = $stmt->fetchAll();
} catch (Exception $e) {}

$total    = countRows('crm_campaigns', 'org_id=?', [$orgId]);
$active   = countRows('crm_campaigns', 'org_id=? AND status=?', [$orgId,'active']);
$draft    = countRows('crm_campaigns', 'org_id=? AND status=?', [$orgId,'draft']);
$completed= countRows('crm_campaigns', 'org_id=? AND status=?', [$orgId,'completed']);

// Contact counts by type (for targeting preview)
$targetCounts = [];
try {
    $s = $pdo->prepare("SELECT type, COUNT(*) AS cnt FROM crm_contacts WHERE org_id=? AND status='active' GROUP BY type");
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) { $targetCounts[$r['type']] = (int)$r['cnt']; }
} catch (Exception $e) {}
$allContacts = array_sum($targetCounts);

$statusColors = ['draft'=>'secondary','active'=>'success','paused'=>'warning','completed'=>'info','cancelled'=>'danger'];
$typeIcons    = ['email'=>'fas fa-envelope','sms'=>'fas fa-sms','social'=>'fas fa-share-alt','event'=>'fas fa-calendar-alt','other'=>'fas fa-bullhorn'];

// View single campaign
$viewCamp = null;
if (isset($_GET['view'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_campaigns WHERE id=? AND org_id=?");
        $stmt->execute([(int)$_GET['view'], $orgId]);
        $viewCamp = $stmt->fetch();
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-bullhorn me-2" style="color:<?= $moduleColor ?>"></i>Campaigns</h4>
    <p class="text-muted mb-0">Plan and track marketing and outreach campaigns</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#campModal" onclick="openAdd()">
    <i class="fas fa-plus me-2"></i>New Campaign
  </button>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-bullhorn"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $total ?></div><div class="stat-label">Total Campaigns</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-play-circle"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $active ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-edit"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $draft ?></div><div class="stat-label">Drafts</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-check-double"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $completed ?></div><div class="stat-label">Completed</div></div></div>
  </div>
</div>

<!-- Campaign view panel -->
<?php if ($viewCamp): ?>
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between" style="background:<?= $moduleColor ?>;color:#fff">
    <h6 class="mb-0"><i class="<?= $typeIcons[$viewCamp['type']] ?? 'fas fa-bullhorn' ?> me-2"></i><?= e($viewCamp['name']) ?></h6>
    <a href="campaigns.php" class="btn btn-sm btn-light"><i class="fas fa-times me-1"></i>Close</a>
  </div>
  <div class="card-body">
    <div class="row g-4">
      <div class="col-md-6">
        <table class="table table-sm">
          <tr><th class="text-muted w-40">Type</th><td><span class="badge bg-info text-dark"><?= ucfirst($viewCamp['type']) ?></span></td></tr>
          <tr><th class="text-muted">Status</th><td><?= statusBadge($viewCamp['status']) ?></td></tr>
          <tr><th class="text-muted">Target</th><td><?= ucfirst($viewCamp['target_type']) ?></td></tr>
          <tr><th class="text-muted">Subject</th><td><?= e($viewCamp['subject'] ?? '—') ?></td></tr>
          <tr><th class="text-muted">Start</th><td><?= $viewCamp['start_date'] ? formatDate($viewCamp['start_date']) : '—' ?></td></tr>
          <tr><th class="text-muted">End</th><td><?= $viewCamp['end_date'] ? formatDate($viewCamp['end_date']) : '—' ?></td></tr>
          <tr><th class="text-muted">Budget</th><td><?= formatCurrency((float)$viewCamp['budget']) ?></td></tr>
        </table>
      </div>
      <div class="col-md-6">
        <h6 class="text-muted mb-2 fw-semibold">Performance Stats</h6>
        <div class="d-flex gap-3 mb-3">
          <div class="text-center">
            <div class="fs-4 fw-bold" style="color:<?= $moduleColor ?>"><?= number_format($viewCamp['sent_count']) ?></div>
            <div class="small text-muted">Sent</div>
          </div>
          <div class="text-center">
            <div class="fs-4 fw-bold text-success"><?= number_format($viewCamp['open_count']) ?></div>
            <div class="small text-muted">Opened</div>
            <?php if ($viewCamp['sent_count'] > 0): ?>
            <div class="small text-muted"><?= round($viewCamp['open_count']/$viewCamp['sent_count']*100,1) ?>%</div>
            <?php endif; ?>
          </div>
          <div class="text-center">
            <div class="fs-4 fw-bold text-warning"><?= number_format($viewCamp['click_count']) ?></div>
            <div class="small text-muted">Clicks</div>
            <?php if ($viewCamp['sent_count'] > 0): ?>
            <div class="small text-muted"><?= round($viewCamp['click_count']/$viewCamp['sent_count']*100,1) ?>%</div>
            <?php endif; ?>
          </div>
        </div>
        <!-- Update stats form -->
        <form method="POST" class="border rounded p-3 bg-light">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="update_stats">
          <input type="hidden" name="id" value="<?= $viewCamp['id'] ?>">
          <div class="small fw-semibold mb-2">Update Counters</div>
          <div class="row g-2">
            <div class="col-4"><label class="form-label small">Sent</label><input type="number" name="sent_count"  class="form-control form-control-sm" min="0" value="<?= $viewCamp['sent_count'] ?>"></div>
            <div class="col-4"><label class="form-label small">Opened</label><input type="number" name="open_count"  class="form-control form-control-sm" min="0" value="<?= $viewCamp['open_count'] ?>"></div>
            <div class="col-4"><label class="form-label small">Clicks</label><input type="number" name="click_count" class="form-control form-control-sm" min="0" value="<?= $viewCamp['click_count'] ?>"></div>
          </div>
          <button type="submit" class="btn btn-sm btn-primary mt-2 w-100"><i class="fas fa-save me-1"></i>Save Stats</button>
        </form>
      </div>
      <?php if ($viewCamp['content']): ?>
      <div class="col-12">
        <h6 class="text-muted mb-2 fw-semibold">Campaign Content</h6>
        <div class="border rounded p-3 bg-light" style="white-space:pre-wrap;font-size:.85rem"><?= e($viewCamp['content']) ?></div>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3"><div class="card-body py-2">
  <form method="GET" class="row g-2 align-items-end">
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Status</label>
      <select name="status" class="form-select form-select-sm">
        <option value="">All Statuses</option>
        <?php foreach (['draft','active','paused','completed','cancelled'] as $s): ?>
        <option value="<?= $s ?>" <?= $fStatus===$s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-sm-3"><label class="form-label small fw-semibold mb-1">Type</label>
      <select name="type" class="form-select form-select-sm">
        <option value="">All Types</option>
        <?php foreach (array_keys($typeIcons) as $t): ?>
        <option value="<?= $t ?>" <?= $fType===$t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-auto">
      <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-filter me-1"></i>Filter</button>
      <a href="campaigns.php" class="btn btn-sm btn-outline-secondary ms-1">Reset</a>
    </div>
  </form>
</div></div>

<!-- Campaigns Table -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-bullhorn me-2" style="color:<?= $moduleColor ?>"></i>Campaign List</h6>
    <span class="badge bg-secondary"><?= count($campaigns) ?> campaigns</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr><th>Name</th><th>Type</th><th>Target</th><th>Status</th><th>Sent</th><th>Open Rate</th><th>Start</th><th>Budget</th><th class="text-center">Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($campaigns)): ?>
          <tr><td colspan="9" class="text-center text-muted py-5"><i class="fas fa-bullhorn fa-2x mb-2 d-block"></i>No campaigns yet.</td></tr>
        <?php else: foreach ($campaigns as $camp): ?>
          <?php
            $openRate = $camp['sent_count'] > 0 ? round($camp['open_count'] / $camp['sent_count'] * 100, 1) : 0;
          ?>
          <tr>
            <td class="fw-semibold"><?= e($camp['name']) ?></td>
            <td><span class="badge bg-info text-dark"><i class="<?= $typeIcons[$camp['type']] ?? 'fas fa-bullhorn' ?> me-1"></i><?= ucfirst($camp['type']) ?></span></td>
            <td class="small"><?= ucfirst($camp['target_type']) ?></td>
            <td><span class="badge bg-<?= $statusColors[$camp['status']] ?? 'secondary' ?>"><?= ucfirst($camp['status']) ?></span></td>
            <td><?= number_format($camp['sent_count']) ?></td>
            <td>
              <?php if ($camp['sent_count'] > 0): ?>
              <div class="d-flex align-items-center gap-2">
                <div class="progress flex-grow-1" style="height:6px;width:60px">
                  <div class="progress-bar bg-success" style="width:<?= $openRate ?>%"></div>
                </div>
                <small><?= $openRate ?>%</small>
              </div>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
            <td class="small"><?= $camp['start_date'] ? formatDate($camp['start_date']) : '—' ?></td>
            <td class="small"><?= formatCurrency((float)$camp['budget']) ?></td>
            <td class="text-center" style="white-space:nowrap">
              <a href="?view=<?= $camp['id'] ?>" class="btn btn-sm btn-outline-info" title="View"><i class="fas fa-eye"></i></a>
              <button class="btn btn-sm btn-outline-primary ms-1" onclick='openEdit(<?= htmlspecialchars(json_encode($camp), ENT_QUOTES) ?>)' title="Edit"><i class="fas fa-edit"></i></button>
              <?php if ($camp['type'] === 'email' && !in_array($camp['status'], ['cancelled','completed']) && $camp['subject']): ?>
              <button class="btn btn-sm btn-outline-success ms-1" onclick="sendCampaign(<?= $camp['id'] ?>,'<?= e($camp['name']) ?>')" title="Send Email Campaign"><i class="fas fa-paper-plane"></i></button>
              <?php endif; ?>
              <button class="btn btn-sm btn-outline-danger ms-1" onclick="delCampaign(<?= $camp['id'] ?>,'<?= e($camp['name']) ?>')" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Modal -->
<div class="modal fade" id="campModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="campId" value="0">
        <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
          <h5 class="modal-title" id="campModalTitle"><i class="fas fa-bullhorn me-2"></i>New Campaign</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Campaign Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="campName" class="form-control" required maxlength="255">
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Type</label>
              <select name="type" id="campType" class="form-select">
                <?php foreach (array_keys($typeIcons) as $t): ?>
                <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Status</label>
              <select name="status" id="campStatus" class="form-select">
                <?php foreach (['draft','active','paused','completed','cancelled'] as $s): ?>
                <option value="<?= $s ?>"><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Target Audience</label>
              <select name="target_type" id="campTarget" class="form-select">
                <option value="all">All Contacts (<?= $allContacts ?>)</option>
                <?php foreach (['customers','leads','partners','vendors'] as $tgt): ?>
                <option value="<?= $tgt ?>"><?= ucfirst($tgt) ?> (<?= $targetCounts[$tgt === 'leads' ? 'lead' : rtrim($tgt,'s')] ?? 0 ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">Start Date</label>
              <input type="date" name="start_date" id="campStart" class="form-control">
            </div>
            <div class="col-md-4">
              <label class="form-label fw-semibold">End Date</label>
              <input type="date" name="end_date" id="campEnd" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Subject / Headline</label>
              <input type="text" name="subject" id="campSubject" class="form-control" maxlength="255">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Budget (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="budget" id="campBudget" class="form-control" min="0" step="0.01" value="0">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Campaign Content / Message</label>
              <textarea name="content" id="campContent" class="form-control" rows="6" placeholder="Write your campaign message, email body, or campaign description here…"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Internal Notes</label>
              <textarea name="notes" id="campNotes" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Campaign</button>
        </div>
      </form>
    </div>
  </div>
</div>
<form method="POST" id="delCampForm" style="display:none"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delCampId"></form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('campModalTitle').innerHTML = '<i class="fas fa-bullhorn me-2"></i>New Campaign';
  ['campId','campName','campSubject','campContent','campNotes','campStart','campEnd'].forEach(i => document.getElementById(i).value = i==='campId' ? '0' : '');
  document.getElementById('campType').value   = 'email';
  document.getElementById('campStatus').value = 'draft';
  document.getElementById('campTarget').value = 'all';
  document.getElementById('campBudget').value = 0;
}
function openEdit(c) {
  document.getElementById('campModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Campaign';
  document.getElementById('campId').value      = c.id;
  document.getElementById('campName').value    = c.name || '';
  document.getElementById('campType').value    = c.type || 'email';
  document.getElementById('campStatus').value  = c.status || 'draft';
  document.getElementById('campTarget').value  = c.target_type || 'all';
  document.getElementById('campSubject').value = c.subject || '';
  document.getElementById('campContent').value = c.content || '';
  document.getElementById('campStart').value   = c.start_date ? c.start_date.substring(0,10) : '';
  document.getElementById('campEnd').value     = c.end_date   ? c.end_date.substring(0,10)   : '';
  document.getElementById('campBudget').value  = c.budget || 0;
  document.getElementById('campNotes').value   = c.notes || '';
  new bootstrap.Modal(document.getElementById('campModal')).show();
}
function delCampaign(id, name) {
  Swal.fire({title:'Delete Campaign?',text:'"'+name+'" will be permanently removed.',icon:'warning',showCancelButton:true,confirmButtonColor:'#e74c3c',confirmButtonText:'Yes, delete'})
    .then(r => { if (r.isConfirmed) { document.getElementById('delCampId').value = id; document.getElementById('delCampForm').submit(); } });
}

function sendCampaign(id, name) {
  Swal.fire({title:'Send Campaign?',html:'Dispatch <strong>"'+name+'"</strong> by email to all matching contacts?',icon:'question',showCancelButton:true,confirmButtonColor:'#198754',confirmButtonText:'<i class="fas fa-paper-plane me-1"></i>Send Now'})
    .then(r => {
      if (r.isConfirmed) {
        const f = document.createElement('form');
        f.method = 'POST'; f.action = 'campaigns.php';
        f.innerHTML = `<?= csrfField() ?><input name="action" value="send_campaign"><input name="id" value="${id}">`;
        document.body.appendChild(f); f.submit();
      }
    });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
