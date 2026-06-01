<?php
$pageTitle = 'Attendance';
require_once __DIR__ . '/../includes/header-parent.php';

// ── Filter: term or date range ──────────────────────────────────
$termId    = (int)($_GET['term'] ?? 0);
$dateFrom  = $_GET['from'] ?? date('Y-m-01', strtotime('-2 months'));
$dateTo    = $_GET['to']   ?? date('Y-m-d');

// Fetch available terms
$terms = [];
try {
    $t = $pdo->prepare("SELECT id, name FROM sch_terms WHERE org_id=? ORDER BY start_date DESC");
    $t->execute([$parOrgId]);
    $terms = $t->fetchAll();
} catch (Throwable $e) {}

// If a term is selected, use its date range
if ($termId) {
    try {
        $tr = $pdo->prepare("SELECT start_date, end_date FROM sch_terms WHERE id=? AND org_id=? LIMIT 1");
        $tr->execute([$termId, $parOrgId]);
        $termRow = $tr->fetch();
        if ($termRow) { $dateFrom = $termRow['start_date']; $dateTo = $termRow['end_date']; }
    } catch (Throwable $e) {}
}

// ── Attendance records ──────────────────────────────────────────
$records = [];
try {
    $s = $pdo->prepare(
        "SELECT date, status, remarks FROM sch_attendance
         WHERE student_id=? AND org_id=? AND date BETWEEN ? AND ?
         ORDER BY date DESC"
    );
    $s->execute([$parActive, $parOrgId, $dateFrom, $dateTo]);
    $records = $s->fetchAll();
} catch (Throwable $e) {}

// Summary counts
$counts = ['present'=>0,'absent'=>0,'late'=>0,'excused'=>0];
foreach ($records as $r) {
    $st = strtolower($r['status'] ?? 'absent');
    if (isset($counts[$st])) $counts[$st]++;
}
$total     = count($records);
$attendPct = $total > 0 ? round($counts['present'] / $total * 100) : 0;

$statusColors = [
    'present' => ['bg'=>'#d4edda','txt'=>'#155724','label'=>'Present'],
    'absent'  => ['bg'=>'#fde8e8','txt'=>'#721c24','label'=>'Absent'],
    'late'    => ['bg'=>'#fff3cd','txt'=>'#856404','label'=>'Late'],
    'excused' => ['bg'=>'#cce5ff','txt'=>'#004085','label'=>'Excused'],
];
?>

<div class="d-flex align-items-center mb-4">
  <h5 class="fw-bold mb-0"><i class="fas fa-clipboard-check me-2" style="color:var(--par-green)"></i>Attendance Record</h5>
</div>

<!-- Filter bar -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-2">
    <form method="GET" class="d-flex flex-wrap align-items-center gap-3">
      <?php if (!empty($terms)): ?>
      <div class="d-flex align-items-center gap-2">
        <label class="small fw-semibold mb-0">Term:</label>
        <select name="term" class="form-select form-select-sm" style="min-width:130px" onchange="this.form.submit()">
          <option value="">Custom range</option>
          <?php foreach ($terms as $tm): ?>
          <option value="<?= $tm['id'] ?>" <?= $termId == $tm['id'] ? 'selected' : '' ?>><?= e($tm['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="d-flex align-items-center gap-2">
        <label class="small fw-semibold mb-0">From:</label>
        <input type="date" name="from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
      </div>
      <div class="d-flex align-items-center gap-2">
        <label class="small fw-semibold mb-0">To:</label>
        <input type="date" name="to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
      </div>
      <button type="submit" class="btn btn-sm btn-success">
        <i class="fas fa-filter me-1"></i>Filter
      </button>
    </form>
  </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
  <?php foreach ([
    ['present','fa-check-circle'],['absent','fa-times-circle'],
    ['late','fa-clock'],['excused','fa-info-circle'],
  ] as [$st, $icon]):
    $sc = $statusColors[$st];
  ?>
  <div class="col-6 col-md-3">
    <div class="par-stat-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center rounded-circle mb-2"
           style="width:44px;height:44px;background:<?= $sc['bg'] ?>">
        <i class="fas <?= $icon ?>" style="color:<?= $sc['txt'] ?>"></i>
      </div>
      <div class="fs-3 fw-bold"><?= $counts[$st] ?></div>
      <div class="text-muted small"><?= $sc['label'] ?></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Overall percentage bar -->
<?php if ($total > 0): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-body py-3">
    <div class="d-flex justify-content-between mb-1">
      <span class="small fw-semibold">Attendance Rate</span>
      <span class="small fw-700 <?= $attendPct >= 80 ? 'text-success' : 'text-danger' ?>"><?= $attendPct ?>%</span>
    </div>
    <div class="progress" style="height:10px;border-radius:5px">
      <div class="progress-bar <?= $attendPct >= 80 ? 'bg-success' : ($attendPct >= 60 ? 'bg-warning' : 'bg-danger') ?>"
           style="width:<?= $attendPct ?>%"></div>
    </div>
    <div class="small text-muted mt-1"><?= $counts['present'] ?> present out of <?= $total ?> school days recorded</div>
  </div>
</div>
<?php endif; ?>

<!-- Records table -->
<div class="card border-0 shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <h6 class="mb-0 fw-bold"><i class="fas fa-list me-2"></i>Daily Records</h6>
    <span class="badge bg-secondary"><?= $total ?> days</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($records)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-clipboard fa-2x d-block mb-2 opacity-25"></i>
      No attendance records found for the selected period.
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead class="table-light">
          <tr><th>Date</th><th>Day</th><th class="text-center">Status</th><th>Remarks</th></tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r):
            $st  = strtolower($r['status'] ?? 'absent');
            $sc  = $statusColors[$st] ?? $statusColors['absent'];
          ?>
          <tr>
            <td class="fw-semibold small"><?= date('d M Y', strtotime($r['date'])) ?></td>
            <td class="small text-muted"><?= date('l', strtotime($r['date'])) ?></td>
            <td class="text-center">
              <span class="badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['txt'] ?>;font-size:.75rem">
                <?= $sc['label'] ?>
              </span>
            </td>
            <td class="small text-muted"><?= e($r['remarks'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer-parent.php'; ?>
