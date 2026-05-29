<?php
$pageTitle = 'Promo Codes';
require_once __DIR__ . '/../includes/header-admin.php';

// ── Auto-create table ─────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS promo_codes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(50) NOT NULL UNIQUE,
        description VARCHAR(200),
        discount_type ENUM('percentage','fixed') DEFAULT 'percentage',
        discount_value DECIMAL(10,2) NOT NULL,
        min_amount DECIMAL(14,2) DEFAULT 0,
        max_uses INT DEFAULT 0 COMMENT '0=unlimited',
        uses_count INT DEFAULT 0,
        valid_from DATE,
        valid_to DATE,
        applies_to ENUM('all','monthly','annual') DEFAULT 'all',
        is_active TINYINT(1) DEFAULT 1,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_code (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    error_log('[promo-codes] table create: ' . $e->getMessage());
}

// ── POST handlers ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Save (add/edit) ──
    if ($action === 'save_promo') {
        $id            = (int)($_POST['promo_id'] ?? 0);
        $code          = strtoupper(trim(sanitize($_POST['code'] ?? '')));
        $description   = sanitize($_POST['description'] ?? '');
        $discountType  = in_array($_POST['discount_type'] ?? '', ['percentage','fixed']) ? $_POST['discount_type'] : 'percentage';
        $discountValue = (float)($_POST['discount_value'] ?? 0);
        $minAmount     = (float)($_POST['min_amount'] ?? 0);
        $maxUses       = (int)($_POST['max_uses'] ?? 0);
        $validFrom     = $_POST['valid_from'] ?? null;
        $validTo       = $_POST['valid_to'] ?? null;
        $appliesTo     = in_array($_POST['applies_to'] ?? '', ['all','monthly','annual']) ? $_POST['applies_to'] : 'all';
        $isActive      = isset($_POST['is_active']) ? 1 : 0;

        $validFrom = $validFrom ?: null;
        $validTo   = $validTo   ?: null;

        if (!$code) {
            setFlash('danger', 'Promo code is required.');
        } elseif ($discountValue <= 0) {
            setFlash('danger', 'Discount value must be greater than zero.');
        } elseif ($discountType === 'percentage' && $discountValue > 100) {
            setFlash('danger', 'Percentage discount cannot exceed 100%.');
        } else {
            try {
                if ($id) {
                    $pdo->prepare("
                        UPDATE promo_codes SET code=?,description=?,discount_type=?,discount_value=?,
                        min_amount=?,max_uses=?,valid_from=?,valid_to=?,applies_to=?,is_active=?
                        WHERE id=?
                    ")->execute([$code,$description,$discountType,$discountValue,$minAmount,$maxUses,$validFrom,$validTo,$appliesTo,$isActive,$id]);
                    setFlash('success', "Promo code <strong>{$code}</strong> updated.");
                    logActivity('edit', 'promo_codes', "Updated promo code: {$code}");
                } else {
                    $pdo->prepare("
                        INSERT INTO promo_codes (code,description,discount_type,discount_value,min_amount,max_uses,valid_from,valid_to,applies_to,is_active,created_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ")->execute([$code,$description,$discountType,$discountValue,$minAmount,$maxUses,$validFrom,$validTo,$appliesTo,$isActive,(int)$user['id']]);
                    setFlash('success', "Promo code <strong>{$code}</strong> created.");
                    logActivity('create', 'promo_codes', "Created promo code: {$code}");
                }
            } catch (Exception $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    setFlash('danger', "Code <strong>{$code}</strong> already exists.");
                } else {
                    setFlash('danger', 'Database error: ' . $e->getMessage());
                }
            }
        }
        redirect(APP_URL . '/admin/promo-codes.php');
    }

    // ── Toggle active ──
    if ($action === 'toggle_promo') {
        $id = (int)($_POST['promo_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE promo_codes SET is_active = 1 - is_active WHERE id=?")->execute([$id]);
            setFlash('success', 'Promo code status toggled.');
            logActivity('toggle', 'promo_codes', "Toggled promo code #{$id}");
        } catch (Exception $e) {
            setFlash('danger', 'Could not toggle promo code.');
        }
        redirect(APP_URL . '/admin/promo-codes.php');
    }
}

// ── Load data ─────────────────────────────────────────────────────
$codes = [];
$kpi   = ['total' => 0, 'active' => 0, 'total_uses' => 0, 'most_used' => '—'];
try {
    $codes = $pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC")->fetchAll();
    $kpi['total']      = count($codes);
    $kpi['active']     = count(array_filter($codes, fn($c) => $c['is_active']));
    $kpi['total_uses'] = array_sum(array_column($codes, 'uses_count'));
    if ($codes) {
        usort($codes, fn($a,$b) => $b['uses_count'] <=> $a['uses_count']);
        $kpi['most_used'] = $codes[0]['uses_count'] > 0 ? $codes[0]['code'] . ' (' . $codes[0]['uses_count'] . 'x)' : '—';
        // Re-sort by created_at desc for display
        usort($codes, fn($a,$b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    }
} catch (Exception $e) {
    error_log('[promo-codes] load: ' . $e->getMessage());
}
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-tags me-2 text-green"></i>Promo Codes</h4>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-0">
        <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
        <li class="breadcrumb-item active">Promo Codes</li>
      </ol>
    </nav>
  </div>
  <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#promoModal" onclick="resetPromoForm()">
    <i class="fas fa-plus me-2"></i>New Promo Code
  </button>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card navy">
      <div class="stat-icon navy-bg"><i class="fas fa-tags"></i></div>
      <div><div class="stat-value"><?= $kpi['total'] ?></div><div class="stat-label">Total Codes</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card green">
      <div class="stat-icon green-bg"><i class="fas fa-check-circle"></i></div>
      <div><div class="stat-value"><?= $kpi['active'] ?></div><div class="stat-label">Active Codes</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card warning">
      <div class="stat-icon warning-bg"><i class="fas fa-fire"></i></div>
      <div><div class="stat-value"><?= number_format($kpi['total_uses']) ?></div><div class="stat-label">Total Uses</div></div>
    </div>
  </div>
  <div class="col-sm-6 col-lg-3">
    <div class="stat-card info">
      <div class="stat-icon info-bg"><i class="fas fa-trophy"></i></div>
      <div><div class="stat-value" style="font-size:1rem"><?= e($kpi['most_used']) ?></div><div class="stat-label">Most Used</div></div>
    </div>
  </div>
</div>

<!-- Table card -->
<div class="card">
  <div class="card-header fw-bold d-flex align-items-center justify-content-between">
    <span><i class="fas fa-list text-green me-2"></i>All Promo Codes</span>
  </div>
  <div class="card-body p-0">
    <?php if (empty($codes)): ?>
    <div class="text-center py-5 text-muted">
      <i class="fas fa-tags fa-3x mb-3 d-block opacity-25"></i>
      <p>No promo codes yet. Create one to offer discounts to your clients.</p>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#promoModal" onclick="resetPromoForm()">
        <i class="fas fa-plus me-1"></i>Add First Code
      </button>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0" id="promoTable">
        <thead class="table-light">
          <tr>
            <th>Code</th>
            <th>Description</th>
            <th>Discount</th>
            <th>Applies To</th>
            <th>Min Amount</th>
            <th>Valid From</th>
            <th>Valid To</th>
            <th>Uses</th>
            <th>Status</th>
            <th class="text-center">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($codes as $c):
            $today = date('Y-m-d');
            $expired = $c['valid_to'] && $c['valid_to'] < $today;
            $notStarted = $c['valid_from'] && $c['valid_from'] > $today;
            $maxReached = $c['max_uses'] > 0 && $c['uses_count'] >= $c['max_uses'];
          ?>
          <tr>
            <td>
              <code class="bg-light px-2 py-1 rounded fw-bold" style="font-size:.9rem;letter-spacing:1px">
                <?= e($c['code']) ?>
              </code>
            </td>
            <td class="text-muted small"><?= e($c['description'] ?: '—') ?></td>
            <td class="fw-bold">
              <?php if ($c['discount_type'] === 'percentage'): ?>
                <span class="text-success"><?= (float)$c['discount_value'] ?>%</span>
                <span class="badge bg-info-subtle text-info ms-1 small">%</span>
              <?php else: ?>
                <span class="text-primary"><?= formatCurrency((float)$c['discount_value']) ?></span>
                <span class="badge bg-primary-subtle text-primary ms-1 small">fixed</span>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge bg-secondary-subtle text-secondary"><?= ucfirst($c['applies_to']) ?></span>
            </td>
            <td class="small"><?= $c['min_amount'] > 0 ? formatCurrency((float)$c['min_amount']) : '—' ?></td>
            <td class="small"><?= $c['valid_from'] ? formatDate($c['valid_from']) : '—' ?></td>
            <td class="small <?= $expired ? 'text-danger fw-semibold' : '' ?>">
              <?= $c['valid_to'] ? formatDate($c['valid_to']) : '—' ?>
              <?php if ($expired): ?><br><span class="badge bg-danger" style="font-size:.65rem">Expired</span><?php endif; ?>
            </td>
            <td>
              <span class="fw-bold"><?= $c['uses_count'] ?></span>
              <?php if ($c['max_uses'] > 0): ?>
              <span class="text-muted small">/ <?= $c['max_uses'] ?></span>
              <?php if ($maxReached): ?>
              <br><span class="badge bg-warning text-dark" style="font-size:.65rem">Limit reached</span>
              <?php endif; ?>
              <?php else: ?>
              <span class="text-muted small">/ ∞</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!$c['is_active']): ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php elseif ($expired): ?>
                <span class="badge bg-danger">Expired</span>
              <?php elseif ($notStarted): ?>
                <span class="badge bg-info">Scheduled</span>
              <?php elseif ($maxReached): ?>
                <span class="badge bg-warning text-dark">Limit Reached</span>
              <?php else: ?>
                <span class="badge bg-success">Active</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <div class="d-flex gap-1 justify-content-center">
                <button class="btn btn-sm btn-outline-primary"
                        onclick="editPromo(<?= htmlspecialchars(json_encode($c), ENT_QUOTES) ?>)"
                        data-bs-toggle="modal" data-bs-target="#promoModal"
                        title="Edit">
                  <i class="fas fa-edit"></i>
                </button>
                <form method="POST" class="d-inline">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="toggle_promo">
                  <input type="hidden" name="promo_id" value="<?= $c['id'] ?>">
                  <button type="submit" class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                          title="<?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>"
                          onclick="return confirm('<?= $c['is_active'] ? 'Deactivate' : 'Activate' ?> this promo code?')">
                    <i class="fas <?= $c['is_active'] ? 'fa-ban' : 'fa-check' ?>"></i>
                  </button>
                </form>
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

<!-- ── Add / Edit Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="promoModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <form method="POST" id="promoForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save_promo">
        <input type="hidden" name="promo_id" id="promoId" value="0">

        <div class="modal-header">
          <h5 class="modal-title fw-bold" id="promoModalTitle">
            <i class="fas fa-tag me-2 text-green"></i>New Promo Code
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">
          <div class="row g-3">

            <!-- Code -->
            <div class="col-sm-8">
              <label class="form-label fw-semibold">Promo Code <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="text" name="code" id="promoCode" class="form-control text-uppercase fw-bold"
                       placeholder="e.g. SAVE20" maxlength="50" required
                       style="letter-spacing:2px;font-family:monospace">
                <button type="button" class="btn btn-outline-secondary" onclick="generateCode()" title="Auto-generate">
                  <i class="fas fa-random me-1"></i>Generate
                </button>
              </div>
              <div class="form-text">Code is stored in UPPERCASE. Share this with customers.</div>
            </div>

            <!-- Is Active -->
            <div class="col-sm-4 d-flex align-items-center pt-3">
              <div class="form-check form-switch mt-1">
                <input type="checkbox" name="is_active" id="promoIsActive" class="form-check-input" value="1" checked>
                <label class="form-check-label fw-semibold" for="promoIsActive">Active</label>
              </div>
            </div>

            <!-- Description -->
            <div class="col-12">
              <label class="form-label fw-semibold">Description</label>
              <input type="text" name="description" id="promoDescription" class="form-control"
                     placeholder="e.g. 20% off for new customers" maxlength="200">
            </div>

            <!-- Discount Type -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Discount Type <span class="text-danger">*</span></label>
              <select name="discount_type" id="promoDiscountType" class="form-select" onchange="updateValueLabel()">
                <option value="percentage">Percentage (%)</option>
                <option value="fixed">Fixed Amount (KES)</option>
              </select>
            </div>

            <!-- Discount Value -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold" id="discountValueLabel">Discount Value (%) <span class="text-danger">*</span></label>
              <input type="number" name="discount_value" id="promoDiscountValue" class="form-control"
                     min="0.01" step="0.01" placeholder="e.g. 20" required>
            </div>

            <!-- Min Amount -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Minimum Order Amount</label>
              <div class="input-group">
                <span class="input-group-text">KES</span>
                <input type="number" name="min_amount" id="promoMinAmount" class="form-control"
                       min="0" step="0.01" placeholder="0 = no minimum">
              </div>
              <div class="form-text">Leave 0 for no minimum.</div>
            </div>

            <!-- Max Uses -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Max Uses</label>
              <input type="number" name="max_uses" id="promoMaxUses" class="form-control"
                     min="0" step="1" placeholder="0 = unlimited">
              <div class="form-text">0 = unlimited uses.</div>
            </div>

            <!-- Valid From -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Valid From</label>
              <input type="date" name="valid_from" id="promoValidFrom" class="form-control">
            </div>

            <!-- Valid To -->
            <div class="col-sm-6">
              <label class="form-label fw-semibold">Valid To (Expiry)</label>
              <input type="date" name="valid_to" id="promoValidTo" class="form-control">
            </div>

            <!-- Applies To -->
            <div class="col-12">
              <label class="form-label fw-semibold">Applies To</label>
              <div class="d-flex gap-3">
                <div class="form-check">
                  <input type="radio" name="applies_to" id="appAll" value="all" class="form-check-input" checked>
                  <label class="form-check-label" for="appAll">All Plans</label>
                </div>
                <div class="form-check">
                  <input type="radio" name="applies_to" id="appMonthly" value="monthly" class="form-check-input">
                  <label class="form-check-label" for="appMonthly">Monthly Only</label>
                </div>
                <div class="form-check">
                  <input type="radio" name="applies_to" id="appAnnual" value="annual" class="form-check-input">
                  <label class="form-check-label" for="appAnnual">Annual Only</label>
                </div>
              </div>
            </div>

          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary fw-bold">
            <i class="fas fa-save me-2"></i><span id="promoSaveBtnText">Create Code</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
function resetPromoForm() {
  document.getElementById("promoId").value = "0";
  document.getElementById("promoModalTitle").innerHTML = \'<i class="fas fa-tag me-2 text-green"></i>New Promo Code\';
  document.getElementById("promoSaveBtnText").textContent = "Create Code";
  document.getElementById("promoForm").reset();
  document.getElementById("promoIsActive").checked = true;
  document.querySelectorAll(\'input[name="applies_to"]\').forEach(r => { r.checked = r.value === "all"; });
  updateValueLabel();
}

function editPromo(c) {
  document.getElementById("promoId").value = c.id;
  document.getElementById("promoModalTitle").innerHTML = \'<i class="fas fa-edit me-2 text-green"></i>Edit Promo Code\';
  document.getElementById("promoSaveBtnText").textContent = "Save Changes";
  document.getElementById("promoCode").value = c.code;
  document.getElementById("promoDescription").value = c.description || "";
  document.getElementById("promoDiscountType").value = c.discount_type;
  document.getElementById("promoDiscountValue").value = c.discount_value;
  document.getElementById("promoMinAmount").value = c.min_amount;
  document.getElementById("promoMaxUses").value = c.max_uses;
  document.getElementById("promoValidFrom").value = c.valid_from || "";
  document.getElementById("promoValidTo").value = c.valid_to || "";
  document.getElementById("promoIsActive").checked = c.is_active == 1;
  document.querySelectorAll(\'input[name="applies_to"]\').forEach(r => { r.checked = r.value === c.applies_to; });
  updateValueLabel();
}

function generateCode() {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let code = "";
  for (let i = 0; i < 8; i++) code += chars.charAt(Math.floor(Math.random() * chars.length));
  document.getElementById("promoCode").value = code;
}

function updateValueLabel() {
  const type = document.getElementById("promoDiscountType").value;
  const lbl  = document.getElementById("discountValueLabel");
  lbl.innerHTML = type === "percentage"
    ? "Discount Value (%) <span class=\"text-danger\">*</span>"
    : "Discount Value (KES) <span class=\"text-danger\">*</span>";
  const inp = document.getElementById("promoDiscountValue");
  inp.placeholder = type === "percentage" ? "e.g. 20 (for 20%)" : "e.g. 500";
  if (type === "percentage") { inp.max = 100; } else { inp.removeAttribute("max"); }
}

document.getElementById("promoCode").addEventListener("input", function() {
  this.value = this.value.toUpperCase();
});

// Init DataTable if available
if (typeof $.fn !== "undefined" && $.fn.DataTable) {
  $(function() {
    if ($("#promoTable").length) {
      $("#promoTable").DataTable({
        pageLength: 25,
        order: [[7,"desc"]],
        columnDefs: [{ orderable: false, targets: [9] }]
      });
    }
  });
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
