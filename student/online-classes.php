<?php
$pageTitle = 'Online Classes';
require_once __DIR__ . '/../includes/header-student.php';

// Load upcoming online classes for this student's class
$upcoming = [];
$past     = [];
try {
    $s = $pdo->prepare(
        "SELECT olc.*, sub.name AS subject_name,
                CONCAT(t.first_name,' ',t.last_name) AS teacher_name
         FROM sch_online_classes olc
         LEFT JOIN sch_subjects sub ON sub.id = olc.subject_id
         LEFT JOIN sch_teachers t   ON t.id   = olc.teacher_id
         WHERE olc.org_id=?
           AND (olc.class_id IS NULL OR olc.class_id=?)
           AND olc.status IN ('scheduled','live','completed')
         ORDER BY olc.scheduled_at DESC
         LIMIT 50"
    );
    $s->execute([$stuOrgId, $stuClassId]);
    $all = $s->fetchAll();
    foreach ($all as $sess) {
        if ($sess['status'] === 'completed') {
            $past[] = $sess;
        } else {
            $upcoming[] = $sess;
        }
    }
} catch (Throwable $e) {}

$platformIcons  = ['zoom'=>'fab fa-youtube','meet'=>'fab fa-google','teams'=>'fas fa-comments','webex'=>'fas fa-video','other'=>'fas fa-link'];
$platformLabels = ['zoom'=>'Zoom','meet'=>'Google Meet','teams'=>'MS Teams','webex'=>'Webex','other'=>'Other'];
$platformColors = ['zoom'=>'#2D8CFF','meet'=>'#4285F4','teams'=>'#5059C9','webex'=>'#00B2E3','other'=>'#6c757d'];
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h5 class="fw-bold mb-0"><i class="fas fa-video me-2" style="color:var(--stu-blue)"></i>Online Classes</h5>
    <div class="text-muted small mt-1">Your virtual class sessions &amp; live lessons</div>
  </div>
</div>

<?php if (empty($upcoming) && empty($past)): ?>
<div class="card border-0 shadow-sm text-center py-5">
  <div class="card-body text-muted">
    <i class="fas fa-video fa-3x mb-3 d-block opacity-25"></i>
    <h6>No online classes scheduled yet</h6>
    <p class="small mb-0">Your teacher will publish virtual class sessions here. Check back soon!</p>
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
    $plt = $sess['platform'] ?? 'meet';
    $pColor = $platformColors[$plt] ?? '#6c757d';
    $pIcon  = $platformIcons[$plt]  ?? 'fas fa-video';
    $pLabel = $platformLabels[$plt] ?? 'Other';
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm h-100" style="<?= $isLive ? 'border-left:4px solid #27ae60!important' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
          <div>
            <?php if ($isLive): ?>
            <span class="badge bg-success mb-1" style="font-size:.65rem">
              <span class="d-inline-block rounded-circle me-1" style="width:6px;height:6px;background:#fff"></span>LIVE NOW
            </span>
            <?php else: ?>
            <span class="badge bg-primary bg-opacity-25 text-primary mb-1" style="font-size:.65rem">Upcoming</span>
            <?php endif; ?>
            <h6 class="fw-bold mb-0"><?= e($sess['title']) ?></h6>
          </div>
          <i class="<?= $pIcon ?>" style="color:<?= $pColor ?>;font-size:1.4rem;flex-shrink:0"></i>
        </div>
        <div class="d-flex gap-3 text-muted small mb-2">
          <?php if (!empty($sess['subject_name'])): ?>
          <span><i class="fas fa-book me-1"></i><?= e($sess['subject_name']) ?></span>
          <?php endif; ?>
          <?php if (!empty($sess['teacher_name'])): ?>
          <span><i class="fas fa-chalkboard-teacher me-1"></i><?= e($sess['teacher_name']) ?></span>
          <?php endif; ?>
        </div>
        <div class="d-flex gap-3 text-muted small mb-3">
          <span><i class="fas fa-calendar-day me-1"></i><?= date('D, d M Y', strtotime($sess['scheduled_at'])) ?></span>
          <span><i class="fas fa-clock me-1"></i><?= date('H:i', strtotime($sess['scheduled_at'])) ?></span>
          <span><?= $sess['duration_mins'] ?> min</span>
        </div>
        <?php if (!empty($sess['description'])): ?>
        <p class="text-muted small mb-3"><?= nl2br(e($sess['description'])) ?></p>
        <?php endif; ?>
        <div class="d-flex gap-2 flex-wrap">
          <?php if (!empty($sess['meeting_url'])): ?>
          <a href="<?= e($sess['meeting_url']) ?>" target="_blank"
             class="btn btn-sm text-white"
             style="background:<?= $pColor ?>">
            <i class="fas fa-video me-1"></i>Join Class
          </a>
          <?php else: ?>
          <span class="btn btn-sm btn-outline-secondary disabled">Link not yet available</span>
          <?php endif; ?>
          <?php if (!empty($sess['meeting_id'])): ?>
          <span class="badge bg-light text-dark border align-self-center small">
            ID: <?= e($sess['meeting_id']) ?>
            <?php if (!empty($sess['meeting_pass'])): ?> · Pass: <?= e($sess['meeting_pass']) ?><?php endif; ?>
          </span>
          <?php endif; ?>
        </div>
        <?php if (!empty($sess['notes'])): ?>
        <div class="mt-2 p-2 bg-light rounded small text-muted">
          <i class="fas fa-info-circle me-1"></i><?= nl2br(e($sess['notes'])) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!empty($past)): ?>
<div class="mb-2 mt-4" style="font-size:.7rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.5px">
  <i class="fas fa-history me-1"></i>Past Sessions
</div>
<div class="row g-3">
  <?php foreach ($past as $sess):
    $plt = $sess['platform'] ?? 'meet';
    $pColor = $platformColors[$plt] ?? '#6c757d';
    $pLabel = $platformLabels[$plt] ?? 'Other';
  ?>
  <div class="col-12 col-md-6">
    <div class="card border-0 shadow-sm">
      <div class="card-body py-3">
        <div class="d-flex align-items-start gap-3">
          <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
               style="width:40px;height:40px;background:<?= $pColor ?>1a">
            <i class="fas fa-video" style="color:<?= $pColor ?>;font-size:.9rem"></i>
          </div>
          <div class="flex-grow-1">
            <div class="fw-semibold small"><?= e($sess['title']) ?></div>
            <div class="text-muted" style="font-size:.75rem">
              <?= !empty($sess['subject_name']) ? e($sess['subject_name']).' &middot; ' : '' ?>
              <?= date('d M Y', strtotime($sess['scheduled_at'])) ?>
            </div>
            <?php if (!empty($sess['recorded_url'])): ?>
            <a href="<?= e($sess['recorded_url']) ?>" target="_blank" class="btn btn-xs btn-outline-secondary mt-1">
              <i class="fas fa-play me-1"></i>Watch Recording
            </a>
            <?php endif; ?>
          </div>
          <span class="badge bg-secondary bg-opacity-25 text-secondary flex-shrink-0" style="font-size:.65rem">Completed</span>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
