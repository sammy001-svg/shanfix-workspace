<?php
require_once __DIR__ . '/_nav.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf(); denyIfReadOnly($moduleSlug);
    $user = currentUser(); $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save_session') {
        $id          = (int)($_POST['id'] ?? 0);
        $classId     = (int)($_POST['class_id']   ?? 0) ?: null;
        $subjectId   = (int)($_POST['subject_id'] ?? 0) ?: null;
        $teacherId   = (int)($_POST['teacher_id'] ?? 0) ?: null;
        $title       = sanitize($_POST['title']        ?? '');
        $description = sanitize($_POST['description']  ?? '');
        $platform    = in_array($_POST['platform'] ?? '', ['zoom','meet','teams','webex','other']) ? $_POST['platform'] : 'meet';
        $meetingUrl  = sanitize($_POST['meeting_url']  ?? '');
        $meetingId   = sanitize($_POST['meeting_id']   ?? '');
        $meetingPass = sanitize($_POST['meeting_pass'] ?? '');
        $scheduledAt = sanitize($_POST['scheduled_at'] ?? '');
        $duration    = max(1, (int)($_POST['duration_mins'] ?? 60));
        $notes       = sanitize($_POST['notes']   ?? '');
        $status      = in_array($_POST['status'] ?? '', ['scheduled','live','completed','cancelled']) ? $_POST['status'] : 'scheduled';

        if (!$title)       { setFlash('error', 'Session title is required.');      redirect('online-classes.php'); }
        if (!$scheduledAt) { setFlash('error', 'Scheduled date/time is required.'); redirect('online-classes.php'); }

        if ($id) {
            $pdo->prepare(
                "UPDATE sch_online_classes
                 SET class_id=?,subject_id=?,teacher_id=?,title=?,description=?,platform=?,
                     meeting_url=?,meeting_id=?,meeting_pass=?,scheduled_at=?,duration_mins=?,
                     notes=?,status=?
                 WHERE id=? AND org_id=?"
            )->execute([$classId,$subjectId,$teacherId,$title,$description,$platform,
                        $meetingUrl,$meetingId,$meetingPass,$scheduledAt,$duration,
                        $notes,$status,$id,$orgId]);
            setFlash('success', 'Session updated.');
        } else {
            $uid = (int)$user['id'];
            $pdo->prepare(
                "INSERT INTO sch_online_classes
                 (org_id,class_id,subject_id,teacher_id,title,description,platform,
                  meeting_url,meeting_id,meeting_pass,scheduled_at,duration_mins,notes,status,created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            )->execute([$orgId,$classId,$subjectId,$teacherId,$title,$description,$platform,
                        $meetingUrl,$meetingId,$meetingPass,$scheduledAt,$duration,$notes,$status,$uid]);
            setFlash('success', 'Online class session created.');
        }
        redirect('online-classes.php');
    }

    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (in_array($status, ['scheduled','live','completed','cancelled'])) {
            $pdo->prepare("UPDATE sch_online_classes SET status=? WHERE id=? AND org_id=?")->execute([$status,$id,$orgId]);
            setFlash('success', 'Status updated.');
        }
        redirect('online-classes.php');
    }

    if ($action === 'delete_session') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM sch_online_classes WHERE id=? AND org_id=?")->execute([$id,$orgId]);
        setFlash('success', 'Session deleted.');
        redirect('online-classes.php');
    }
}
require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser(); $orgId = (int)$user['org_id'];

// ── Dropdown data ──────────────────────────────────────────
$classes = $subjects = $teachers = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? ORDER BY name"); $s->execute([$orgId]); $classes=$s->fetchAll(); } catch(Throwable $e) {}
try { $s=$pdo->prepare("SELECT id,name FROM sch_subjects WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $subjects=$s->fetchAll(); } catch(Throwable $e) {}
try { $s=$pdo->prepare("SELECT id,CONCAT(first_name,' ',last_name) AS name FROM sch_teachers WHERE org_id=? AND status='active' ORDER BY first_name"); $s->execute([$orgId]); $teachers=$s->fetchAll(); } catch(Throwable $e) {}

// ── Filters ───────────────────────────────────────────────
$filterStatus  = $_GET['status'] ?? 'all';
$filterClassId = (int)($_GET['class_id'] ?? 0);

// ── Sessions with joins ───────────────────────────────────
$sessions = [];
try {
    $where = ['olc.org_id=?']; $params = [$orgId];
    if (in_array($filterStatus, ['scheduled','live','completed','cancelled'])) {
        $where[] = 'olc.status=?'; $params[] = $filterStatus;
    }
    if ($filterClassId) { $where[] = 'olc.class_id=?'; $params[] = $filterClassId; }
    $s = $pdo->prepare(
        "SELECT olc.*, c.name AS class_name, sub.name AS subject_name,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name
         FROM sch_online_classes olc
         LEFT JOIN sch_classes c ON c.id = olc.class_id
         LEFT JOIN sch_subjects sub ON sub.id = olc.subject_id
         LEFT JOIN sch_teachers t ON t.id = olc.teacher_id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY olc.scheduled_at DESC"
    );
    $s->execute($params); $sessions = $s->fetchAll();
} catch(Throwable $e) {}

// ── KPI counts ────────────────────────────────────────────
$counts = ['scheduled'=>0,'live'=>0,'completed'=>0,'cancelled'=>0,'total'=>0];
try {
    $s=$pdo->prepare("SELECT status,COUNT(*) AS cnt FROM sch_online_classes WHERE org_id=? GROUP BY status");
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) { $counts[$r['status']] = (int)$r['cnt']; $counts['total'] += (int)$r['cnt']; }
} catch(Throwable $e) {}

$platformLabels = ['zoom'=>'Zoom','meet'=>'Google Meet','teams'=>'MS Teams','webex'=>'Webex','other'=>'Other'];
$statusColors   = ['scheduled'=>'primary','live'=>'success','completed'=>'secondary','cancelled'=>'danger'];
?>
<?= flashAlert() ?>
<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-video me-2" style="color:<?= $moduleColor ?>"></i>Online Classes</h4>
    <p class="text-muted mb-0">Schedule and manage virtual class sessions for students and teachers</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#sessionModal">
    <i class="fas fa-plus me-2"></i>New Session
  </button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-video"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['total'] ?></div><div class="stat-label">Total Sessions</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-circle" style="font-size:.6rem"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['live'] ?></div><div class="stat-label">Live Now</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-clock"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['scheduled'] ?></div><div class="stat-label">Scheduled</div></div></div></div>
  <div class="col-6 col-md-3"><div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-check-circle"></i></div><div class="stat-body"><div class="stat-value"><?= $counts['completed'] ?></div><div class="stat-label">Completed</div></div></div></div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body py-2">
    <form method="GET" class="row g-2 align-items-center">
      <div class="col-auto">
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="all" <?= $filterStatus==='all'?'selected':'' ?>>All Status</option>
          <?php foreach (['scheduled'=>'Scheduled','live'=>'Live','completed'=>'Completed','cancelled'=>'Cancelled'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= $filterStatus===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto">
        <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
          <option value="">All Classes</option>
          <?php foreach ($classes as $c): ?>
          <option value="<?= $c['id'] ?>" <?= $filterClassId==$c['id']?'selected':'' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($filterStatus !== 'all' || $filterClassId): ?>
      <div class="col-auto"><a href="online-classes.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-times me-1"></i>Clear</a></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header"><h6 class="mb-0"><i class="fas fa-list me-2" style="color:<?= $moduleColor ?>"></i>Sessions (<?= count($sessions) ?>)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr><th>Session</th><th>Class / Subject</th><th>Teacher</th><th>Scheduled</th><th>Platform</th><th>Status</th><th class="text-end">Actions</th></tr>
        </thead>
        <tbody>
          <?php if (empty($sessions)): ?>
          <tr><td colspan="7" class="text-center text-muted py-5">
            <i class="fas fa-video fa-2x d-block mb-2 opacity-25"></i>No online class sessions yet. Create the first one!
          </td></tr>
          <?php else: foreach ($sessions as $sess):
            $sc  = $statusColors[$sess['status']] ?? 'secondary';
            $plt = $sess['platform'] ?? 'meet';
          ?>
          <tr>
            <td style="max-width:220px">
              <div class="fw-semibold"><?= e($sess['title']) ?></div>
              <?php if (!empty($sess['description'])): ?>
              <div class="text-muted small"><?= e(mb_strimwidth($sess['description'], 0, 55, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <div><?= e($sess['class_name'] ?? '—') ?></div>
              <div class="text-muted"><?= e($sess['subject_name'] ?? '') ?></div>
            </td>
            <td class="small text-muted"><?= e($sess['teacher_name'] ?? '—') ?></td>
            <td class="small">
              <div><?= $sess['scheduled_at'] ? date('d M Y', strtotime($sess['scheduled_at'])) : '—' ?></div>
              <div class="text-muted"><?= $sess['scheduled_at'] ? date('H:i', strtotime($sess['scheduled_at'])) : '' ?> · <?= $sess['duration_mins'] ?>min</div>
            </td>
            <td class="small"><?= e($platformLabels[$plt] ?? 'Other') ?></td>
            <td><span class="badge bg-<?= $sc ?>"><?= ucfirst($sess['status']) ?></span></td>
            <td class="text-end text-nowrap">
              <?php if (!empty($sess['meeting_url'])): ?>
              <a href="<?= e($sess['meeting_url']) ?>" target="_blank" class="btn btn-xs btn-success me-1" title="Open Meeting">
                <i class="fas fa-external-link-alt"></i>
              </a>
              <?php endif; ?>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="id" value="<?= $sess['id'] ?>">
                <select name="status" class="form-select form-select-sm d-inline-block w-auto me-1" style="font-size:.75rem" onchange="this.form.submit()">
                  <?php foreach (['scheduled'=>'Scheduled','live'=>'Live','completed'=>'Completed','cancelled'=>'Cancelled'] as $v=>$l): ?>
                  <option value="<?= $v ?>" <?= $sess['status']===$v?'selected':'' ?>><?= $l ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
              <button class="btn btn-xs btn-outline-secondary me-1 btn-edit-session"
                data-id="<?= $sess['id'] ?>"
                data-class_id="<?= $sess['class_id'] ?? '' ?>"
                data-subject_id="<?= $sess['subject_id'] ?? '' ?>"
                data-teacher_id="<?= $sess['teacher_id'] ?? '' ?>"
                data-title="<?= e($sess['title']) ?>"
                data-description="<?= e($sess['description'] ?? '') ?>"
                data-platform="<?= $sess['platform'] ?? 'meet' ?>"
                data-meeting_url="<?= e($sess['meeting_url'] ?? '') ?>"
                data-meeting_id="<?= e($sess['meeting_id'] ?? '') ?>"
                data-meeting_pass="<?= e($sess['meeting_pass'] ?? '') ?>"
                data-scheduled_at="<?= $sess['scheduled_at'] ? date('Y-m-d\TH:i', strtotime($sess['scheduled_at'])) : '' ?>"
                data-duration="<?= (int)$sess['duration_mins'] ?>"
                data-notes="<?= e($sess['notes'] ?? '') ?>"
                data-status="<?= $sess['status'] ?>">
                <i class="fas fa-edit"></i>
              </button>
              <form method="POST" class="d-inline">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_session">
                <input type="hidden" name="id" value="<?= $sess['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger btn-confirm" data-msg="Delete this session permanently?">
                  <i class="fas fa-trash"></i>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Create / Edit Modal -->
<div class="modal fade" id="sessionModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="fas fa-video me-2"></i><span id="sessionModalTitle">Schedule Online Class</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST"><div class="modal-body">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_session">
        <input type="hidden" name="id" id="sessId" value="0">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label fw-semibold">Session Title <span class="text-danger">*</span></label>
            <input type="text" name="title" id="sessTitle" class="form-control" placeholder="e.g. Mathematics – Algebra Introduction" required>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Class</label>
            <select name="class_id" id="sessClass" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Subject</label>
            <select name="subject_id" id="sessSubject" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Teacher</label>
            <select name="teacher_id" id="sessTeacher" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Scheduled Date &amp; Time <span class="text-danger">*</span></label>
            <input type="datetime-local" name="scheduled_at" id="sessScheduled" class="form-control" required>
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Duration (min)</label>
            <input type="number" name="duration_mins" id="sessDuration" class="form-control" value="60" min="1" max="480">
          </div>
          <div class="col-md-3">
            <label class="form-label fw-semibold">Status</label>
            <select name="status" id="sessStatus" class="form-select">
              <option value="scheduled">Scheduled</option>
              <option value="live">Live</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label fw-semibold">Platform</label>
            <select name="platform" id="sessPlatform" class="form-select">
              <option value="meet">Google Meet</option>
              <option value="zoom">Zoom</option>
              <option value="teams">Microsoft Teams</option>
              <option value="webex">Cisco Webex</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label fw-semibold">Meeting Link / URL</label>
            <input type="url" name="meeting_url" id="sessMeetUrl" class="form-control" placeholder="https://meet.google.com/abc-defg-hij">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Meeting ID <span class="text-muted small">(optional)</span></label>
            <input type="text" name="meeting_id" id="sessMeetId" class="form-control" placeholder="123 456 7890">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Passcode <span class="text-muted small">(optional)</span></label>
            <input type="text" name="meeting_pass" id="sessMeetPass" class="form-control" placeholder="Optional password">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Description / Instructions</label>
            <textarea name="description" id="sessDesc" class="form-control" rows="2" placeholder="Topics covered, preparation instructions…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>">Save Session</button>
      </div>
      </form>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
document.querySelectorAll('.btn-edit-session').forEach(btn => {
  btn.addEventListener('click', function() {
    document.getElementById('sessionModalTitle').textContent = 'Edit Online Class';
    document.getElementById('sessId').value        = this.dataset.id;
    document.getElementById('sessClass').value     = this.dataset.class_id    || '';
    document.getElementById('sessSubject').value   = this.dataset.subject_id  || '';
    document.getElementById('sessTeacher').value   = this.dataset.teacher_id  || '';
    document.getElementById('sessTitle').value     = this.dataset.title       || '';
    document.getElementById('sessScheduled').value = this.dataset.scheduled_at|| '';
    document.getElementById('sessDuration').value  = this.dataset.duration    || 60;
    document.getElementById('sessStatus').value    = this.dataset.status      || 'scheduled';
    document.getElementById('sessPlatform').value  = this.dataset.platform    || 'meet';
    document.getElementById('sessMeetUrl').value   = this.dataset.meeting_url || '';
    document.getElementById('sessMeetId').value    = this.dataset.meeting_id  || '';
    document.getElementById('sessMeetPass').value  = this.dataset.meeting_pass|| '';
    document.getElementById('sessNotes') && (document.getElementById('sessNotes').value = this.dataset.notes || '');
    const d = document.createElement('textarea');
    d.innerHTML = this.dataset.description || '';
    document.getElementById('sessDesc').value = d.value;
    new bootstrap.Modal(document.getElementById('sessionModal')).show();
  });
});
document.querySelectorAll('.btn-confirm').forEach(btn => {
  btn.addEventListener('click', function(e) { if (!confirm(this.dataset.msg || 'Are you sure?')) e.preventDefault(); });
});
document.getElementById('sessionModal').addEventListener('hidden.bs.modal', function() {
  document.getElementById('sessionModalTitle').textContent = 'Schedule Online Class';
  document.getElementById('sessId').value = '0';
  this.querySelector('form').reset();
  document.getElementById('sessDuration').value = '60';
  document.getElementById('sessPlatform').value = 'meet';
  document.getElementById('sessStatus').value   = 'scheduled';
});
</script>
<?php $extraJs = ob_get_clean(); require_once __DIR__ . '/../../includes/footer.php'; ?>
