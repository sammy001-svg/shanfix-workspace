<?php
require_once __DIR__ . '/_nav.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
if (session_status() === PHP_SESSION_NONE) session_start();
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

// ── Period filter (academic year) ──────────────────────────────────────
$years = [];
try {
    $s = $pdo->prepare("SELECT id, name, is_current FROM sch_academic_years WHERE org_id=? ORDER BY is_current DESC, name DESC");
    $s->execute([$orgId]); $years = $s->fetchAll();
} catch (Throwable $e) {}

$selYear = sanitize($_GET['year'] ?? '');
$yearFilter = ''; $yearParams = [];
if ($selYear) {
    $yearFilter  = " AND ay.name = ?";
    $yearParams  = [$selYear];
}

// ── KPI Cards ──────────────────────────────────────────────────────────
$kpiStudents = $kpiTeachers = $kpiAttRate = $kpiFeeRate = $kpiPassRate = $kpiAvgScore = 0;
try {
    $kpiStudents = (int)$pdo->prepare("SELECT COUNT(*) FROM sch_students WHERE org_id=? AND status='active'")->execute([$orgId]) ?
        (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
    $s = $pdo->prepare("SELECT COUNT(*) FROM sch_students WHERE org_id=? AND status='active'");
    $s->execute([$orgId]); $kpiStudents = (int)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM sch_teachers WHERE org_id=? AND status='active'");
    $s->execute([$orgId]); $kpiTeachers = (int)$s->fetchColumn();

    // Attendance rate — last 30 days
    $s = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status='present') AS present FROM sch_attendance WHERE org_id=? AND att_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)");
    $s->execute([$orgId]); $row = $s->fetch();
    $kpiAttRate = ($row['total'] > 0) ? round($row['present'] / $row['total'] * 100, 1) : 0;

    // Fee collection rate — all time (paid / total invoiced)
    $s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS invoiced, COALESCE(SUM(paid),0) AS paid FROM sch_fees WHERE org_id=?");
    $s->execute([$orgId]); $row = $s->fetch();
    $kpiFeeRate = ($row['invoiced'] > 0) ? round($row['paid'] / $row['invoiced'] * 100, 1) : 0;

    // Pass rate + avg score from results
    $s = $pdo->prepare("SELECT COUNT(*) AS total, SUM(marks >= 50) AS passed, ROUND(AVG(marks),1) AS avg FROM sch_results WHERE org_id=?");
    $s->execute([$orgId]); $row = $s->fetch();
    $kpiPassRate = ($row['total'] > 0) ? round($row['passed'] / $row['total'] * 100, 1) : 0;
    $kpiAvgScore = (float)($row['avg'] ?? 0);
} catch (Throwable $e) {}

// ── Attendance trend — last 6 months ──────────────────────────────────
$attLabels = $attRates = [];
try {
    $s = $pdo->prepare(
        "SELECT DATE_FORMAT(att_date,'%b %Y') AS month,
                MIN(att_date) AS min_date,
                COUNT(*) AS total,
                SUM(status='present') AS present
         FROM sch_attendance WHERE org_id=? AND att_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(att_date,'%Y-%m')
         ORDER BY min_date ASC"
    );
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) {
        $attLabels[] = $r['month'];
        $attRates[]  = $r['total'] > 0 ? round($r['present'] / $r['total'] * 100, 1) : 0;
    }
} catch (Throwable $e) {}

// ── Fee collection trend — last 6 months ──────────────────────────────
$feeLabels = $feeAmounts = [];
try {
    $s = $pdo->prepare(
        "SELECT DATE_FORMAT(fp.payment_date,'%b %Y') AS month,
                MIN(fp.payment_date) AS min_date,
                ROUND(SUM(fp.amount_paid),2) AS total
         FROM sch_fee_payments fp
         JOIN sch_fees f ON fp.fee_id=f.id
         WHERE f.org_id=? AND fp.payment_date >= DATE_SUB(CURDATE(),INTERVAL 6 MONTH)
         GROUP BY DATE_FORMAT(fp.payment_date,'%Y-%m')
         ORDER BY min_date ASC"
    );
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) {
        $feeLabels[]  = $r['month'];
        $feeAmounts[] = (float)$r['total'];
    }
} catch (Throwable $e) {}

// ── Subject pass rates ─────────────────────────────────────────────────
$subjNames = $subjPass = $subjFail = [];
try {
    $s = $pdo->prepare(
        "SELECT sub.name AS subject,
                COUNT(r.id) AS total,
                SUM(r.marks >= COALESCE(sub.pass_mark, 50)) AS passed
         FROM sch_results r
         JOIN sch_subjects sub ON sub.id = r.subject_id
         WHERE r.org_id = ?
         GROUP BY sub.id HAVING total >= 3
         ORDER BY (passed/total) DESC LIMIT 10"
    );
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) {
        $subjNames[] = $r['subject'];
        $pct = $r['total'] > 0 ? round($r['passed'] / $r['total'] * 100, 1) : 0;
        $subjPass[]  = $pct;
        $subjFail[]  = round(100 - $pct, 1);
    }
} catch (Throwable $e) {}

// ── Class performance ──────────────────────────────────────────────────
$classNames = $classAvg = $classPassRate = [];
$classRows  = [];
try {
    $s = $pdo->prepare(
        "SELECT c.name AS class_name,
                COUNT(DISTINCT r.student_id) AS students,
                ROUND(AVG(r.marks),1)        AS avg_marks,
                SUM(r.marks >= 50)           AS passed,
                COUNT(r.id)                  AS total_results
         FROM sch_results r
         JOIN sch_students st ON st.id=r.student_id
         JOIN sch_classes c   ON c.id=st.class_id
         WHERE r.org_id=?
         GROUP BY c.id HAVING total_results >= 5
         ORDER BY avg_marks DESC"
    );
    $s->execute([$orgId]);
    $classRows = $s->fetchAll();
    foreach ($classRows as $r) {
        $classNames[]    = $r['class_name'];
        $classAvg[]      = (float)$r['avg_marks'];
        $classPassRate[] = $r['total_results'] > 0 ? round($r['passed'] / $r['total_results'] * 100, 1) : 0;
    }
} catch (Throwable $e) {}

// ── Top 10 students ────────────────────────────────────────────────────
$topStudents = [];
try {
    $s = $pdo->prepare(
        "SELECT CONCAT(st.first_name,' ',st.last_name) AS name,
                st.admission_no,
                c.name   AS class_name,
                COUNT(r.id)         AS exams_taken,
                ROUND(AVG(r.marks),1) AS avg_marks,
                MAX(r.marks)        AS top_score,
                SUM(r.marks >= 50)  AS passed
         FROM sch_results r
         JOIN sch_students st ON st.id=r.student_id
         LEFT JOIN sch_classes c ON c.id=st.class_id
         WHERE r.org_id=?
         GROUP BY st.id HAVING exams_taken >= 2
         ORDER BY avg_marks DESC LIMIT 10"
    );
    $s->execute([$orgId]); $topStudents = $s->fetchAll();
} catch (Throwable $e) {}

// ── Exam results distribution (score buckets) ──────────────────────────
$bucketLabels  = ['0–39','40–49','50–59','60–69','70–79','80–89','90–100'];
$bucketCounts  = [0,0,0,0,0,0,0];
try {
    $s = $pdo->prepare("SELECT marks FROM sch_results WHERE org_id=?");
    $s->execute([$orgId]);
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $m) {
        $m = (int)$m;
        if      ($m < 40) $bucketCounts[0]++;
        elseif  ($m < 50) $bucketCounts[1]++;
        elseif  ($m < 60) $bucketCounts[2]++;
        elseif  ($m < 70) $bucketCounts[3]++;
        elseif  ($m < 80) $bucketCounts[4]++;
        elseif  ($m < 90) $bucketCounts[5]++;
        else               $bucketCounts[6]++;
    }
} catch (Throwable $e) {}

// ── Student enrollment by curriculum ──────────────────────────────────
$currLabels = $currCounts = [];
try {
    $s = $pdo->prepare("SELECT COALESCE(curriculum,'Other') AS curr, COUNT(*) AS cnt FROM sch_students WHERE org_id=? AND status='active' GROUP BY curriculum ORDER BY cnt DESC");
    $s->execute([$orgId]);
    foreach ($s->fetchAll() as $r) {
        $currLabels[] = $r['curr']; $currCounts[] = (int)$r['cnt'];
    }
} catch (Throwable $e) {}

// ── Absentee trend — top 10 most absent students ───────────────────────
$absentStudents = [];
try {
    $s = $pdo->prepare(
        "SELECT CONCAT(st.first_name,' ',st.last_name) AS name, st.admission_no,
                c.name AS class_name,
                COUNT(*) AS absent_days
         FROM sch_attendance a
         JOIN sch_students st ON st.id=a.student_id
         LEFT JOIN sch_classes c ON c.id=st.class_id
         WHERE a.org_id=? AND a.status='absent'
           AND a.att_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)
         GROUP BY st.id
         ORDER BY absent_days DESC LIMIT 10"
    );
    $s->execute([$orgId]); $absentStudents = $s->fetchAll();
} catch (Throwable $e) {}

require_once __DIR__ . '/../../includes/header-module.php';
?>
<?= flashAlert() ?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-chart-line me-2" style="color:<?= $moduleColor ?>"></i>Advanced Analytics</h4>
    <p class="text-muted mb-0">Multi-dimensional insights into academic performance, attendance, and finance</p>
  </div>
  <form method="GET" class="d-flex align-items-center gap-2">
    <select name="year" class="form-select form-select-sm" style="width:180px" onchange="this.form.submit()">
      <option value="">All Years</option>
      <?php foreach ($years as $y): ?>
      <option value="<?= e($y['name']) ?>" <?= $selYear===$y['name']?'selected':'' ?>>
        <?= e($y['name']) ?><?= $y['is_current']?' (Current)':'' ?>
      </option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<!-- ── KPI Cards ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
  <?php
  $kpis = [
    ['label'=>'Active Students',  'value'=>number_format($kpiStudents), 'icon'=>'fas fa-user-graduate', 'bg'=>'#0B2D4E', 'suffix'=>''],
    ['label'=>'Active Teachers',  'value'=>number_format($kpiTeachers), 'icon'=>'fas fa-chalkboard-teacher','bg'=>'#1A8A4E','suffix'=>''],
    ['label'=>'Attendance Rate',  'value'=>$kpiAttRate,  'icon'=>'fas fa-clipboard-check','bg'=>$kpiAttRate>=80?'#1A8A4E':'#dc3545','suffix'=>'%'],
    ['label'=>'Fee Collection',   'value'=>$kpiFeeRate,  'icon'=>'fas fa-money-bill-wave','bg'=>$kpiFeeRate>=80?'#1A8A4E':'#fd7e14','suffix'=>'%'],
    ['label'=>'Overall Pass Rate','value'=>$kpiPassRate, 'icon'=>'fas fa-graduation-cap', 'bg'=>$kpiPassRate>=50?'#1A8A4E':'#dc3545','suffix'=>'%'],
    ['label'=>'Avg Exam Score',   'value'=>$kpiAvgScore, 'icon'=>'fas fa-star',           'bg'=>'#6f42c1','suffix'=>'/100'],
  ];
  foreach ($kpis as $k): ?>
  <div class="col-6 col-md-4 col-lg-2">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body p-3 text-center">
        <div class="rounded-circle text-white d-flex align-items-center justify-content-center mx-auto mb-2" style="width:44px;height:44px;background:<?= $k['bg'] ?>">
          <i class="<?= $k['icon'] ?> small"></i>
        </div>
        <div class="fs-4 fw-bold text-dark"><?= $k['value'] ?><span class="fs-6 text-muted"><?= $k['suffix'] ?></span></div>
        <div class="text-muted" style="font-size:.75rem"><?= $k['label'] ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Row 1: Attendance Trend + Fee Collection ───────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-clipboard-check me-2 text-success"></i>Attendance Rate — Last 6 Months</h6>
        <small class="text-muted">Monthly average % of students present</small>
      </div>
      <div class="card-body pt-2">
        <?php if (empty($attLabels)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-chart-line fa-2x mb-2 d-block"></i>No attendance data yet.</div>
        <?php else: ?>
        <canvas id="attChart" height="200"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-money-bill-wave me-2 text-success"></i>Fee Collection — Last 6 Months</h6>
        <small class="text-muted">Monthly payment totals</small>
      </div>
      <div class="card-body pt-2">
        <?php if (empty($feeLabels)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-chart-bar fa-2x mb-2 d-block"></i>No payment data yet.</div>
        <?php else: ?>
        <canvas id="feeChart" height="200"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Row 2: Subject Pass Rates + Score Distribution ────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-book me-2 text-success"></i>Subject Pass Rates</h6>
        <small class="text-muted">Top 10 subjects by overall pass % (≥50 marks)</small>
      </div>
      <div class="card-body pt-2">
        <?php if (empty($subjNames)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-book fa-2x mb-2 d-block"></i>No result data yet.</div>
        <?php else: ?>
        <canvas id="subjChart" height="<?= min(400, count($subjNames)*40 + 60) ?>"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-chart-pie me-2 text-success"></i>Score Distribution</h6>
        <small class="text-muted">How marks are spread across all exams</small>
      </div>
      <div class="card-body pt-2">
        <?php if (array_sum($bucketCounts) === 0): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-chart-pie fa-2x mb-2 d-block"></i>No result data yet.</div>
        <?php else: ?>
        <canvas id="distChart" height="240"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Row 3: Class Performance + Curriculum Breakdown ───────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-chalkboard me-2 text-success"></i>Class Performance Comparison</h6>
        <small class="text-muted">Average exam score and pass rate per class</small>
      </div>
      <div class="card-body p-0">
        <?php if (empty($classRows)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-chalkboard fa-2x mb-2 d-block"></i>No class result data yet.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Class</th><th>Students</th><th>Avg Score</th><th>Pass Rate</th><th>Progress</th></tr>
            </thead>
            <tbody>
              <?php foreach ($classRows as $i => $cr):
                $pr = $classPassRate[$i];
                $barColor = $pr >= 75 ? '#1A8A4E' : ($pr >= 50 ? '#fd7e14' : '#dc3545');
              ?>
              <tr>
                <td class="fw-semibold"><?= e($cr['class_name']) ?></td>
                <td><?= $cr['students'] ?></td>
                <td>
                  <span class="fw-bold" style="color:<?= (float)$cr['avg_marks']>=50?'#1A8A4E':'#dc3545' ?>">
                    <?= $cr['avg_marks'] ?>
                  </span><span class="text-muted small">/100</span>
                </td>
                <td><?= $pr ?>%</td>
                <td style="min-width:100px">
                  <div class="progress" style="height:6px">
                    <div class="progress-bar" style="width:<?= $pr ?>%;background:<?= $barColor ?>"></div>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-globe me-2 text-success"></i>Curriculum Breakdown</h6>
        <small class="text-muted">Active students by curriculum</small>
      </div>
      <div class="card-body pt-2">
        <?php if (empty($currLabels)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-globe fa-2x mb-2 d-block"></i>No student data yet.</div>
        <?php else: ?>
        <canvas id="currChart" height="220"></canvas>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Row 4: Top Performers + At-Risk Absentees ─────────────────────── -->
<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-trophy me-2 text-warning"></i>Top 10 Performers</h6>
        <small class="text-muted">Ranked by average exam score (min 2 exams)</small>
      </div>
      <div class="card-body p-0">
        <?php if (empty($topStudents)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-trophy fa-2x mb-2 d-block"></i>No result data yet.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>#</th><th>Student</th><th>Class</th><th>Exams</th><th>Avg</th><th>Top</th></tr>
            </thead>
            <tbody>
              <?php foreach ($topStudents as $i => $st): ?>
              <tr>
                <td>
                  <?php if ($i === 0): ?><i class="fas fa-medal text-warning"></i>
                  <?php elseif ($i === 1): ?><i class="fas fa-medal text-secondary"></i>
                  <?php elseif ($i === 2): ?><i class="fas fa-medal" style="color:#cd7f32"></i>
                  <?php else: ?><span class="text-muted"><?= $i+1 ?></span><?php endif; ?>
                </td>
                <td>
                  <div class="fw-semibold small"><?= e($st['name']) ?></div>
                  <small class="text-muted"><?= e($st['admission_no']) ?></small>
                </td>
                <td><span class="badge bg-light text-dark border small"><?= e($st['class_name'] ?: '—') ?></span></td>
                <td class="text-center"><?= $st['exams_taken'] ?></td>
                <td>
                  <span class="fw-bold <?= $st['avg_marks']>=50?'text-success':'text-danger' ?>">
                    <?= $st['avg_marks'] ?>
                  </span>
                </td>
                <td class="text-success fw-semibold"><?= $st['top_score'] ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white border-bottom-0 pb-0">
        <h6 class="fw-bold mb-0"><i class="fas fa-exclamation-triangle me-2 text-danger"></i>At-Risk: Most Absent (Last 30 Days)</h6>
        <small class="text-muted">Students with highest absence count this month</small>
      </div>
      <div class="card-body p-0">
        <?php if (empty($absentStudents)): ?>
        <div class="text-center text-muted py-4"><i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>No absences recorded in the last 30 days.</div>
        <?php else: ?>
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Student</th><th>Class</th><th>Absent Days</th><th>Risk Level</th></tr>
            </thead>
            <tbody>
              <?php foreach ($absentStudents as $ab):
                $risk = $ab['absent_days'] >= 10 ? ['High','danger'] : ($ab['absent_days'] >= 5 ? ['Medium','warning'] : ['Low','info']);
              ?>
              <tr>
                <td>
                  <div class="fw-semibold small"><?= e($ab['name']) ?></div>
                  <small class="text-muted"><?= e($ab['admission_no']) ?></small>
                </td>
                <td><span class="badge bg-light text-dark border small"><?= e($ab['class_name'] ?: '—') ?></span></td>
                <td>
                  <span class="fw-bold text-danger"><?= $ab['absent_days'] ?></span>
                  <span class="text-muted small"> day<?= $ab['absent_days']!=1?'s':'' ?></span>
                </td>
                <td><span class="badge bg-<?= $risk[1] ?>"><?= $risk[0] ?></span></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php ob_start(); ?>
<script>
const moduleColor  = '<?= $moduleColor ?>';
const navyColor    = '#0B2D4E';
const orangeColor  = '#fd7e14';
const dangerColor  = '#dc3545';

// ── Attendance trend ────────────────────────────────────────────────────
<?php if (!empty($attLabels)): ?>
new Chart(document.getElementById('attChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode($attLabels) ?>,
    datasets: [{
      label: 'Attendance %',
      data:  <?= json_encode($attRates) ?>,
      borderColor: moduleColor, backgroundColor: moduleColor+'22',
      borderWidth: 2.5, pointRadius: 4, fill: true, tension: 0.35
    }]
  },
  options: {
    scales: {
      y: { min: 0, max: 100, ticks: { callback: v => v+'%' } }
    },
    plugins: { legend: { display: false } }
  }
});
<?php endif; ?>

// ── Fee collection ─────────────────────────────────────────────────────
<?php if (!empty($feeLabels)): ?>
new Chart(document.getElementById('feeChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($feeLabels) ?>,
    datasets: [{
      label: 'Amount Collected',
      data:  <?= json_encode($feeAmounts) ?>,
      backgroundColor: moduleColor+'cc', borderRadius: 5
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { y: { ticks: { callback: v => v.toLocaleString() } } }
  }
});
<?php endif; ?>

// ── Subject pass rates (horizontal bar) ────────────────────────────────
<?php if (!empty($subjNames)): ?>
new Chart(document.getElementById('subjChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($subjNames) ?>,
    datasets: [
      { label: 'Pass %', data: <?= json_encode($subjPass) ?>, backgroundColor: moduleColor+'cc', borderRadius: 4 },
      { label: 'Fail %', data: <?= json_encode($subjFail) ?>, backgroundColor: dangerColor+'88',  borderRadius: 4 }
    ]
  },
  options: {
    indexAxis: 'y',
    scales: { x: { stacked: true, max: 100, ticks: { callback: v => v+'%' } }, y: { stacked: true } },
    plugins: { legend: { position: 'bottom' } }
  }
});
<?php endif; ?>

// ── Score distribution ─────────────────────────────────────────────────
<?php if (array_sum($bucketCounts) > 0): ?>
new Chart(document.getElementById('distChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($bucketLabels) ?>,
    datasets: [{
      data: <?= json_encode($bucketCounts) ?>,
      backgroundColor: ['#dc3545','#fd7e14','#ffc107','#6f42c1','#0d6efd','#1A8A4E','#0B2D4E'],
      borderWidth: 2
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
    cutout: '60%'
  }
});
<?php endif; ?>

// ── Curriculum breakdown ───────────────────────────────────────────────
<?php if (!empty($currLabels)): ?>
new Chart(document.getElementById('currChart'), {
  type: 'pie',
  data: {
    labels: <?= json_encode($currLabels) ?>,
    datasets: [{
      data: <?= json_encode($currCounts) ?>,
      backgroundColor: ['#0B2D4E','#1A8A4E','#0d6efd','#fd7e14','#6f42c1','#adb5bd'],
      borderWidth: 2
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } }
  }
});
<?php endif; ?>
</script>
<?php
$extraJs = ob_get_clean();
require_once __DIR__ . '/../../includes/footer.php';
?>
