<?php
$pageTitle = 'Homework';
require_once __DIR__ . '/../includes/header-parent.php';

// Get active student's class_id
$classId = (int)($activeStudent['class_id'] ?? 0);

$homework = [];
if ($classId) {
    try {
        $s = $pdo->prepare(
            "SELECT hw.*,
                    sub.name AS subject_name,
                    CONCAT(t.first_name,' ',t.last_name) AS teacher_name,
                    COALESCE(
                        (SELECT status FROM sch_homework_submissions
                         WHERE homework_id=hw.id AND student_id=? LIMIT 1),
                        'pending'
                    ) AS my_status,
                    (SELECT marks_obtained FROM sch_homework_submissions
                     WHERE homework_id=hw.id AND student_id=? LIMIT 1) AS my_marks
             FROM sch_homework hw
             LEFT JOIN sch_subjects sub  ON hw.subject_id  = sub.id
             LEFT JOIN sch_teachers t    ON hw.teacher_id  = t.id
             WHERE hw.class_id=? AND hw.org_id=? AND hw.status='active'
             ORDER BY hw.due_date ASC, hw.created_at DESC"
        );
        $s->execute([$parActive, $parActive, $classId, $parOrgId]);
        $homework = $s->fetchAll();
    } catch (Throwable $e) {}
}

$completedHw = [];
if ($classId) {
    try {
        $s = $pdo->prepare(
            "SELECT hw.*,
                    sub.name AS subject_name,
                    COALESCE(
                        (SELECT status FROM sch_homework_submissions
                         WHERE homework_id=hw.id AND student_id=? LIMIT 1),
                        'pending'
                    ) AS my_status,
                    (SELECT marks_obtained FROM sch_homework_submissions
                     WHERE homework_id=hw.id AND student_id=? LIMIT 1) AS my_marks
             FROM sch_homework hw
             LEFT JOIN sch_subjects sub ON hw.subject_id = sub.id
             WHERE hw.class_id=? AND hw.org_id=? AND hw.status='closed'
             ORDER BY hw.due_date DESC LIMIT 10"
        );
        $s->execute([$parActive, $parActive, $classId, $parOrgId]);
        $completedHw = $s->fetchAll();
    } catch (Throwable $e) {}
}

$statusLabels = [
    'pending'   => ['label' => 'Pending',   'cls' => 'bg-warning text-dark'],
    'submitted' => ['label' => 'Submitted', 'cls' => 'bg-success text-white'],
    'late'      => ['label' => 'Late',      'cls' => 'bg-danger text-white'],
    'missing'   => ['label' => 'Missing',   'cls' => 'bg-danger text-white'],
];

$today = date('Y-m-d');
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-book-open me-2" style="color:var(--par-green)"></i>Homework</h5>
  <?php if ($activeStudent): ?>
  <span class="badge bg-secondary ms-2"><?= e($activeStudent['class_name'] ?? '') ?></span>
  <?php endif; ?>
</div>

<?php if (!$classId): ?>
<div class="alert alert-info">Student is not assigned to a class yet.</div>
<?php elseif (empty($homework)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body text-center py-5">
    <i class="fas fa-check-circle fa-3x text-success mb-3 opacity-50"></i>
    <p class="fw-semibold mb-1">No pending homework</p>
    <p class="text-muted small">All caught up! New assignments will appear here when set by teachers.</p>
  </div>
</div>
<?php else: ?>

<!-- Active homework -->
<div class="mb-4">
  <h6 class="text-muted text-uppercase fw-bold small mb-3" style="letter-spacing:.5px">
    Active Assignments (<?= count($homework) ?>)
  </h6>
  <div class="row g-3">
    <?php foreach ($homework as $hw):
      $isDue     = $hw['due_date'] && $hw['due_date'] < $today;
      $isDueToday = $hw['due_date'] === $today;
      $myStatus   = $hw['my_status'] ?? 'pending';
      $statusInfo = $statusLabels[$myStatus] ?? $statusLabels['pending'];
    ?>
    <div class="col-md-6">
      <div class="card border-0 shadow-sm h-100"
           style="border-left: 3px solid <?= $isDue && $myStatus==='pending' ? '#e74c3c' : 'var(--par-green)' ?> !important">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <span class="badge bg-light text-dark border" style="font-size:.7rem">
              <?= e($hw['subject_name'] ?? 'General') ?>
            </span>
            <span class="badge <?= $statusInfo['cls'] ?>" style="font-size:.7rem">
              <?= $statusInfo['label'] ?>
            </span>
          </div>
          <h6 class="fw-bold mb-1" style="font-size:.9rem"><?= e($hw['title']) ?></h6>
          <?php if ($hw['description']): ?>
          <p class="text-muted small mb-2" style="line-height:1.5"><?= nl2br(e($hw['description'])) ?></p>
          <?php endif; ?>
          <div class="d-flex align-items-center gap-3 mt-2" style="font-size:.75rem;color:#6c757d">
            <?php if ($hw['due_date']): ?>
            <span class="<?= $isDue && $myStatus==='pending' ? 'text-danger fw-semibold' : ($isDueToday ? 'text-warning fw-semibold' : '') ?>">
              <i class="fas fa-calendar-alt me-1"></i>
              Due: <?= date('d M Y', strtotime($hw['due_date'])) ?>
              <?php if ($isDue && $myStatus==='pending'): ?>
              <span class="badge bg-danger ms-1" style="font-size:.65rem">Overdue</span>
              <?php elseif ($isDueToday): ?>
              <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">Today</span>
              <?php endif; ?>
            </span>
            <?php endif; ?>
            <?php if ($hw['max_marks']): ?>
            <span><i class="fas fa-star me-1"></i>Max: <?= (int)$hw['max_marks'] ?> marks</span>
            <?php endif; ?>
            <?php if ($hw['my_marks'] !== null): ?>
            <span class="text-success fw-semibold">
              <i class="fas fa-check me-1"></i>Scored: <?= (float)$hw['my_marks'] ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($completedHw)): ?>
<!-- Closed / recent homework -->
<div>
  <h6 class="text-muted text-uppercase fw-bold small mb-3" style="letter-spacing:.5px">
    Recent Past Assignments
  </h6>
  <div class="card border-0 shadow-sm">
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table mb-0">
          <thead class="table-light">
            <tr>
              <th>Assignment</th>
              <th>Subject</th>
              <th>Due Date</th>
              <th class="text-center">My Status</th>
              <th class="text-end">Marks</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($completedHw as $hw):
              $myStatus   = $hw['my_status'] ?? 'pending';
              $statusInfo = $statusLabels[$myStatus] ?? $statusLabels['pending'];
            ?>
            <tr>
              <td class="small fw-semibold"><?= e($hw['title']) ?></td>
              <td class="small text-muted"><?= e($hw['subject_name'] ?? '—') ?></td>
              <td class="small"><?= $hw['due_date'] ? date('d M Y', strtotime($hw['due_date'])) : '—' ?></td>
              <td class="text-center">
                <span class="badge <?= $statusInfo['cls'] ?>" style="font-size:.7rem"><?= $statusInfo['label'] ?></span>
              </td>
              <td class="text-end small">
                <?php if ($hw['my_marks'] !== null): ?>
                <span class="text-success fw-semibold"><?= (float)$hw['my_marks'] ?> / <?= (int)$hw['max_marks'] ?></span>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
