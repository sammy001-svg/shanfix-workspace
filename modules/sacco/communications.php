<?php
$moduleSlug  = 'sacco';
$moduleName  = 'SACCO Management';
$moduleIcon  = 'fas fa-piggy-bank';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt',    'label' => 'Dashboard'],
    ['url' => 'members.php',      'icon' => 'fas fa-users',             'label' => 'Members'],
    ['url' => 'savings.php',      'icon' => 'fas fa-piggy-bank',        'label' => 'Savings'],
    ['url' => 'loans.php',        'icon' => 'fas fa-hand-holding-usd',  'label' => 'Loans'],
    ['url' => 'shares.php',       'icon' => 'fas fa-certificate',       'label' => 'Shares'],
    ['url' => 'repayments.php',   'icon' => 'fas fa-undo',              'label' => 'Repayments'],
    ['url' => 'dividends.php',    'icon' => 'fas fa-percentage',        'label' => 'Dividends'],
    ['url' => 'statements.php',   'icon' => 'fas fa-file-invoice',      'label' => 'Statements'],
    ['url' => 'guarantors.php',   'icon' => 'fas fa-user-shield',       'label' => 'Guarantors'],
    ['url' => 'penalties.php',    'icon' => 'fas fa-exclamation-circle', 'label' => 'Penalties'],
    ['url' => 'communications.php','icon'=> 'fas fa-envelope',           'label' => 'Communications'],
    ['url' => 'reports.php',      'icon' => 'fas fa-chart-bar',         'label' => 'Reports'],
];

// ── POST Handler ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../includes/header-module.php';
    verifyCsrf();denyIfReadOnly($moduleSlug);
    $orgId  = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'send_communication') {
        $audience  = sanitize($_POST['audience'] ?? 'all');
        $memberId  = (int)($_POST['member_id'] ?? 0);
        $channel   = sanitize($_POST['channel'] ?? 'notice');
        $subject   = sanitize($_POST['subject'] ?? '');
        $message   = sanitize($_POST['message'] ?? '');
        $priority  = in_array($_POST['priority'] ?? '', ['low','normal','high','urgent']) ? $_POST['priority'] : 'normal';

        if (!$subject || !$message) {
            setFlash('danger', 'Subject and message body are required.');
        } else {
            // Determine recipients
            if ($audience === 'individual' && $memberId) {
                $recipientIds = [$memberId];
            } else {
                $res = $pdo->prepare("SELECT id FROM sacco_members WHERE org_id=? AND status='active'");
                $res->execute([$orgId]);
                $recipientIds = array_column($res->fetchAll(), 'id');
            }

            $sent = 0;
            $stmt = $pdo->prepare("INSERT INTO sacco_communications (org_id,member_id,channel,subject,message,priority,sent_by,status) VALUES (?,?,?,?,?,?,?,'sent')");
            foreach ($recipientIds as $rid) {
                $stmt->execute([$orgId, $rid, $channel, $subject, $message, $priority, $user['id']]);
                $sent++;
            }
            setFlash('success', "Communication sent to $sent member(s).");
            logActivity('create', 'sacco', "Sent '$subject' to $sent members via $channel");
        }
        redirect(APP_URL . '/modules/sacco/communications.php');
    }

    if ($action === 'delete_comm') {
        $id = (int)$_POST['comm_id'];
        $pdo->prepare("DELETE FROM sacco_communications WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Communication record deleted.');
        redirect(APP_URL . '/modules/sacco/communications.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$orgId = (int)$user['org_id'];

$members = $pdo->prepare("SELECT id, CONCAT(first_name,' ',last_name) AS name, member_no FROM sacco_members WHERE org_id=? AND status='active' ORDER BY first_name");
$members->execute([$orgId]);
$members = $members->fetchAll();

// Filter
$filterChannel  = $_GET['channel'] ?? '';
$filterPriority = $_GET['priority'] ?? '';

$comms = [];
try {
    $where = "WHERE c.org_id=?";
    $params = [$orgId];
    if ($filterChannel)  { $where .= " AND c.channel=?";   $params[] = $filterChannel; }
    if ($filterPriority) { $where .= " AND c.priority=?";  $params[] = $filterPriority; }

    $stmt = $pdo->prepare("SELECT c.*, CONCAT(m.first_name,' ',m.last_name) AS member_name, m.member_no
                           FROM sacco_communications c
                           JOIN sacco_members m ON c.member_id=m.id
                           $where ORDER BY c.created_at DESC LIMIT 200");
    $stmt->execute($params);
    $comms = $stmt->fetchAll();
} catch (Exception $e) {}

$totalSent   = count($comms);
$byChannel   = array_count_values(array_column($comms, 'channel'));
$urgentCount = count(array_filter($comms, fn($c) => $c['priority'] === 'urgent'));

$channels   = ['notice','sms','email','whatsapp','letter'];
$priorities = ['low','normal','high','urgent'];
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-envelope me-2" style="color:<?= $moduleColor ?>"></i>Member Communications</h4>
    <p class="text-muted mb-0">Send notices, SMS, emails, and track all member communication history</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#commModal">
    <i class="fas fa-paper-plane me-2"></i>Send Communication
  </button>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:<?= $moduleColor ?>20;color:<?= $moduleColor ?>"><i class="fas fa-envelope"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $totalSent ?></div><div class="stat-label">Total Communications</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#e74c3c20;color:#e74c3c"><i class="fas fa-bell"></i></div>
      <div class="stat-body"><div class="stat-value"><?= $urgentCount ?></div><div class="stat-label">Urgent Messages</div></div>
    </div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card">
      <div class="stat-icon" style="background:#3498db20;color:#3498db"><i class="fas fa-broadcast-tower"></i></div>
      <div class="stat-body"><div class="stat-value"><?= count($byChannel) ?></div><div class="stat-label">Channels Used</div></div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-end">
      <div class="col-auto">
        <select name="channel" class="form-select form-select-sm">
          <option value="">All Channels</option>
          <?php foreach ($channels as $ch): ?>
          <option value="<?= $ch ?>" <?= $filterChannel===$ch?'selected':'' ?>><?= ucfirst($ch) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="priority" class="form-select form-select-sm">
          <option value="">All Priorities</option>
          <?php foreach ($priorities as $pr): ?>
          <option value="<?= $pr ?>" <?= $filterPriority===$pr?'selected':'' ?>><?= ucfirst($pr) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-filter me-1"></i>Filter</button>
        <a href="communications.php" class="btn btn-sm btn-link">Reset</a>
      </div>
    </form>
  </div>
</div>

<!-- Communications Log -->
<div class="card">
  <div class="card-header"><h6 class="mb-0 fw-semibold">Communication History</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="commsTable">
        <thead class="table-light">
          <tr>
            <th>Date / Time</th>
            <th>Member</th>
            <th>Channel</th>
            <th>Subject</th>
            <th>Priority</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($comms)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5"><i class="fas fa-envelope-open fa-3x mb-3 d-block opacity-25"></i>No communications logged yet.</td></tr>
          <?php else: foreach ($comms as $c):
            $pMap = ['low'=>'secondary','normal'=>'info','high'=>'warning','urgent'=>'danger'];
            $chMap = ['notice'=>'primary','sms'=>'success','email'=>'info','whatsapp'=>'success','letter'=>'secondary'];
          ?>
          <tr>
            <td class="small"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></td>
            <td>
              <div class="fw-semibold small"><?= e($c['member_name']) ?></div>
              <small class="text-muted"><?= e($c['member_no']) ?></small>
            </td>
            <td><span class="badge bg-<?= $chMap[$c['channel']] ?? 'secondary' ?>"><?= ucfirst($c['channel']) ?></span></td>
            <td>
              <div class="small fw-semibold"><?= e($c['subject']) ?></div>
              <div class="small text-muted text-truncate" style="max-width:200px"><?= e(substr($c['message'],0,60)) ?>...</div>
            </td>
            <td><span class="badge bg-<?= $pMap[$c['priority']] ?? 'secondary' ?>"><?= ucfirst($c['priority']) ?></span></td>
            <td><span class="badge bg-success"><?= ucfirst($c['status']) ?></span></td>
            <td class="text-end">
              <button class="btn btn-sm btn-outline-secondary" onclick='viewMessage(<?= json_encode(['subject'=>$c['subject'],'message'=>$c['message'],'member'=>$c['member_name'],'channel'=>$c['channel']]) ?>)' title="View"><i class="fas fa-eye"></i></button>
              <form method="POST" class="d-inline" onsubmit="return confirm('Delete this record?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_comm">
                <input type="hidden" name="comm_id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Send Communication Modal -->
<div class="modal fade" id="commModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Send Communication</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="send_communication">
        <div class="modal-body row g-3">
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Audience</label>
            <select name="audience" id="fAudience" class="form-select" onchange="toggleMember()">
              <option value="all">All Active Members</option>
              <option value="individual">Individual Member</option>
            </select>
          </div>
          <div class="col-sm-6" id="memberRow" style="display:none">
            <label class="form-label fw-semibold">Member</label>
            <select name="member_id" id="fMember" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['member_no']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Channel</label>
            <select name="channel" class="form-select">
              <?php foreach ($channels as $ch): ?>
              <option value="<?= $ch ?>"><?= ucfirst($ch) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6">
            <label class="form-label fw-semibold">Priority</label>
            <select name="priority" class="form-select">
              <?php foreach ($priorities as $pr): ?>
              <option value="<?= $pr ?>" <?= $pr==='normal'?'selected':'' ?>><?= ucfirst($pr) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Subject <span class="text-danger">*</span></label>
            <input type="text" name="subject" class="form-control" required maxlength="200" placeholder="e.g. Loan Repayment Reminder — January 2025">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
            <textarea name="message" class="form-control" rows="5" required placeholder="Write your message here…"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-paper-plane me-2"></i>Send</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- View Message Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h6 class="modal-title fw-semibold" id="viewSubject"></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2 small text-muted">To: <strong id="viewMember"></strong> via <strong id="viewChannel"></strong></div>
        <div id="viewBody" class="border rounded p-3 bg-light" style="white-space:pre-wrap"></div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
$("#commsTable").DataTable({pageLength:25,order:[[0,"desc"]]});

function toggleMember() {
  var v = document.getElementById('fAudience').value;
  document.getElementById('memberRow').style.display = v === 'individual' ? '' : 'none';
  document.getElementById('fMember').required = v === 'individual';
}

function viewMessage(data) {
  document.getElementById('viewSubject').textContent = data.subject;
  document.getElementById('viewMember').textContent = data.member;
  document.getElementById('viewChannel').textContent = data.channel;
  document.getElementById('viewBody').textContent = data.message;
  new bootstrap.Modal(document.getElementById('viewModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
