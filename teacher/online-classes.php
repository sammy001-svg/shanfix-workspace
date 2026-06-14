<?php
$pageTitle = 'Online Classes';
require_once __DIR__ . '/../includes/header-teacher.php';

// Load online classes assigned to this teacher
$upcoming = [];
$past     = [];
try {
    $s = $pdo->prepare(
        "SELECT olc.*, c.name AS class_name, sub.name AS subject_name
         FROM sch_online_classes olc
         LEFT JOIN sch_classes c ON c.id = olc.class_id
         LEFT JOIN sch_subjects sub ON sub.id = olc.subject_id
         WHERE olc.org_id=?
           AND (olc.teacher_id=? OR olc.teacher_id IS NULL)
           AND olc.status IN ('scheduled','live','completed')
         ORDER BY olc.scheduled_at DESC
         LIMIT 50"
    );
    $s->execute([$tchOrgId, $tchId]);
    foreach ($s->fetchAll() as $sess) {
        if ($sess['status'] === 'completed') {
            $past[] = $sess;
        } else {
            $upcoming[] = $sess;
        }
    }
} catch (Throwable $e) {}

$platformLabels = ['zoom'=>'Zoom','meet'=>'Google Meet','teams'=>'MS Teams','webex'=>'Webex','other'=>'Other'];
$platformColors = ['zoom'=>'#2D8CFF','meet'=>'#4285F4','teams'=>'#5059C9','webex'=>'#00B2E3','other'=>'#6c757d'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-video me-2" style="color:var(--tch-green)"></i>Online Classes</h5>
    <div class="text-muted small mt-1">Your scheduled virtual lessons &amp; sessions</div>
  </div>
  <a href="<?= APP_URL ?>/modules/school/online-classes.php" class="btn btn-sm btn-outline-success">
    <i class="fas fa-external-link-alt me-1"></i>Manage in Admin
  </a>
</div>

<?php if (empty($upcoming) && empty($past)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-video fa-3x mb-3 d-block opacity-25"></i>
    <h6>No online classes assigned yet</h6>
    <p class="small mb-0">Contact the school administrator to schedule virtual sessions for your classes.</p>
  </div>
</div>

<?php else: ?>

<?php if (!empty($upcoming)): ?>
<div class="mb-2" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-clock me-1"></i>Upcoming &amp; Live
</div>
<div class="row g-3 mb-4">
  <?php foreach ($upcoming as $sess):
    $isLive = $sess['status'] === 'live';
    $plt    = $sess['platform'] ?? 'meet';
    $pColor = $platformColors[$plt] ?? '#6c757d';
    $pLabel = $platformLabels[$plt] ?? 'Other';
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100" style="<?= $isLive ? 'border-left:4px solid #27ae60!important' : '' ?>">
      <div class="card-body">
        <?php if ($isLive): ?>
        <div class="d-flex align-items-center gap-2 mb-2">
          <span class="badge bg-success" style="font-size:.65rem">
            <span class="d-inline-block rounded-circle me-1" style="width:6px;height:6px;background:#fff"></span>LIVE NOW
          </span>
        </div>
        <?php endif; ?>
        <h6 class="fw-bold mb-2"><?= e($sess['title']) ?></h6>
        <div class="d-flex flex-wrap gap-3 text-muted small mb-2">
          <?php if (!empty($sess['class_name'])): ?><span><i class="fas fa-chalkboard me-1"></i><?= e($sess['class_name']) ?></span><?php endif; ?>
          <?php if (!empty($sess['subject_name'])): ?><span><i class="fas fa-book me-1"></i><?= e($sess['subject_name']) ?></span><?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-3 text-muted small mb-3">
          <span><i class="fas fa-calendar-day me-1"></i><?= date('D, d M Y', strtotime($sess['scheduled_at'])) ?></span>
          <span><i class="fas fa-clock me-1"></i><?= date('H:i', strtotime($sess['scheduled_at'])) ?></span>
          <span><?= $sess['duration_mins'] ?> min</span>
        </div>
        <?php if (!empty($sess['description'])): ?>
        <p class="text-muted small mb-3"><?= nl2br(e($sess['description'])) ?></p>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap align-items-center">
          <?php if (!empty($sess['meeting_url'])): ?>
          <a href="<?= e($sess['meeting_url']) ?>" target="_blank"
             class="btn btn-sm text-white" style="background:<?= $pColor ?>">
            <i class="fas fa-video me-1"></i>Start / Host Session
          </a>
          <?php else: ?>
          <span class="btn btn-sm btn-outline-secondary disabled">No meeting link set</span>
          <?php endif; ?>
          <?php if (!empty($sess['meeting_id'])): ?>
          <span class="badge bg-light text-dark border small">
            <?= $pLabel ?> ID: <?= e($sess['meeting_id']) ?>
            <?php if (!empty($sess['meeting_pass'])): ?> · <?= e($sess['meeting_pass']) ?><?php endif; ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($sess['notes'])): ?>
        <div class="mt-2 p-2 bg-light rounded small text-muted">
          <i class="fas fa-sticky-note me-1"></i><?= nl2br(e($sess['notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($past)): ?>
<div class="mb-2 mt-2" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-history me-1"></i>Past Sessions
</div>
<div class="card border-0 shadow-sm">
  <div class="card-body p-0">
    <?php foreach ($past as $sess):
      $plt = $sess['platform'] ?? 'meet';
      $pColor = $platformColors[$plt] ?? '#6c757d';
    ?>
    <div class="d-flex align-items-center gap-3 px-4 py-3 border-bottom">
      <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
           style="width:36px;height:36px;background:<?= $pColor ?>1a">
        <i class="fas fa-video" style="color:<?= $pColor ?>;font-size:.85rem"></i>
      </div>
      <div class="flex-grow-1">
        <div class="fw-semibold small"><?= e($sess['title']) ?></div>
        <div class="text-muted" style="font-size:.75rem">
          <?= !empty($sess['class_name']) ? e($sess['class_name']).' · ' : '' ?>
          <?= date('d M Y', strtotime($sess['scheduled_at'])) ?>
          &middot; <?= $sess['duration_mins'] ?>min
        </div>
      </div>
      <?php if (!empty($sess['recorded_url'])): ?>
      <a href="<?= e($sess['recorded_url']) ?>" target="_blank" class="btn btn-xs btn-outline-secondary">
        <i class="fas fa-play me-1"></i>Recording
      </a>
      <?php endif; ?>
      <span class="badge bg-secondary bg-opacity-25 text-secondary flex-shrink-0" style="font-size:.65rem">Completed</span>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-teacher.php'; ?>
