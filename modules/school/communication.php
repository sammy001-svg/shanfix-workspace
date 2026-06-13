<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'send') {
        $title      = sanitize($_POST['title'] ?? '');
        $message    = sanitize($_POST['message'] ?? '');
        $recipients = sanitize($_POST['recipients'] ?? 'all_parents');
        $classId    = (int)($_POST['class_id'] ?? 0) ?: null;
        $channel    = in_array($_POST['channel'] ?? '', ['sms','notice','both']) ? $_POST['channel'] : 'both';

        if (!$title || !$message) {
            setFlash('error', 'Title and message are required.');
            redirect('communication.php');
        }

        // Collect recipient phones (for SMS)
        $phones = [];
        if ($channel !== 'notice') {
            try {
                if ($recipients === 'all_parents') {
                    $s = $pdo->prepare("SELECT DISTINCT p.phone FROM sch_parents p WHERE p.org_id=? AND p.phone IS NOT NULL AND p.phone!=''");
                    $s->execute([$orgId]); foreach ($s->fetchAll() as $r) $phones[] = $r['phone'];
                } elseif ($recipients === 'class_parents' && $classId) {
                    $s = $pdo->prepare("SELECT DISTINCT p.phone FROM sch_parents p JOIN sch_students st ON p.student_id=st.id WHERE p.org_id=? AND st.class_id=? AND p.phone IS NOT NULL AND p.phone!=''");
                    $s->execute([$orgId,$classId]); foreach ($s->fetchAll() as $r) $phones[] = $r['phone'];
                } elseif ($recipients === 'all_teachers') {
                    $s = $pdo->prepare("SELECT phone FROM sch_teachers WHERE org_id=? AND status='active' AND phone IS NOT NULL AND phone!=''");
                    $s->execute([$orgId]); foreach ($s->fetchAll() as $r) $phones[] = $r['phone'];
                } elseif ($recipients === 'all_staff') {
                    $s = $pdo->prepare("SELECT phone FROM sch_teachers WHERE org_id=? AND status='active' AND phone IS NOT NULL AND phone!=''");
                    $s->execute([$orgId]); foreach ($s->fetchAll() as $r) $phones[] = $r['phone'];
                    $s = $pdo->prepare("SELECT phone FROM users WHERE org_id=? AND phone IS NOT NULL AND phone!=''");
                    $s->execute([$orgId]); foreach ($s->fetchAll() as $r) $phones[] = $r['phone'];
                }
            } catch (Exception $e) {}
            $phones = array_unique(array_filter($phones));
        }

        $sentCount = 0;
        $smsMsg = substr($title . ': ' . $message, 0, 160);
        foreach ($phones as $phone) {
            notifySms($phone, $smsMsg, $orgId, 'school_communication');
            $sentCount++;
        }

        // Save to communications log
        try {
            $pdo->prepare("INSERT INTO sch_communications
                (org_id,title,message,recipients_type,class_id,channel,status,total_recipients,sent_count,created_by,sent_at)
                VALUES (?,?,?,?,?,?,'sent',?,?,?,NOW())")
                ->execute([$orgId,$title,$message,$recipients,$classId,$channel,count($phones),$sentCount,$user['id']]);
        } catch (Exception $e) {}

        logActivity('create','school',"Communication sent: \"$title\" — $sentCount recipient(s)");
        $msg = "\"$title\" sent.";
        if ($channel !== 'notice') $msg .= " SMS dispatched to $sentCount recipient(s).";
        setFlash('success', $msg);
        redirect('communication.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_communications WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success','Communication deleted.');
        redirect('communication.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

$classes = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Exception $e){}

// Communication history
$comms = [];
try {
    $s=$pdo->prepare("SELECT c.*,cl.name AS class_name,u.name AS sent_by_name
        FROM sch_communications c
        LEFT JOIN sch_classes cl ON c.class_id=cl.id
        LEFT JOIN users u ON c.created_by=u.id
        WHERE c.org_id=? ORDER BY c.sent_at DESC LIMIT 200");
    $s->execute([$orgId]); $comms=$s->fetchAll();
} catch(Exception $e){}

// Quick stats
$thisMonthCount = 0; $totalParentsReached = 0;
try {
    $s=$pdo->prepare("SELECT COUNT(*) FROM sch_communications WHERE org_id=? AND MONTH(sent_at)=MONTH(NOW()) AND YEAR(sent_at)=YEAR(NOW())");
    $s->execute([$orgId]); $thisMonthCount=(int)$s->fetchColumn();
    $s=$pdo->prepare("SELECT COALESCE(SUM(sent_count),0) FROM sch_communications WHERE org_id=? AND recipients_type IN('all_parents','class_parents')");
    $s->execute([$orgId]); $totalParentsReached=(int)$s->fetchColumn();
} catch(Exception $e){}

$recipientLabels = [
    'all_parents'   => 'All Parents',
    'class_parents' => 'Class Parents',
    'all_teachers'  => 'All Teachers',
    'all_staff'     => 'All Staff',
];
$channelColors = ['sms'=>'success','notice'=>'primary','both'=>'warning text-dark'];
$channelIcons  = ['sms'=>'fa-sms','notice'=>'fa-bullhorn','both'=>'fa-broadcast-tower'];
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-broadcast-tower me-2" style="color:<?= $moduleColor ?>"></i>School Communication</h4>
    <p class="text-muted mb-0">Send circulars, announcements, and alerts to parents and staff</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#commModal">
    <i class="fas fa-paper-plane me-2"></i>New Communication
  </button>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
  <?php
  $totalComms = count($comms);
  $smsComms = count(array_filter($comms, fn($c)=>in_array($c['channel'],['sms','both'])));
  ?>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-envelope-open-text"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $totalComms ?></div><div class="stat-label">Total Sent</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-calendar-check"></i></div>
    <div class="stat-body"><div class="stat-value"><?= $thisMonthCount ?></div><div class="stat-label">This Month</div></div></div>
  </div>
  <div class="col-sm-4">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-users"></i></div>
    <div class="stat-body"><div class="stat-value"><?= number_format($totalParentsReached) ?></div><div class="stat-label">Parent SMS Sent</div></div></div>
  </div>
</div>

<!-- Communication History -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-history me-2 text-muted"></i>Communication History</h6></div>
  <div class="card-body p-0">
    <?php if (empty($comms)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-broadcast-tower fa-3x mb-3 d-block opacity-25"></i>
      <h6>No communications sent yet</h6>
      <p class="small mb-0">Click "New Communication" to send your first circular.</p>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 datatable">
        <thead class="table-light">
          <tr>
            <th class="ps-3">Title</th>
            <th>Message</th>
            <th>Recipients</th>
            <th class="text-center">Channel</th>
            <th class="text-center">Sent To</th>
            <th>Sent By</th>
            <th>Date & Time</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($comms as $c): ?>
        <tr>
          <td class="ps-3 fw-semibold"><?= e($c['title']) ?></td>
          <td>
            <span class="text-muted small text-truncate d-inline-block" style="max-width:220px"
                  title="<?= e($c['message']) ?>"><?= e($c['message']) ?></span>
          </td>
          <td>
            <span class="badge bg-light text-dark border">
              <?= $recipientLabels[$c['recipients_type']] ?? $c['recipients_type'] ?>
              <?php if ($c['class_name']): ?> — <?= e($c['class_name']) ?><?php endif; ?>
            </span>
          </td>
          <td class="text-center">
            <span class="badge bg-<?= $channelColors[$c['channel']] ?? 'secondary' ?>">
              <i class="fas <?= $channelIcons[$c['channel']] ?? 'fa-circle' ?> me-1"></i>
              <?= ucfirst($c['channel']) ?>
            </span>
          </td>
          <td class="text-center">
            <span class="fw-semibold"><?= number_format($c['sent_count']) ?></span>
            <span class="text-muted small">/ <?= number_format($c['total_recipients']) ?></span>
          </td>
          <td class="small"><?= e($c['sent_by_name'] ?? '—') ?></td>
          <td class="text-muted small"><?= $c['sent_at'] ? date('d M Y H:i', strtotime($c['sent_at'])) : '—' ?></td>
          <td class="text-center">
            <button class="btn btn-sm btn-outline-info" onclick="previewComm(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)" title="View">
              <i class="fas fa-eye"></i>
            </button>
            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this communication record?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- New Communication Modal -->
<div class="modal fade" id="commModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
        <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>New Communication</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="send">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12">
              <label class="form-label fw-semibold">Subject / Title <span class="text-danger">*</span></label>
              <input type="text" name="title" class="form-control" required
                     placeholder="e.g. Term 2 Opening Notice">
            </div>
            <div class="col-md-5">
              <label class="form-label fw-semibold">Send To <span class="text-danger">*</span></label>
              <select name="recipients" id="recipientType" class="form-select" required onchange="toggleClassSelect(this)">
                <option value="all_parents">All Parents</option>
                <option value="class_parents">Specific Class Parents</option>
                <option value="all_teachers">All Teachers</option>
                <option value="all_staff">All Staff (Teachers + Admin)</option>
              </select>
            </div>
            <div class="col-md-4" id="classSelectWrapper" style="display:none">
              <label class="form-label fw-semibold">Select Class</label>
              <select name="class_id" class="form-select">
                <option value="">— choose class —</option>
                <?php foreach ($classes as $c): ?>
                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Channel <span class="text-danger">*</span></label>
              <select name="channel" class="form-select" id="channelSelect" onchange="updateChannelHint()">
                <option value="both">SMS + Notice Board</option>
                <option value="sms">SMS Only</option>
                <option value="notice">Notice Board Only</option>
              </select>
            </div>
            <div class="col-12" id="channelHint">
              <div class="alert alert-warning py-2 small mb-0" id="hintBoth">
                <i class="fas fa-info-circle me-1"></i>
                Message will be sent via <strong>SMS</strong> and recorded in the communication log.
              </div>
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold">Message <span class="text-danger">*</span></label>
              <textarea name="message" id="commMessage" class="form-control" rows="5" required
                        placeholder="Type your announcement or circular here…"
                        oninput="updateCharCount(this)"></textarea>
              <div class="d-flex justify-content-between mt-1">
                <small class="text-muted">Keep SMS messages under 160 characters for single-part delivery.</small>
                <small id="charCount" class="text-muted">0 chars</small>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">
            <i class="fas fa-paper-plane me-1"></i>Send Now
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="previewTitle"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <dl class="row mb-0">
          <dt class="col-4 text-muted">Recipients</dt><dd class="col-8" id="previewRecipients"></dd>
          <dt class="col-4 text-muted">Channel</dt><dd class="col-8" id="previewChannel"></dd>
          <dt class="col-4 text-muted">Sent To</dt><dd class="col-8" id="previewSentCount"></dd>
          <dt class="col-4 text-muted">Date</dt><dd class="col-8" id="previewDate"></dd>
          <dt class="col-4 text-muted">Message</dt><dd class="col-8 fst-italic" id="previewMessage"></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php $extraJs = <<<'JS'
<script>
function toggleClassSelect(sel) {
    const wrapper = document.getElementById('classSelectWrapper');
    wrapper.style.display = sel.value === 'class_parents' ? 'block' : 'none';
}
function updateChannelHint() {
    const ch = document.getElementById('channelSelect').value;
    const hint = document.getElementById('hintBoth');
    const msgs = {
        both:   'Message will be sent via <strong>SMS</strong> and recorded in the communication log.',
        sms:    'Message will be sent via <strong>SMS only</strong> to recipient phone numbers.',
        notice: 'Message will be <strong>recorded only</strong> in the communication log — no SMS will be sent.'
    };
    hint.innerHTML = '<i class="fas fa-info-circle me-1"></i>' + msgs[ch];
    hint.className = 'alert py-2 small mb-0 ' + (ch === 'notice' ? 'alert-info' : 'alert-warning');
}
function updateCharCount(ta) {
    const cc = document.getElementById('charCount');
    const len = ta.value.length;
    cc.textContent = len + ' chars';
    cc.className = 'text-' + (len > 160 ? (len > 320 ? 'danger' : 'warning') : 'muted');
}
function previewComm(data) {
    const labels = {all_parents:'All Parents',class_parents:'Class Parents',all_teachers:'All Teachers',all_staff:'All Staff'};
    document.getElementById('previewTitle').textContent = data.title;
    document.getElementById('previewRecipients').textContent = (labels[data.recipients_type] || data.recipients_type) + (data.class_name ? ' — ' + data.class_name : '');
    document.getElementById('previewChannel').textContent = data.channel;
    document.getElementById('previewSentCount').textContent = data.sent_count + ' / ' + data.total_recipients;
    document.getElementById('previewDate').textContent = data.sent_at || '—';
    document.getElementById('previewMessage').textContent = data.message;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php'; ?>
