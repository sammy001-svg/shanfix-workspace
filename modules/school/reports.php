<?php
require_once __DIR__ . '/_nav.php';

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// General aggregated metrics
$totalStudents = countRows('sch_students', 'org_id = ?', [$orgId]);
$activeStudents = countRows('sch_students', "org_id = ? AND status = 'active'", [$orgId]);
$graduatedStudents = countRows('sch_students', "org_id = ? AND status = 'graduated'", [$orgId]);
$transferredStudents = countRows('sch_students', "org_id = ? AND status = 'transferred'", [$orgId]);

// Gender breakdown
$maleCount = countRows('sch_students', "org_id = ? AND gender = 'male'", [$orgId]);
$femaleCount = countRows('sch_students', "org_id = ? AND gender = 'female'", [$orgId]);

// Total Invoice billing amount vs Collections
$totalInvoiced = 0;
$totalCollected = 0;
$outstandingFees = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0), COALESCE(SUM(paid),0), COALESCE(SUM(balance),0) FROM sch_fees WHERE org_id = ?");
    $stmt->execute([$orgId]);
    $row = $stmt->fetch();
    $totalInvoiced = (float)($row[0] ?? 0);
    $totalCollected = (float)($row[1] ?? 0);
    $outstandingFees = (float)($row[2] ?? 0);
} catch (Exception $e) {}

// Grade Distribution statistics
$gradesDistribution = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
try {
    $stmt = $pdo->prepare("SELECT grade, COUNT(*) AS count FROM sch_grades WHERE org_id = ? GROUP BY grade");
    $stmt->execute([$orgId]);
    foreach ($stmt->fetchAll() as $row) {
        $g = strtoupper($row['grade']);
        if (array_key_exists($g, $gradesDistribution)) {
            $gradesDistribution[$g] = (int)$row['count'];
        }
    }
} catch (Exception $e) {}

// Top Performing Subjects
$subjectAverages = [];
try {
    $stmt = $pdo->prepare("SELECT sub.name AS subject_name, sub.code, AVG(g.total_score) AS avg_score 
                           FROM sch_grades g 
                           JOIN sch_subjects sub ON g.subject_id = sub.id
                           WHERE g.org_id = ? 
                           GROUP BY g.subject_id 
                           ORDER BY avg_score DESC 
                           LIMIT 5");
    $stmt->execute([$orgId]);
    $subjectAverages = $stmt->fetchAll();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-bar me-2" style="color:<?= $moduleColor ?>"></i>School Performance & Fee Reports</h4>
    <p class="text-muted mb-0">Review student demographics, exam grading metrics and collection pipelines</p>
  </div>
  <button class="btn btn-outline-secondary" onclick="window.print()"><i class="fas fa-print me-2"></i>Print Report Card</button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-user-graduate"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= $totalStudents ?></div>
        <div class="stat-label">Total Enrollment</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon navy-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalInvoiced) ?></div>
        <div class="stat-label">Total Invoiced</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon green-bg"><i class="fas fa-check-double"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($totalCollected) ?></div>
        <div class="stat-label">Total Fees Collected</div>
      </div>
    </div>
  </div>
  <div class="col-sm-6 col-md-3">
    <div class="stat-card">
      <div class="stat-icon danger-bg"><i class="fas fa-exclamation-circle"></i></div>
      <div class="stat-body">
        <div class="stat-value"><?= formatCurrency($outstandingFees) ?></div>
        <div class="stat-label">Outstanding Arrears</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <!-- Grade Performance Distribution -->
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-award me-2 text-success"></i>Score Book Grade Distribution</h6></div>
      <div class="card-body">
        <div style="height:300px;"><canvas id="gradeDistChart"></canvas></div>
      </div>
    </div>
  </div>
  
  <!-- Demographics (Gender) Doughnut -->
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-venus-mars me-2 text-success"></i>Student Gender Demographics</h6></div>
      <div class="card-body d-flex flex-column justify-content-center">
        <div style="height:220px;"><canvas id="genderChart"></canvas></div>
        <div class="mt-3 text-center small text-muted">
          Male Enrolled: <strong><?= $maleCount ?></strong> &nbsp;|&nbsp; 
          Female Enrolled: <strong><?= $femaleCount ?></strong>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4">
  <!-- Top Performing Subjects Roster -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-star me-2 text-success"></i>Highest Average Subjects</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Subject Details</th><th class="text-end">Average Score (100%)</th></tr>
            </thead>
            <tbody>
              <?php if (empty($subjectAverages)): ?>
              <tr><td colspan="2" class="text-center text-muted py-4">No performance metrics registered.</td></tr>
              <?php else: foreach ($subjectAverages as $sa): ?>
              <tr>
                <td class="fw-semibold text-dark"><?= e($sa['subject_name']) ?> <small class="text-muted">(<?= e($sa['code']) ?>)</small></td>
                <td class="text-end fw-bold text-success"><?= number_format($sa['avg_score'], 1) ?>%</td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Enrollment Status Details -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-semibold text-dark"><i class="fas fa-chart-pie me-2 text-success"></i>Enrollment Status Distribution</h6></div>
      <div class="card-body d-flex flex-column justify-content-between">
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Active Students</span>
            <span><?= $totalStudents > 0 ? round(($activeStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $activeStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-success" style="width: <?= $totalStudents > 0 ? ($activeStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-3">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Graduated Alumni</span>
            <span><?= $totalStudents > 0 ? round(($graduatedStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $graduatedStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-primary" style="width: <?= $totalStudents > 0 ? ($graduatedStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
        <div class="mb-0">
          <div class="d-flex justify-content-between mb-1 small fw-semibold">
            <span>Transferred</span>
            <span><?= $totalStudents > 0 ? round(($transferredStudents/$totalStudents)*100, 1) : 0 ?>% (<?= $transferredStudents ?>)</span>
          </div>
          <div class="progress" style="height:8px;"><div class="progress-bar bg-warning" style="width: <?= $totalStudents > 0 ? ($transferredStudents/$totalStudents)*100 : 0 ?>%"></div></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     ENHANCED REPORTS
     ═══════════════════════════════════════════════════════════════ -->

<?php
// ── Attendance summary by class ────────────────────────────────
$attFilter = (int)($_GET['att_class'] ?? 0);
$allClasses = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_classes WHERE org_id=? AND status='active' ORDER BY name"); $s->execute([$orgId]); $allClasses=$s->fetchAll(); } catch(Throwable $e) {}

$attendanceSummary = [];
try {
    $where = 'a.org_id=?'; $params = [$orgId];
    if ($attFilter) { $where .= ' AND s.class_id=?'; $params[]=$attFilter; }
    $s = $pdo->prepare(
        "SELECT s.first_name, s.last_name, s.admission_no, c.name AS class_name,
                COUNT(a.id) AS total_days,
                SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN a.status='absent'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN a.status='late'    THEN 1 ELSE 0 END) AS late
         FROM sch_students s
         LEFT JOIN sch_attendance a ON a.student_id=s.id AND a.org_id=s.org_id
         LEFT JOIN sch_classes c ON s.class_id=c.id
         WHERE $where AND s.status='active'
         GROUP BY s.id ORDER BY c.name, s.first_name LIMIT 100"
    );
    $s->execute($params);
    $attendanceSummary = $s->fetchAll();
} catch (Throwable $e) {}

// ── Class ranking (latest completed exam) ─────────────────────────
$rankExamId = (int)($_GET['rank_exam'] ?? 0);
$allExams   = [];
try { $s=$pdo->prepare("SELECT id,name FROM sch_exams WHERE org_id=? ORDER BY end_date DESC LIMIT 20"); $s->execute([$orgId]); $allExams=$s->fetchAll(); } catch(Throwable $e) {}

$rankingData = [];
if ($rankExamId) {
    try {
        $s = $pdo->prepare(
            "SELECT s.first_name, s.last_name, s.admission_no, c.name AS class_name,
                    SUM(r.marks) AS total, SUM(r.max_marks) AS max_total,
                    ROUND(SUM(r.marks)/NULLIF(SUM(r.max_marks),0)*100,1) AS pct,
                    COUNT(r.id) AS subjects
             FROM sch_results r
             JOIN sch_students s ON r.student_id=s.id
             LEFT JOIN sch_classes c ON s.class_id=c.id
             WHERE r.exam_id=? AND r.org_id=?
             GROUP BY r.student_id
             ORDER BY c.name, pct DESC"
        );
        $s->execute([$rankExamId, $orgId]);
        foreach ($s->fetchAll() as $row) {
            $rankingData[$row['class_name'] ?? 'Unknown'][] = $row;
        }
    } catch (Throwable $e) {}
}

// ── Fee collection by class ────────────────────────────────────────
$feeByClass = [];
try {
    $s = $pdo->prepare(
        "SELECT c.name AS class_name,
                COUNT(f.id) AS invoices,
                SUM(f.amount) AS invoiced,
                SUM(f.paid)   AS collected,
                SUM(f.balance) AS outstanding
         FROM sch_fees f
         JOIN sch_students s ON f.student_id=s.id
         LEFT JOIN sch_classes c ON s.class_id=c.id
         WHERE f.org_id=?
         GROUP BY s.class_id ORDER BY c.name"
    );
    $s->execute([$orgId]);
    $feeByClass = $s->fetchAll();
} catch (Throwable $e) {}
?>

<!-- Attendance Summary -->
<div class="card mb-4 mt-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-clipboard-check me-2 text-success"></i>Attendance Summary</h6>
    <form method="GET" class="d-flex gap-2 align-items-center">
      <?php foreach ($_GET as $k=>$v): if ($k !== 'att_class'): ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
      <?php endif; endforeach; ?>
      <select name="att_class" class="form-select form-select-sm" style="min-width:140px" onchange="this.form.submit()">
        <option value="">All Classes</option>
        <?php foreach ($allClasses as $cl): ?>
        <option value="<?=$cl['id']?>" <?=$attFilter==$cl['id']?'selected':''?>><?=e($cl['name'])?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Student</th><th>Adm No</th><th>Class</th><th class="text-center">Days Recorded</th><th class="text-center">Present</th><th class="text-center">Absent</th><th class="text-center">Late</th><th class="text-center">Attendance %</th></tr>
        </thead>
        <tbody>
          <?php if (empty($attendanceSummary)): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No attendance data found.</td></tr>
          <?php else: foreach ($attendanceSummary as $a):
            $pct = $a['total_days'] > 0 ? round($a['present']/$a['total_days']*100) : null;
          ?>
          <tr>
            <td class="fw-semibold small"><?= e($a['first_name'].' '.$a['last_name']) ?></td>
            <td class="small text-muted"><?= e($a['admission_no']??'—') ?></td>
            <td class="small"><?= e($a['class_name']??'—') ?></td>
            <td class="text-center small"><?= $a['total_days'] ?></td>
            <td class="text-center small text-success fw-semibold"><?= $a['present'] ?></td>
            <td class="text-center small text-danger"><?= $a['absent'] ?></td>
            <td class="text-center small text-warning"><?= $a['late'] ?></td>
            <td class="text-center">
              <?php if ($pct !== null): ?>
              <span class="badge <?= $pct>=80?'bg-success':($pct>=60?'bg-warning text-dark':'bg-danger') ?>"><?= $pct ?>%</span>
              <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Class Ranking -->
<div class="card mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0 fw-bold"><i class="fas fa-trophy me-2 text-warning"></i>Class Performance Ranking</h6>
    <form method="GET" class="d-flex gap-2 align-items-center">
      <?php foreach ($_GET as $k=>$v): if ($k !== 'rank_exam'): ?>
      <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
      <?php endif; endforeach; ?>
      <select name="rank_exam" class="form-select form-select-sm" style="min-width:160px" onchange="this.form.submit()">
        <option value="">Select Exam</option>
        <?php foreach ($allExams as $ex): ?>
        <option value="<?=$ex['id']?>" <?=$rankExamId==$ex['id']?'selected':''?>><?=e($ex['name'])?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div class="card-body p-0">
    <?php if (!$rankExamId): ?>
    <div class="text-center text-muted py-4 small"><i class="fas fa-arrow-up d-block mb-1 fa-2x opacity-25"></i>Select an exam to view class rankings.</div>
    <?php elseif (empty($rankingData)): ?>
    <div class="text-center text-muted py-4 small">No results found for this exam.</div>
    <?php else: ?>
    <?php foreach ($rankingData as $className => $students): $rank=0; ?>
    <div class="px-3 py-2 border-bottom bg-light d-flex align-items-center gap-2">
      <i class="fas fa-chalkboard me-1" style="color:<?=$moduleColor?>"></i>
      <strong class="small"><?= e($className) ?></strong>
      <span class="badge bg-secondary bg-opacity-25 text-secondary"><?= count($students) ?> students</span>
    </div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th style="width:50px">#</th><th>Student</th><th>Adm No</th><th class="text-center">Total Marks</th><th class="text-center">%</th></tr></thead>
        <tbody>
        <?php foreach ($students as $st): $rank++;
          $medal = $rank===1?'🥇':($rank===2?'🥈':($rank===3?'🥉':''));
        ?>
        <tr class="<?=$rank<=3?'table-success':''?>">
          <td class="text-center fw-700"><?=$medal?><small><?=$rank?></small></td>
          <td class="fw-semibold small"><?=e($st['first_name'].' '.$st['last_name'])?></td>
          <td class="small text-muted"><?=e($st['admission_no']??'—')?></td>
          <td class="text-center small"><?=$st['total']?>/<?=$st['max_total']?></td>
          <td class="text-center"><span class="badge <?=$st['pct']>=80?'bg-success':($st['pct']>=60?'bg-warning text-dark':'bg-danger')?>"><?=$st['pct']?>%</span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- Fee Collection by Class -->
<div class="card mb-4">
  <div class="card-header">
    <h6 class="mb-0 fw-bold"><i class="fas fa-money-bill-wave me-2 text-success"></i>Fee Collection by Class</h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead class="table-light">
          <tr><th>Class</th><th class="text-center">Invoices</th><th class="text-end">Invoiced</th><th class="text-end">Collected</th><th class="text-end">Outstanding</th><th class="text-center">Rate</th></tr>
        </thead>
        <tbody>
        <?php if (empty($feeByClass)): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No fee data found.</td></tr>
        <?php else: foreach ($feeByClass as $fc):
          $rate = $fc['invoiced'] > 0 ? round($fc['collected']/$fc['invoiced']*100) : 0;
        ?>
        <tr>
          <td class="fw-semibold small"><?=e($fc['class_name']??'—')?></td>
          <td class="text-center small"><?=$fc['invoices']?></td>
          <td class="text-end small"><?=formatCurrency($fc['invoiced'])?></td>
          <td class="text-end small text-success fw-semibold"><?=formatCurrency($fc['collected'])?></td>
          <td class="text-end small <?=$fc['outstanding']>0?'text-danger fw-semibold':''?>"><?=formatCurrency($fc['outstanding'])?></td>
          <td class="text-center">
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-fill" style="height:6px"><div class="progress-bar <?=$rate>=80?'bg-success':($rate>=50?'bg-warning':'bg-danger')?>" style="width:<?=$rate?>%"></div></div>
              <span class="small fw-semibold"><?=$rate?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php
$gradeLabelsJson = json_encode(array_keys($gradesDistribution));
$gradeDataJson   = json_encode(array_values($gradesDistribution));

$genderLabelsJson = json_encode(['Male Students', 'Female Students']);
$genderDataJson   = json_encode([$maleCount, $femaleCount]);

$extraJs = <<<JS
<script>
// Grade distribution Chart
new Chart(document.getElementById('gradeDistChart'), {
  type: 'bar',
  data: {
    labels: {$gradeLabelsJson},
    datasets: [{
      label: 'Performance Score Entries',
      data: {$gradeDataJson},
      backgroundColor: '#1A8A4E',
      borderRadius: 4
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } }
    }
  }
});

// Gender Doughnut Chart
new Chart(document.getElementById('genderChart'), {
  type: 'doughnut',
  data: {
    labels: {$genderLabelsJson},
    datasets: [{
      data: {$genderDataJson},
      backgroundColor: ['#0b5ed7', '#d63384']
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom' } }
  }
});
</script>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>

