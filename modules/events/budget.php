<?php
// ── EVENTS: Budget & Expenses ──────────────────────────────────
$moduleSlug  = 'events';
$moduleName  = 'Event Management';
$moduleIcon  = 'fas fa-calendar-alt';
$moduleColor = '#8e44ad';
$moduleNav   = [
    ['url' => 'index.php',     'icon' => 'fas fa-tachometer-alt', 'label' => 'Dashboard'],
    ['url' => 'events.php',    'icon' => 'fas fa-calendar-alt',   'label' => 'Events'],
    ['url' => 'tickets.php',   'icon' => 'fas fa-ticket-alt',     'label' => 'Tickets'],
    ['url' => 'attendees.php', 'icon' => 'fas fa-users',          'label' => 'Attendees'],
    ['url' => 'schedule.php',  'icon' => 'fas fa-list-ol',        'label' => 'Schedule'],
    ['url' => 'budget.php',    'icon' => 'fas fa-wallet',         'label' => 'Budget'],
    ['url' => 'vendors.php',   'icon' => 'fas fa-store',          'label' => 'Vendors'],
    ['url' => 'sponsors.php',  'icon' => 'fas fa-handshake',      'label' => 'Sponsors'],
    ['url' => 'tasks.php',     'icon' => 'fas fa-tasks',          'label' => 'Tasks'],
    ['url' => 'reports.php',   'icon' => 'fas fa-chart-bar',      'label' => 'Reports'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/functions.php';
    if (session_status() === PHP_SESSION_NONE) session_start();
    verifyCsrf();
    $user  = currentUser();
    $orgId = (int)$user['org_id'];
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id              = (int)($_POST['id']               ?? 0);
        $eventId         = (int)($_POST['event_id']         ?? 0);
        $category        = sanitize($_POST['category']        ?? '');
        $description     = sanitize($_POST['description']     ?? '');
        $type            = in_array($_POST['type'] ?? '', ['income','expense']) ? $_POST['type'] : 'expense';
        $estimatedAmount = (float)($_POST['estimated_amount'] ?? 0);
        $actualAmount    = (float)($_POST['actual_amount']    ?? 0);

        if (!$eventId || !$description) {
            setFlash('danger', 'Event and description are required.');
            redirect('budget.php');
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE event_budget SET event_id=?,category=?,description=?,type=?,estimated_amount=?,actual_amount=? WHERE id=? AND org_id=?")
                ->execute([$eventId, $category, $description, $type, $estimatedAmount, $actualAmount, $id, $orgId]);
            setFlash('success', 'Budget item updated.');
            logActivity('update', 'events', "Updated budget item #$id");
        } else {
            $pdo->prepare("INSERT INTO event_budget (org_id,event_id,category,description,type,estimated_amount,actual_amount) VALUES (?,?,?,?,?,?,?)")
                ->execute([$orgId, $eventId, $category, $description, $type, $estimatedAmount, $actualAmount]);
            setFlash('success', "Budget item '$description' added.");
            logActivity('create', 'events', "Added budget: $description");
        }
        redirect('budget.php' . ($eventId ? '?event_id='.$eventId : ''));
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM event_budget WHERE id=? AND org_id=?")->execute([$id, $orgId]);
        setFlash('success', 'Budget item deleted.');
        logActivity('delete', 'events', "Deleted budget item #$id");
        redirect('budget.php');
    }
}

require_once __DIR__ . '/../../includes/header-module.php';
$user    = currentUser();
$orgId   = (int)$user['org_id'];

$filterEvent = (int)($_GET['event_id'] ?? 0);
$where  = 'b.org_id = ?';
$params = [$orgId];
if ($filterEvent) { $where .= ' AND b.event_id = ?'; $params[] = $filterEvent; }

$budgetItems = [];
$totalEstimatedIncome = $totalActualIncome = $totalEstimatedExpense = $totalActualExpense = 0;
try {
    $stmt = $pdo->prepare("
        SELECT b.*, e.title AS event_title
        FROM event_budget b
        JOIN events e ON b.event_id = e.id
        WHERE $where ORDER BY b.event_id, b.type, b.category
    ");
    $stmt->execute($params);
    $budgetItems = $stmt->fetchAll();

    foreach ($budgetItems as $item) {
        if ($item['type'] === 'income') {
            $totalEstimatedIncome  += $item['estimated_amount'];
            $totalActualIncome     += $item['actual_amount'];
        } else {
            $totalEstimatedExpense += $item['estimated_amount'];
            $totalActualExpense    += $item['actual_amount'];
        }
    }
} catch (Exception $e) {}

$events = [];
try {
    $stmt = $pdo->prepare("SELECT id, title, start_date FROM events WHERE org_id=? AND status NOT IN ('cancelled') ORDER BY start_date DESC");
    $stmt->execute([$orgId]);
    $events = $stmt->fetchAll();
} catch (Exception $e) {}

$netEstimated = $totalEstimatedIncome - $totalEstimatedExpense;
$netActual    = $totalActualIncome    - $totalActualExpense;
?>

<div class="page-header d-flex align-items-center justify-content-between mb-4">
  <div>
    <h4 class="mb-1"><i class="fas fa-wallet me-2" style="color:<?= $moduleColor ?>"></i>Event Budget</h4>
    <p class="text-muted mb-0">Track income and expenses for each event</p>
  </div>
  <button class="btn text-white" style="background:<?= $moduleColor ?>" data-bs-toggle="modal" data-bs-target="#budgetModal" onclick="openAdd()">
    <i class="fas fa-plus me-1"></i>Add Budget Item
  </button>
</div>

<div class="row g-3 mb-4">
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon green-bg"><i class="fas fa-arrow-down"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalActualIncome) ?></div><div class="stat-label">Actual Income</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon danger-bg"><i class="fas fa-arrow-up"></i></div>
      <div><div class="stat-value"><?= formatCurrency($totalActualExpense) ?></div><div class="stat-label">Actual Expenses</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon navy-bg"><i class="fas fa-chart-pie"></i></div>
      <div><div class="stat-value"><?= formatCurrency($netActual) ?></div><div class="stat-label">Net Balance</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="stat-card"><div class="stat-icon warning-bg"><i class="fas fa-file-invoice-dollar"></i></div>
      <div><div class="stat-value"><?= formatCurrency($netEstimated) ?></div><div class="stat-label">Estimated Net</div></div></div>
  </div>
</div>

<!-- Event filter -->
<div class="mb-3 d-flex gap-2 flex-wrap align-items-center">
  <span class="small text-muted fw-semibold me-1">Filter by event:</span>
  <a href="budget.php" class="btn btn-sm <?= !$filterEvent ? 'btn-primary':'btn-outline-secondary' ?>">All Events</a>
  <?php foreach ($events as $ev): ?>
  <a href="budget.php?event_id=<?= $ev['id'] ?>" class="btn btn-sm <?= $filterEvent==$ev['id'] ? 'btn-primary':'btn-outline-secondary' ?>">
    <?= e($ev['title']) ?>
  </a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0"><i class="fas fa-wallet me-2" style="color:<?= $moduleColor ?>"></i>Budget Items</h6>
    <span class="badge bg-secondary"><?= count($budgetItems) ?> items</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover data-table mb-0">
        <thead class="table-light">
          <tr>
            <th>Event</th>
            <th>Type</th>
            <th>Category</th>
            <th>Description</th>
            <th class="text-end">Estimated</th>
            <th class="text-end">Actual</th>
            <th class="text-end">Variance</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($budgetItems)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted"><i class="fas fa-wallet fa-2x mb-2 d-block"></i>No budget items added yet.</td></tr>
          <?php else: foreach ($budgetItems as $b): ?>
          <?php $variance = $b['actual_amount'] - $b['estimated_amount']; ?>
          <tr>
            <td class="small fw-semibold"><?= e($b['event_title']) ?></td>
            <td>
              <?php if ($b['type']==='income'): ?>
                <span class="badge bg-success">Income</span>
              <?php else: ?>
                <span class="badge bg-danger">Expense</span>
              <?php endif; ?>
            </td>
            <td class="small text-muted"><?= e($b['category'] ?: '—') ?></td>
            <td><?= e($b['description']) ?></td>
            <td class="text-end"><?= formatCurrency((float)$b['estimated_amount']) ?></td>
            <td class="text-end fw-semibold"><?= formatCurrency((float)$b['actual_amount']) ?></td>
            <td class="text-end <?= $variance > 0 ? 'text-danger':'text-success' ?>">
              <?= ($variance > 0 ? '+' : '') . formatCurrency($variance) ?>
            </td>
            <td class="text-center" style="white-space:nowrap">
              <button class="btn btn-sm btn-outline-primary" onclick='openEdit(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)'><i class="fas fa-edit"></i></button>
              <form method="POST" class="d-inline ms-1" onsubmit="return confirm('Delete this budget item?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
              </form>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="modal fade" id="budgetModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="bdgId" value="0">
        <div class="modal-header text-white" style="background:<?= $moduleColor ?>">
          <h5 class="modal-title" id="bdgTitle"><i class="fas fa-wallet me-2"></i>Add Budget Item</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Event <span class="text-danger">*</span></label>
              <select name="event_id" id="bdgEvent" class="form-select" required>
                <option value="">— Select Event —</option>
                <?php foreach ($events as $ev): ?>
                <option value="<?= $ev['id'] ?>" <?= $filterEvent==$ev['id'] ? 'selected':'' ?>><?= e($ev['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Type</label>
              <select name="type" id="bdgType" class="form-select">
                <option value="expense">Expense</option>
                <option value="income">Income</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label fw-semibold">Category</label>
              <input type="text" name="category" id="bdgCategory" class="form-control" placeholder="Venue, Catering, Marketing...">
            </div>
            <div class="col-md-12">
              <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
              <input type="text" name="description" id="bdgDesc" class="form-control" required placeholder="e.g. Venue Rental">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Estimated Amount (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="estimated_amount" id="bdgEstimated" class="form-control" step="0.01" min="0" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold">Actual Amount (<?= CURRENCY_SYMBOL ?>)</label>
              <input type="number" name="actual_amount" id="bdgActual" class="form-control" step="0.01" min="0" value="0">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn text-white" style="background:<?= $moduleColor ?>"><i class="fas fa-save me-1"></i>Save Item</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openAdd() {
  document.getElementById("bdgTitle").innerHTML = "<i class=\"fas fa-wallet me-2\"></i>Add Budget Item";
  document.getElementById("bdgId").value        = 0;
  document.getElementById("bdgEvent").value     = "' . $filterEvent . '";
  document.getElementById("bdgType").value      = "expense";
  document.getElementById("bdgCategory").value  = "";
  document.getElementById("bdgDesc").value      = "";
  document.getElementById("bdgEstimated").value = 0;
  document.getElementById("bdgActual").value    = 0;
}
function openEdit(b) {
  document.getElementById("bdgTitle").innerHTML = "<i class=\"fas fa-edit me-2\"></i>Edit Budget Item";
  document.getElementById("bdgId").value        = b.id;
  document.getElementById("bdgEvent").value     = b.event_id         || "";
  document.getElementById("bdgType").value      = b.type             || "expense";
  document.getElementById("bdgCategory").value  = b.category         || "";
  document.getElementById("bdgDesc").value      = b.description      || "";
  document.getElementById("bdgEstimated").value = b.estimated_amount || 0;
  document.getElementById("bdgActual").value    = b.actual_amount    || 0;
  new bootstrap.Modal(document.getElementById("budgetModal")).show();
}
</script>';
require_once __DIR__ . '/../../includes/footer.php';
?>
