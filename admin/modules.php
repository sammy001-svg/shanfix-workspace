<?php
$pageTitle = 'Module Management';
require_once __DIR__ . '/../includes/header-admin.php';

// ── POST: save module edits ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_module') {
        $id           = (int)($_POST['module_id']      ?? 0);
        $name         = sanitize($_POST['name']         ?? '');
        $description  = sanitize($_POST['description']  ?? '');
        $priceMonthly = (float)($_POST['monthly_price'] ?? 0);
        $priceAnnual  = (float)($_POST['annual_price']  ?? 0);
        $icon         = sanitize($_POST['icon']         ?? '');
        $color        = sanitize($_POST['color']        ?? '#1A8A4E');
        $sortOrder    = (int)($_POST['sort_order']      ?? 0);
        $status       = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

        if (!$id || !$name) {
            setFlash('danger', 'Module ID and name are required.');
        } else {
            $pdo->prepare("
                UPDATE modules
                SET name=?, description=?, monthly_price=?, annual_price=?,
                    icon=?, color=?, sort_order=?, status=?
                WHERE id=?
            ")->execute([$name, $description, $priceMonthly, $priceAnnual, $icon, $color, $sortOrder, $status, $id]);
            logActivity('edit_module', 'admin', "Updated module: $name");
            setFlash('success', "Module '$name' updated.");
        }
        redirect(APP_URL . '/admin/modules.php');
    }

    if ($act === 'toggle_module') {
        $id     = (int)($_POST['module_id'] ?? 0);
        $status = ($_POST['current'] ?? '') === 'active' ? 'inactive' : 'active';
        if ($id) {
            $pdo->prepare("UPDATE modules SET status=? WHERE id=?")->execute([$status, $id]);
        }
        redirect(APP_URL . '/admin/modules.php');
    }
}

// ── Data ──────────────────────────────────────────────────────────
$modules = $pdo->query("
    SELECT m.*, COUNT(sm.id) as subscriber_count
    FROM modules m
    LEFT JOIN subscription_modules sm ON m.id = sm.module_id AND sm.status='active'
    GROUP BY m.id ORDER BY m.sort_order
")->fetchAll();

$totalActive   = count(array_filter($modules, fn($m) => $m['status'] === 'active'));
$totalInactive = count($modules) - $totalActive;
$totalSubs     = array_sum(array_column($modules, 'subscriber_count'));
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-puzzle-piece me-2 text-green"></i>Module Management</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0"><li class="breadcrumb-item"><a href="index.php">Dashboard</a></li><li class="breadcrumb-item active">Modules</li></ol></nav>
  </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
  <div class="col-6 col-md-3">
    <div class="stat-card navy"><div class="stat-icon navy-bg"><i class="fas fa-puzzle-piece"></i></div>
      <div><div class="stat-value"><?= count($modules) ?></div><div class="stat-label">Total Modules</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $totalActive ?></div><div class="stat-label">Active</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card warning"><div class="stat-icon warning-bg"><i class="fas fa-pause-circle"></i></div>
      <div><div class="stat-value"><?= $totalInactive ?></div><div class="stat-label">Inactive</div></div></div>
  </div>
  <div class="col-6 col-md-3">
    <div class="stat-card green"><div class="stat-icon green-bg"><i class="fas fa-users"></i></div>
      <div><div class="stat-value"><?= $totalSubs ?></div><div class="stat-label">Total Subscriptions</div></div></div>
  </div>
</div>

<!-- Module grid -->
<div class="row g-3">
  <?php foreach ($modules as $m): ?>
  <div class="col-md-6 col-lg-4">
    <div class="card h-100 <?= $m['status'] === 'inactive' ? 'opacity-75 border-dashed' : '' ?>">
      <div class="card-body">
        <div class="d-flex align-items-start gap-3 mb-3">
          <div style="width:48px;height:48px;border-radius:12px;background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
            <i class="<?= e($m['icon']) ?>"></i>
          </div>
          <div class="flex-fill min-w-0">
            <div class="d-flex align-items-center gap-2 flex-wrap">
              <span class="fw-700 text-navy"><?= e($m['name']) ?></span>
              <?= statusBadge($m['status']) ?>
            </div>
            <div class="text-muted small"><?= e($m['category'] ?? '') ?> &bull; order #<?= $m['sort_order'] ?></div>
          </div>
        </div>

        <p class="small text-muted mb-3" style="min-height:2.5rem"><?= e(substr($m['description'] ?? '', 0, 90)) ?><?= strlen($m['description'] ?? '') > 90 ? '…' : '' ?></p>

        <div class="row g-2 mb-3">
          <div class="col-6">
            <div class="rounded p-2 text-center" style="background:var(--green-pale)">
              <div class="fw-700 text-green small">KES <?= number_format($m['monthly_price']) ?></div>
              <div class="text-muted" style="font-size:.7rem">/ month</div>
            </div>
          </div>
          <div class="col-6">
            <div class="rounded p-2 text-center" style="background:var(--gray-100)">
              <div class="fw-700 small">KES <?= number_format($m['annual_price']) ?></div>
              <div class="text-muted" style="font-size:.7rem">/ year</div>
            </div>
          </div>
        </div>

        <div class="d-flex align-items-center justify-content-between">
          <span class="badge bg-primary"><i class="fas fa-users me-1"></i><?= $m['subscriber_count'] ?> subscribers</span>
          <div class="d-flex gap-1">
            <button class="btn btn-xs btn-outline-secondary"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($m), ENT_QUOTES) ?>)"
                    title="Edit module">
              <i class="fas fa-edit"></i>
            </button>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="toggle_module">
              <input type="hidden" name="module_id" value="<?= $m['id'] ?>">
              <input type="hidden" name="current" value="<?= e($m['status']) ?>">
              <button type="submit"
                      class="btn btn-xs <?= $m['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>"
                      title="<?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?>"
                      data-confirm="<?= $m['status'] === 'active' ? 'Deactivate' : 'Activate' ?> the <?= e($m['name']) ?> module?">
                <i class="fas fa-<?= $m['status'] === 'active' ? 'pause' : 'play' ?>"></i>
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Module Edit Modal -->
<div class="modal fade" id="editModuleModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Module: <span id="editModTitle"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="save_module">
        <input type="hidden" name="module_id" id="editModId">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label">Module Name *</label>
              <input type="text" name="name" id="editModName" class="form-control" required>
            </div>
            <div class="col-md-3">
              <label class="form-label">Status</label>
              <select name="status" id="editModStatus" class="form-select">
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label">Sort Order</label>
              <input type="number" name="sort_order" id="editModSort" class="form-control" min="0">
            </div>
            <div class="col-12">
              <label class="form-label">Description</label>
              <textarea name="description" id="editModDesc" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label">Monthly Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="monthly_price" id="editModPriceM" class="form-control" min="0" step="1">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Annual Price (KES)</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="annual_price" id="editModPriceA" class="form-control" min="0" step="1">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Icon class <span class="text-muted small">(Font Awesome)</span></label>
              <div class="input-group">
                <span class="input-group-text"><i id="iconPreview" class="fas fa-puzzle-piece"></i></span>
                <input type="text" name="icon" id="editModIcon" class="form-control" placeholder="fas fa-puzzle-piece"
                       oninput="document.getElementById('iconPreview').className=this.value">
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Accent Color</label>
              <div class="input-group">
                <input type="color" name="color" id="editModColor" class="form-control form-control-color" style="max-width:48px">
                <input type="text" id="editModColorText" class="form-control" placeholder="#1A8A4E" readonly>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function openEditModal(m) {
  document.getElementById("editModTitle").textContent  = m.name;
  document.getElementById("editModId").value           = m.id;
  document.getElementById("editModName").value         = m.name;
  document.getElementById("editModDesc").value         = m.description || "";
  document.getElementById("editModPriceM").value       = m.monthly_price;
  document.getElementById("editModPriceA").value       = m.annual_price;
  document.getElementById("editModSort").value         = m.sort_order;
  document.getElementById("editModStatus").value       = m.status;
  document.getElementById("editModIcon").value         = m.icon || "";
  document.getElementById("iconPreview").className     = m.icon || "fas fa-puzzle-piece";
  document.getElementById("editModColor").value        = m.color || "#1A8A4E";
  document.getElementById("editModColorText").value    = m.color || "#1A8A4E";
  new bootstrap.Modal(document.getElementById("editModuleModal")).show();
}
document.getElementById("editModColor").addEventListener("input", function() {
  document.getElementById("editModColorText").value = this.value;
});
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
