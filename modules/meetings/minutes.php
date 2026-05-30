<?php
$moduleSlug = 'meetings';
$moduleName = 'Meetings & Minutes';
$moduleIcon = 'fas fa-video';
$moduleColor = '#0B2D4E';
$moduleNav = [
    ['url' => 'index.php',        'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'meetings.php',     'icon' => 'fas fa-video',          'label' => 'Meetings'],
    ['url' => 'minutes.php',      'icon' => 'fas fa-file-alt',       'label' => 'Minutes'],
    ['url' => 'actions.php',      'icon' => 'fas fa-tasks',          'label' => 'Action Items'],
    ['url' => 'participants.php', 'icon' => 'fas fa-address-book',   'label' => 'Participants'],
    ['url' => 'calendar.php',     'icon' => 'fas fa-calendar',       'label' => 'Calendar'],
    ['url' => 'agenda.php',      'icon' => 'fas fa-list-ul',         'label' => 'Agenda'],
    ['url' => 'recordings.php',  'icon' => 'fas fa-microphone',      'label' => 'Recordings'],
    ['url' => 'documents.php',   'icon' => 'fas fa-folder-open',     'label' => 'Documents'],
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
        $meetingId = (int)($_POST['meeting_id'] ?? 0);
        $content = $_POST['content'] ?? '';
        $actionItems = $_POST['action_items'] ?? '';

        // Verify meeting belongs to org
        $stmt = $pdo->prepare("SELECT id FROM meetings WHERE id = ? AND org_id = ?");
        $stmt->execute([$meetingId, $orgId]);
        if ($stmt->fetch()) {
            if ($id > 0) {
                $stmt = $pdo->prepare("UPDATE meeting_minutes SET meeting_id = ?, content = ?, action_items = ? WHERE id = ?");
                $stmt->execute([$meetingId, $content, $actionItems, $id]);
                setFlash('success', 'Meeting minutes updated successfully.');
            } else {
                $stmt = $pdo->prepare("INSERT INTO meeting_minutes (meeting_id, content, action_items, created_by) VALUES (?, ?, ?, ?)");
                $stmt->execute([$meetingId, $content, $actionItems, $user['id']]);
                setFlash('success', 'Meeting minutes recorded successfully.');
                
                // Automatically set meeting status to completed if not already
                $pdo->prepare("UPDATE meetings SET status = 'completed' WHERE id = ?")->execute([$meetingId]);
            }
            logActivity($id > 0 ? 'update' : 'create', 'meetings', "Recorded minutes for Meeting ID: $meetingId");
        } else {
            setFlash('danger', 'Unauthorized or invalid meeting selected.');
        }
        redirect('minutes.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Verify ownership through join
        $stmt = $pdo->prepare("SELECT mm.id 
                               FROM meeting_minutes mm
                               JOIN meetings m ON mm.meeting_id = m.id
                               WHERE mm.id = ? AND m.org_id = ?");
        $stmt->execute([$id, $orgId]);
        if ($stmt->fetch()) {
            $pdo->prepare("DELETE FROM meeting_minutes WHERE id = ? AND meeting_id IN (SELECT id FROM meetings WHERE org_id = ?)")->execute([$id, $orgId]);
            setFlash('success', 'Minutes record deleted successfully.');
        }
        redirect('minutes.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// Fetch recorded minutes
$minutesList = [];
try {
    $stmt = $pdo->prepare("SELECT mm.*, m.title AS meeting_title, m.meeting_date, u.name AS recorded_by_name 
                           FROM meeting_minutes mm
                           JOIN meetings m ON mm.meeting_id = m.id
                           LEFT JOIN users u ON mm.created_by = u.id
                           WHERE m.org_id = ?
                           ORDER BY m.meeting_date DESC, mm.created_at DESC");
    $stmt->execute([$orgId]);
    $minutesList = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch eligible meetings for new minutes (meetings that do not have minutes yet)
$eligibleMeetings = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, meeting_date 
                           FROM meetings 
                           WHERE org_id = ? AND id NOT IN (SELECT meeting_id FROM meeting_minutes)
                           ORDER BY meeting_date DESC");
    $stmt->execute([$orgId]);
    $eligibleMeetings = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch all meetings to support editing
$allMeetings = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, meeting_date FROM meetings WHERE org_id = ? ORDER BY meeting_date DESC");
    $stmt->execute([$orgId]);
    $allMeetings = $stmt->fetchAll();
} catch (Exception $e) {}

// AJAX fetch for details
if (isset($_GET['fetch_details'])) {
    $mmId = (int)$_GET['fetch_details'];
    try {
        $stmt = $pdo->prepare("SELECT mm.*, m.title AS meeting_title, m.meeting_date 
                               FROM meeting_minutes mm
                               JOIN meetings m ON mm.meeting_id = m.id
                               WHERE mm.id = ? AND m.org_id = ?");
        $stmt->execute([$mmId, $orgId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            header('Content-Type: application/json');
            echo json_encode($row);
            exit;
        }
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-file-alt me-2" style="color:<?= $moduleColor ?>"></i>Meeting Minutes</h4>
    <p class="text-muted mb-0">Record official resolutions, outline decisions, and assign actionable checklists</p>
  </div>
  <button class="btn" style="background:<?= $moduleColor ?>;color:#fff" data-bs-toggle="modal" data-bs-target="#minModal" onclick="openAdd()"><i class="fas fa-plus me-2"></i>Record Minutes</button>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-file-invoice me-2" style="color:<?= $moduleColor ?>"></i>Recorded Minutes</h6>
    <span class="badge bg-secondary"><?= count($minutesList) ?> documents</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Meeting</th>
            <th>Meeting Date</th>
            <th>Minutes Content Summary</th>
            <th>Action Items Assignment</th>
            <th>Recorded By</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($minutesList)): ?>
          <tr><td colspan="6" class="text-center text-muted py-5"><i class="fas fa-file-signature fa-2x mb-2 d-block"></i>No meeting minutes recorded yet.</td></tr>
          <?php else: foreach ($minutesList as $mm): ?>
          <tr>
            <td class="fw-semibold text-dark"><?= e($mm['meeting_title']) ?></td>
            <td class="fw-semibold text-secondary"><?= formatDate($mm['meeting_date']) ?></td>
            <td>
              <div class="text-muted text-truncate" style="max-width:300px; font-size:0.88rem;"><?= strip_tags($mm['content']) ?></div>
            </td>
            <td>
              <div class="text-muted text-truncate" style="max-width:250px; font-size:0.88rem;"><?= strip_tags($mm['action_items'] ?: 'None') ?></div>
            </td>
            <td><?= e($mm['recorded_by_name'] ?: 'System User') ?></td>
            <td class="text-center">
              <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-info" onclick="viewMinutes(<?= $mm['id'] ?>)" title="View Minutes"><i class="fas fa-eye"></i></button>
                <button class="btn btn-outline-primary" onclick="openEdit(<?= $mm['id'] ?>)" title="Edit"><i class="fas fa-edit"></i></button>
                <button class="btn btn-outline-danger" onclick="delMinutes(<?= $mm['id'] ?>, '<?= e($mm['meeting_title']) ?>')"><i class="fas fa-trash"></i></button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add/Edit Minutes Modal -->
<div class="modal fade" id="minModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content">
  <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" id="minId" value="0">
  <div class="modal-header" style="background:<?= $moduleColor ?>;color:#fff">
    <h5 class="modal-title" id="minTitle"><i class="fas fa-file-alt me-2"></i>Record Minutes</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body">
    <div class="row g-3">
      <div class="col-12" id="eligibleGroup">
        <label class="form-label fw-semibold">Select Meeting <span class="text-danger">*</span></label>
        <select name="meeting_id" id="minMeetingSelect" class="form-select" required>
          <option value="">-- select scheduled meeting --</option>
          <?php foreach ($eligibleMeetings as $em): ?>
          <option value="<?= $em['id'] ?>"><?= e($em['title']) ?> (<?= formatDate($em['meeting_date']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <small class="text-muted">Only meetings without recorded minutes are shown. Select to load detail template.</small>
      </div>
      
      <!-- Hidden/Disabled select for edit view -->
      <div class="col-12" id="editGroup" style="display:none">
        <label class="form-label fw-semibold">Selected Meeting</label>
        <select id="minEditMeetingSelect" class="form-select" disabled>
          <?php foreach ($allMeetings as $am): ?>
          <option value="<?= $am['id'] ?>"><?= e($am['title']) ?> (<?= formatDate($am['meeting_date']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label fw-semibold">Resolutions & Minutes Content <span class="text-danger">*</span></label>
        <textarea name="content" id="minContent" class="form-control" rows="8" required placeholder="Write bulleted minutes, key decisions, updates, and general notes here…"></textarea>
      </div>
      
      <div class="col-12">
        <label class="form-label fw-semibold">Action Items & Deliverables Assignment</label>
        <textarea name="action_items" id="minActionItems" class="form-control" rows="4" placeholder="e.g. 
- John: Complete financial projections by Friday.
- Sarah: Send vendor agreement details."></textarea>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    <button type="submit" class="btn" style="background:<?= $moduleColor ?>;color:#fff"><i class="fas fa-save me-1"></i>Save Minutes</button>
  </div>
  </form>
</div></div></div>

<!-- View Minutes Details Modal -->
<div class="modal fade" id="viewModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
  <div class="modal-header bg-light">
    <h5 class="modal-title fw-bold text-dark"><i class="fas fa-file-signature me-2 text-primary"></i>Official Meeting Minutes</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
  </div>
  <div class="modal-body py-4">
    <div class="d-flex align-items-center justify-content-between border-bottom pb-3 mb-4">
      <div>
        <h4 class="fw-bold mb-1 text-primary" id="vMeetingTitle"></h4>
        <span class="text-muted"><i class="fas fa-calendar-alt me-1"></i>Date of Session: <strong id="vMeetingDate"></strong></span>
      </div>
      <button type="button" class="btn btn-sm btn-outline-secondary" onclick="printMinutes()"><i class="fas fa-print me-1"></i>Print Minutes</button>
    </div>
    
    <div class="mb-4">
      <h6 class="fw-bold text-dark border-bottom pb-1"><i class="fas fa-align-left me-2 text-primary"></i>Discussion & Resolutions</h6>
      <div class="p-3 bg-light rounded text-dark font-monospace" style="white-space: pre-wrap; font-size:0.92rem; line-height: 1.6;" id="vContent"></div>
    </div>
    
    <div>
      <h6 class="fw-bold text-dark border-bottom pb-1"><i class="fas fa-tasks me-2 text-danger"></i>Action Items Checklist</h6>
      <div class="p-3 bg-light rounded text-dark font-monospace" style="white-space: pre-wrap; font-size:0.92rem; line-height: 1.6;" id="vActionItems"></div>
    </div>
  </div>
  <div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
  </div>
</div></div></div>

<form method="POST" id="delMinForm" style="display:none">
  <?= csrfField() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="delMinId">
</form>

<?php
$extraJs = <<<'JS'
<script>
function openAdd() {
  document.getElementById('minTitle').innerHTML = '<i class="fas fa-file-alt me-2"></i>Record Minutes';
  document.getElementById('minId').value = '0';
  document.getElementById('minMeetingSelect').value = '';
  document.getElementById('minContent').value = '';
  document.getElementById('minActionItems').value = '';
  
  document.getElementById('eligibleGroup').style.display = 'block';
  document.getElementById('editGroup').style.display = 'none';
  document.getElementById('minMeetingSelect').required = true;
}
function openEdit(id) {
  fetch('minutes.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('minTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Minutes';
      document.getElementById('minId').value = data.id;
      
      document.getElementById('eligibleGroup').style.display = 'none';
      document.getElementById('editGroup').style.display = 'block';
      document.getElementById('minMeetingSelect').required = false;
      document.getElementById('minEditMeetingSelect').value = data.meeting_id;
      
      // Inject meeting_id as hidden field in form if not eligible select
      let hiddenInput = document.getElementById('minHiddenMeeting');
      if (!hiddenInput) {
        hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'meeting_id';
        hiddenInput.id = 'minHiddenMeeting';
        document.getElementById('minId').after(hiddenInput);
      }
      hiddenInput.value = data.meeting_id;
      
      document.getElementById('minContent').value = data.content;
      document.getElementById('minActionItems').value = data.action_items || '';
      
      new bootstrap.Modal(document.getElementById('minModal')).show();
    });
}
function viewMinutes(id) {
  fetch('minutes.php?fetch_details=' + id)
    .then(r => r.json())
    .then(data => {
      document.getElementById('vMeetingTitle').textContent = data.meeting_title;
      document.getElementById('vMeetingDate').textContent = data.meeting_date;
      document.getElementById('vContent').textContent = data.content;
      document.getElementById('vActionItems').textContent = data.action_items || 'No assignments recorded for this meeting.';
      
      new bootstrap.Modal(document.getElementById('viewModal')).show();
    });
}
function printMinutes() {
  const title = document.getElementById('vMeetingTitle').textContent;
  const date = document.getElementById('vMeetingDate').textContent;
  const content = document.getElementById('vContent').textContent;
  const action = document.getElementById('vActionItems').textContent;
  
  const w = window.open();
  w.document.write(`
    <html>
      <head>
        <title>Minutes - ${title}</title>
        <style>
          body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 40px; color: #333; line-height:1.6; }
          h2 { color: #0B2D4E; margin-bottom: 5px; border-bottom: 2px solid #0B2D4E; padding-bottom: 10px; }
          .date { color: #666; font-size: 0.95rem; margin-bottom: 30px; display:block; }
          h4 { color: #2c3e50; margin-top: 30px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
          pre { white-space: pre-wrap; font-family: inherit; background: #f8f9fa; padding: 15px; border-radius: 4px; }
        </style>
      </head>
      <body>
        <h2>Minutes: ${title}</h2>
        <span class="date">Meeting Date: <strong>${date}</strong></span>
        
        <h4>Discussion & Resolutions</h4>
        <pre>${content}</pre>
        
        <h4>Action Items & Assignments</h4>
        <pre>${action}</pre>
        
        <script>window.print();<\/script>
      </body>
    </html>
  `);
  w.document.close();
}
function delMinutes(id, title) {
  Swal.fire({
    title: 'Delete Minutes?',
    text: 'Permanently remove resolutions and action items recorded for "' + title + '"?',
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#e74c3c',
    confirmButtonText: 'Yes, delete'
  }).then(r => {
    if (r.isConfirmed) {
      document.getElementById('delMinId').value = id;
      document.getElementById('delMinForm').submit();
    }
  });
}
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
