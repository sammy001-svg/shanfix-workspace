<?php
$moduleSlug  = 'church';
$moduleName  = 'Church Management';
$moduleIcon  = 'fas fa-church';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt',     'label' => 'Dashboard'],
    ['url' => 'members.php',   'icon' => 'fas fa-users',              'label' => 'Members'],
    ['url' => 'offerings.php', 'icon' => 'fas fa-hand-holding-heart', 'label' => 'Offerings'],
    ['url' => 'expenses.php',  'icon' => 'fas fa-money-bill-wave',    'label' => 'Expenses'],
    ['url' => 'attendance.php','icon' => 'fas fa-clipboard-check',    'label' => 'Attendance'],
    ['url' => 'sermons.php',   'icon' => 'fas fa-bible',              'label' => 'Sermons'],
    ['url' => 'prayers.php',   'icon' => 'fas fa-praying-hands',      'label' => 'Prayer Requests'],
    ['url' => 'pastoral.php',  'icon' => 'fas fa-hands-helping',      'label' => 'Pastoral Care'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',       'label' => 'Events'],
    ['url' => 'cells.php',     'icon' => 'fas fa-home',               'label' => 'Cells / Groups'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',          'label' => 'Reports'],
];

require_once __DIR__ . '/../../includes/header-module.php';
$user = currentUser();
$orgId = (int)$user['org_id'];

// Fetch dynamically grouped cells
$cellsList = [];
try {
    $stmt = $pdo->prepare("SELECT cell_group, COUNT(*) AS total_members
                           FROM church_members
                           WHERE org_id = ? AND cell_group != '' AND status = 'active'
                           GROUP BY cell_group
                           ORDER BY total_members DESC");
    $stmt->execute([$orgId]);
    $cellsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch dynamically grouped departments
$deptsList = [];
try {
    $stmt = $pdo->prepare("SELECT department, COUNT(*) AS total_members
                           FROM church_members
                           WHERE org_id = ? AND department != '' AND status = 'active'
                           GROUP BY department
                           ORDER BY total_members DESC");
    $stmt->execute([$orgId]);
    $deptsList = $stmt->fetchAll();
} catch (Exception $e) {}

// Fetch details for specific selected category
$selectedGroup = $_GET['group'] ?? '';
$selectedDept  = $_GET['dept'] ?? '';
$detailsList   = [];

if ($selectedGroup !== '') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM church_members WHERE org_id = ? AND cell_group = ? ORDER BY first_name ASC");
        $stmt->execute([$orgId, $selectedGroup]);
        $detailsList = $stmt->fetchAll();
    } catch (Exception $e) {}
} elseif ($selectedDept !== '') {
    try {
        $stmt = $pdo->prepare("SELECT * FROM church_members WHERE org_id = ? AND department = ? ORDER BY first_name ASC");
        $stmt->execute([$orgId, $selectedDept]);
        $detailsList = $stmt->fetchAll();
    } catch (Exception $e) {}
}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-home me-2" style="color:<?= $moduleColor ?>"></i>Home Cells & Departments</h4>
    <p class="text-muted mb-0">Browse home cell fellowships and congregation ministerial departments</p>
  </div>
  <a href="members.php" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-user-plus me-2"></i>Assign Member</a>
</div>

<div class="row g-4 mb-4">
  <!-- Fellowship Cells -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-home me-2 text-primary"></i>Active Home Cells / Fellowships</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Home Cell Group</th><th class="text-center">Active Members</th><th class="text-center">Action</th></tr>
            </thead>
            <tbody>
              <?php if (empty($cellsList)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No home cell groupings documented yet.</td></tr>
              <?php else: foreach ($cellsList as $c): ?>
              <tr class="<?= $selectedGroup === $c['cell_group'] ? 'table-primary' : '' ?>">
                <td class="fw-bold text-dark"><?= e($c['cell_group']) ?></td>
                <td class="text-center fw-semibold"><?= $c['total_members'] ?> members</td>
                <td class="text-center">
                  <a href="cells.php?group=<?= urlencode($c['cell_group']) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>View Roster</a>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Ministerial Departments -->
  <div class="col-md-6">
    <div class="card h-100">
      <div class="card-header"><h6 class="mb-0 fw-bold text-dark"><i class="fas fa-users-cog me-2 text-primary"></i>Ministerial Departments</h6></div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead class="table-light">
              <tr><th>Department Name</th><th class="text-center">Roster Size</th><th class="text-center">Action</th></tr>
            </thead>
            <tbody>
              <?php if (empty($deptsList)): ?>
              <tr><td colspan="3" class="text-center text-muted py-4">No ministerial departments documented.</td></tr>
              <?php else: foreach ($deptsList as $d): ?>
              <tr class="<?= $selectedDept === $d['department'] ? 'table-primary' : '' ?>">
                <td class="fw-bold text-dark"><?= e($d['department']) ?></td>
                <td class="text-center fw-semibold"><?= $d['total_members'] ?> active</td>
                <td class="text-center">
                  <a href="cells.php?dept=<?= urlencode($d['department']) ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-eye me-1"></i>View Roster</a>
                </td>
              </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($selectedGroup !== '' || $selectedDept !== ''): ?>
<!-- Category members list -->
<div class="card mb-5">
  <div class="card-header bg-light">
    <h6 class="mb-0 text-dark fw-bold">
      <i class="fas fa-users me-2 text-primary"></i>
      Roster: <?= $selectedGroup !== '' ? 'Home Cell "'.e($selectedGroup).'"' : 'Department "'.e($selectedDept).'"' ?>
    </h6>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead class="table-light">
          <tr><th>Member Name</th><th>Member No</th><th>Gender</th><th>Phone Contact</th><th>Baptized</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php if (empty($detailsList)): ?>
          <tr><td colspan="6" class="text-center text-muted py-4">No active members found in this cluster.</td></tr>
          <?php else: foreach ($detailsList as $m): ?>
          <tr>
            <td class="fw-bold text-dark"><?= e($m['first_name'] . ' ' . $m['last_name']) ?></td>
            <td><span class="badge bg-light text-dark border"><?= e($m['member_no']) ?></span></td>
            <td><?= ucfirst($m['gender']) ?></td>
            <td><?= e($m['phone']) ?></td>
            <td>
              <?php if ($m['baptized']): ?>
              <span class="badge bg-success small"><i class="fas fa-water me-1"></i>Baptized</span>
              <?php else: ?>
              <span class="badge bg-secondary small">Unbaptized</span>
              <?php endif; ?>
            </td>
            <td><span class="badge bg-success"><?= ucfirst($m['status']) ?></span></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>
