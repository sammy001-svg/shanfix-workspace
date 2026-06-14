<?php
$pageTitle = 'My Attendance';
require_once __DIR__ . '/../includes/header-student.php';

// ── Monthly summary (last 6 months) ─────────────────────────────
$monthlySummary = [];
try {
    $s = $pdo->prepare(
        "SELECT DATE_FORMAT(att_date,'%Y-%m') AS ym,
                DATE_FORMAT(att_date,'%b %Y')  AS label,
                SUM(status='present')  AS present,
                SUM(status='absent')   AS absent,
                SUM(status='late')     AS late,
                SUM(status='excused')  AS excused,
                COUNT(*)               AS total
         FROM sch_attendance
         WHERE student_id=? AND org_id=?
           AND att_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY ym ORDER BY ym DESC"
    );
    $s->execute([$stuId, $stuOrgId]);
    $monthlySummary = $s->fetchAll();
} catch (Throwable $e) {}

// ── Overall stats (current academic year) ────────────────────────
$overallStats = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0,'total'=>0];
try {
    $s = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM sch_attendance
         WHERE student_id=? AND org_id=?
           AND YEAR(att_date) = YEAR(CURDATE())
         GROUP BY status"
    );
    $s->execute([$stuId, $stuOrgId]);
    foreach ($s->fetchAll() as $r) {
        $overallStats[$r['status']] = (int)$r['cnt'];
        $overallStats['total'] += (int)$r['cnt'];
    }
} catch (Throwable $e) {}
$overallRate = $overallStats['total'] > 0
    ? round($overallStats['present'] / $overallStats['total'] * 100, 1)
    : null;

// ── Recent daily records ─────────────────────────────────────────
$filterMonth = $_GET['month'] ?? date('Y-m');
$records = [];
try {
    $s = $pdo->prepare(
        "SELECT att_date, status, remarks FROM sch_attendance
         WHERE student_id=? AND org_id=?
           AND DATE_FORMAT(att_date,'%Y-%m')=?
         ORDER BY att_date DESC"
    );
    $s->execute([$stuId, $stuOrgId, $filterMonth]);
    $records = $s->fetchAll();
} catch (Throwable $e) {}

$statusConfig = [
    'present' => ['label'=>'Present', 'color'=>'#1A8A4E', 'bg'=>'#f0fdf4', 'badge'=>'success'],
    'absent'  => ['label'=>'Absent',  'color'=>'#e74c3c', 'bg'=>'#fef2f2', 'badge'=>'danger'],
    'late'    => ['label'=>'Late',    'color'=>'#f39c12', 'bg'=>'#fef5e7', 'badge'=>'warning'],
    'excused' => ['label'=>'Excused', 'color'=>'#3498db', 'bg'=>'#eff6ff', 'badge'=>'info'],
];
?>

<h5 class="fw-bold mb-4"><i class="fas fa-clipboard-check me-2" style="color:var(--stu-blue)"></i>My Attendance</h5>

<!-- Overall stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:rgba(29,78,216,.1)">
        <i class="fas fa-chart-pie" style="color:var(--stu-blue)"></i>
      </div>
      <div class="fs-3 fw-bold <?= $overallRate!==null?($overallRate>=80?'text-success':($overallRate>=60?'text-warning':'text-danger')):'' ?>">
        <?= $overallRate !== null ? $overallRate . '%' : '&mdash;' ?>
      </div>
      <div class="text-muted small">Attendance Rate (<?= date('Y') ?>)</div>
    </div>
  </div>
  <?php foreach (['present'=>'success','absent'=>'danger','late'=>'warning','excused'=>'info'] as $st => $cls): ?>
  <div class="col-6 col-md-3">
    <div class="stu-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:<?= $statusConfig[$st]['bg'] ?>">
        <i class="fas fa-circle" style="color:<?= $statusConfig[$st]['color'] ?>;font-size:.7rem"></i>
      </div>
      <div class="fs-3 fw-bold text-<?= $cls ?>"><?= $overallStats[$st] ?></div>
      <div class="text-muted small"><?= $statusConfig[$st]['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Monthly breakdown -->
<?php if (!empty($monthlySummary)): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header"><h6 class="mb-0 fw-bold"><i class="fas fa-chart-bar me-2" style="color:var(--stu-blue)"></i>Monthly Breakdown (Last 6 Months)</h6></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-sm mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>Month</th>
            <th class="text-center text-success">Present</th>
            <th class="text-center text-danger">Absent</th>
            <th class="text-center text-warning">Late</th>
            <th class="text-center text-info">Excused</th>
            <th class="text-center">Rate</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($monthlySummary as $m):
            $rate = $m['total'] > 0 ? round($m['present'] / $m['total'] * 100) : 0;
            $rateColor = $rate >= 80 ? 'success' : ($rate >= 60 ? 'warning' : 'danger');
          ?>
          <tr>
            <td class="fw-semibold small"><?= e($m['label']) ?></td>
            <td class="text-center small text-success"><?= $m['present'] ?></td>
            <td class="text-center small text-danger"><?= $m['absent'] ?></td>
            <td class="text-center small text-warning"><?= $m['late'] ?></td>
            <td class="text-center small text-info"><?= $m['excused'] ?></td>
            <td class="text-center">
              <span class="badge bg-<?= $rateColor ?>"><?= $rate ?>%</span>
            </td>
            <td>
              <a href="?month=<?= $m['ym'] ?>" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-2" style="font-size:.68rem">Details</a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Daily records for selected month -->
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2" style="color:var(--stu-blue)"></i>Daily Records</h6>
    <input type="month" class="form-control form-control-sm" style="width:160px"
           value="<?= e($filterMonth) ?>" max="<?= date('Y-m') ?>"
           onchange="location.href='?month='+this.value">
  </div>
  <div class="card-body p-0">
    <?php if (empty($records)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-calendar fa-3x mb-3 d-block opacity-25"></i>
      <h6>No records for this month</h6>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead class="table-light">
          <tr><th>Date</th><th>Day</th><th class="text-center">Status</th><th class="d-none d-md-table-cell">Remarks</th></tr>
        </thead>
        <tbody>
          <?php foreach ($records as $rec):
            $cfg = $statusConfig[$rec['status']] ?? $statusConfig['present'];
            $isToday = $rec['att_date'] === date('Y-m-d');
          ?>
          <tr class="<?= $isToday ? 'table-primary' : '' ?>">
            <td class="fw-semibold small">
              <?= date('d M Y', strtotime($rec['att_date'])) ?>
              <?php if ($isToday): ?><span class="badge bg-primary ms-1" style="font-size:.6rem">Today</span><?php endif; ?>
            </td>
            <td class="small text-muted"><?= date('l', strtotime($rec['att_date'])) ?></td>
            <td class="text-center">
              <span class="badge bg-<?= $cfg['badge'] ?>"><?= $cfg['label'] ?></span>
            </td>
            <td class="small text-muted d-none d-md-table-cell"><?= e($rec['remarks'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-student.php'; ?>
