<?php
$moduleSlug  = 'crm';
$moduleName  = 'CRM — Customer Relations';
$moduleIcon  = 'fas fa-handshake';
$moduleColor = '#0B2D4E';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'contacts.php',  'icon' => 'fas fa-address-book',   'label' => 'Contacts'],
    ['url' => 'companies.php', 'icon' => 'fas fa-building',       'label' => 'Companies'],
    ['url' => 'leads.php',     'icon' => 'fas fa-filter',         'label' => 'Leads'],
    ['url' => 'deals.php',     'icon' => 'fas fa-handshake',      'label' => 'Deals'],
    ['url' => 'pipeline.php',  'icon' => 'fas fa-columns',        'label' => 'Pipeline'],
    ['url' => 'quotes.php',    'icon' => 'fas fa-file-invoice',   'label' => 'Quotes'],
    ['url' => 'products.php',  'icon' => 'fas fa-box-open',       'label' => 'Products'],
    ['url' => 'activities.php','icon' => 'fas fa-tasks',          'label' => 'Activities'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-check-square',   'label' => 'Tasks'],
    ['url' => 'campaigns.php', 'icon' => 'fas fa-bullhorn',       'label' => 'Campaigns'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

// AJAX: move deal to new stage
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'move') {
        $id    = (int)($_POST['deal_id'] ?? 0);
        $stage = sanitize($_POST['stage'] ?? '');
        $valid = ['prospect','qualified','proposal','negotiation','won','lost'];
        if ($id && in_array($stage, $valid)) {
            $status = $stage === 'won' ? 'won' : ($stage === 'lost' ? 'lost' : 'open');
            $pdo->prepare("UPDATE crm_deals SET stage=?, status=? WHERE id=? AND org_id=?")
                ->execute([$stage, $status, $id, $orgId]);
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false]);
        }
        exit;
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user  = currentUser();
$orgId = (int)$user['org_id'];

$stages = [
    'prospect'    => ['label' => 'Prospect',    'color' => '#6c757d', 'icon' => 'fas fa-binoculars'],
    'qualified'   => ['label' => 'Qualified',   'color' => '#17a2b8', 'icon' => 'fas fa-check'],
    'proposal'    => ['label' => 'Proposal',    'color' => '#ffc107', 'icon' => 'fas fa-file-alt'],
    'negotiation' => ['label' => 'Negotiation', 'color' => '#fd7e14', 'icon' => 'fas fa-comments-dollar'],
    'won'         => ['label' => 'Won',         'color' => '#28a745', 'icon' => 'fas fa-trophy'],
    'lost'        => ['label' => 'Lost',        'color' => '#dc3545', 'icon' => 'fas fa-times-circle'],
];

$deals = [];
try {
    $stmt = $pdo->prepare("
        SELECT d.*, CONCAT(c.first_name,' ',c.last_name) AS contact_name
        FROM crm_deals d
        LEFT JOIN crm_contacts c ON d.contact_id = c.id
        WHERE d.org_id = ?
        ORDER BY d.value DESC
    ");
    $stmt->execute([$orgId]);
    $deals = $stmt->fetchAll();
} catch (Exception $e) {}

// Group by stage
$byStage = array_fill_keys(array_keys($stages), []);
foreach ($deals as $d) {
    $s = $d['stage'] ?? 'prospect';
    if (!isset($byStage[$s])) $byStage['prospect'][] = $d;
    else $byStage[$s][] = $d;
}

// Stage totals
$stageTotals = [];
foreach ($byStage as $s => $list) {
    $stageTotals[$s] = array_sum(array_column($list, 'value'));
}

$totalPipeline = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(value),0) FROM crm_deals WHERE org_id=? AND status='open'");
    $stmt->execute([$orgId]);
    $totalPipeline = (float)$stmt->fetchColumn();
} catch (Exception $e) {}
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-columns me-2" style="color:<?= $moduleColor ?>"></i>Sales Pipeline — Kanban</h4>
    <p class="text-muted mb-0">Drag &amp; drop deals across stages • Total pipeline: <strong><?= formatCurrency($totalPipeline) ?></strong></p>
  </div>
  <a href="deals.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-list me-1"></i>List View</a>
</div>

<!-- Stage summary bar -->
<div class="row g-2 mb-4">
  <?php foreach ($stages as $slug => $meta): ?>
  <div class="col">
    <div class="card text-center border-0 shadow-sm">
      <div class="card-body py-2 px-2">
        <div class="small fw-semibold" style="color:<?= $meta['color'] ?>"><?= $meta['label'] ?></div>
        <div class="fw-bold"><?= count($byStage[$slug]) ?> deals</div>
        <div class="small text-muted"><?= formatCurrency($stageTotals[$slug]) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Kanban Board -->
<div class="kanban-board d-flex gap-3 overflow-auto pb-3" style="min-height:500px;align-items:flex-start">
  <?php foreach ($stages as $slug => $meta): ?>
  <div class="kanban-column flex-shrink-0" style="width:260px" data-stage="<?= $slug ?>">
    <!-- Column header -->
    <div class="kanban-col-header d-flex align-items-center justify-content-between rounded-top px-3 py-2" style="background:<?= $meta['color'] ?>;color:#fff">
      <div class="fw-semibold small"><i class="<?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?></div>
      <span class="badge bg-white" style="color:<?= $meta['color'] ?>"><?= count($byStage[$slug]) ?></span>
    </div>
    <!-- Drop zone -->
    <div class="kanban-drop p-2 rounded-bottom" style="background:#f8f9fa;min-height:420px;border:2px dashed #dee2e6"
         ondragover="event.preventDefault();this.style.borderColor='<?= $meta['color'] ?>'"
         ondragleave="this.style.borderColor='#dee2e6'"
         ondrop="handleDrop(event,'<?= $slug ?>')">
      <?php foreach ($byStage[$slug] as $d): ?>
      <div class="kanban-card card shadow-sm mb-2 cursor-grab"
           draggable="true"
           id="deal-<?= $d['id'] ?>"
           data-id="<?= $d['id'] ?>"
           ondragstart="handleDragStart(event,<?= $d['id'] ?>)"
           ondragend="this.style.opacity=1">
        <div class="card-body p-2">
          <div class="d-flex align-items-start justify-content-between mb-1">
            <div class="fw-semibold small lh-sm" style="color:<?= $moduleColor ?>"><?= e($d['title']) ?></div>
            <a href="deals.php" class="btn btn-sm btn-link p-0 ms-1" style="font-size:.7rem;color:#aaa" title="Edit in Deals"><i class="fas fa-external-link-alt"></i></a>
          </div>
          <?php if ($d['contact_name']): ?>
          <div class="small text-muted mb-1"><i class="fas fa-user me-1"></i><?= e($d['contact_name']) ?></div>
          <?php endif; ?>
          <div class="d-flex align-items-center justify-content-between">
            <span class="fw-bold text-success small"><?= formatCurrency((float)$d['value']) ?></span>
            <div class="d-flex align-items-center gap-1">
              <div class="progress" style="width:50px;height:5px" title="<?= $d['probability'] ?>% probability">
                <div class="progress-bar" style="width:<?= $d['probability'] ?>%;background:<?= $meta['color'] ?>"></div>
              </div>
              <small class="text-muted"><?= $d['probability'] ?>%</small>
            </div>
          </div>
          <?php if ($d['expected_close']): ?>
          <div class="small text-muted mt-1"><i class="fas fa-calendar-alt me-1"></i><?= formatDate($d['expected_close']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (empty($byStage[$slug])): ?>
      <div class="text-center text-muted py-4 small empty-placeholder"><i class="fas fa-inbox d-block mb-1 fa-lg"></i>No deals</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php
$csrf = $_SESSION['csrf_token'] ?? '';
$extraJs = <<<JS
<script>
let dragId = null;

function handleDragStart(ev, id) {
  dragId = id;
  ev.dataTransfer.effectAllowed = 'move';
  ev.target.style.opacity = 0.5;
}

function handleDrop(ev, stage) {
  ev.preventDefault();
  ev.currentTarget.style.borderColor = '#dee2e6';
  if (!dragId) return;

  fetch('pipeline.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body: '_token={$csrf}&action=move&deal_id=' + dragId + '&stage=' + stage
  })
  .then(r => r.json())
  .then(data => {
    if (data.ok) {
      const card = document.getElementById('deal-' + dragId);
      const zone = ev.currentTarget;
      const placeholder = zone.querySelector('.empty-placeholder');
      if (placeholder) placeholder.remove();
      zone.insertBefore(card, zone.querySelector('.empty-placeholder'));
      zone.appendChild(card);
      // Update column counts
      updateCounts();
    }
  })
  .catch(e => console.error(e));
  dragId = null;
}

function updateCounts() {
  document.querySelectorAll('.kanban-column').forEach(col => {
    const count = col.querySelectorAll('.kanban-card').length;
    col.querySelector('.badge').textContent = count;
    const zone = col.querySelector('.kanban-drop');
    if (count === 0 && !zone.querySelector('.empty-placeholder')) {
      const ph = document.createElement('div');
      ph.className = 'text-center text-muted py-4 small empty-placeholder';
      ph.innerHTML = '<i class="fas fa-inbox d-block mb-1 fa-lg"></i>No deals';
      zone.appendChild(ph);
    }
  });
}
</script>
<style>
.kanban-card { cursor: grab; transition: box-shadow .15s; }
.kanban-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.15) !important; }
.kanban-card:active { cursor: grabbing; }
</style>
JS;
require_once __DIR__ . '/../../includes/footer.php';
?>
