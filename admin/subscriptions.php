<?php
$pageTitle = 'Subscriptions';
require_once __DIR__ . '/../includes/header-admin.php';

// ── POST: extend subscription ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'extend_subscription') {
        verifyCsrf();
        $subId   = (int)($_POST['sub_id']  ?? 0);
        $endsAt  = $_POST['ends_at']       ?? '';
        $status  = $_POST['status']        ?? 'active';
        $planId  = (int)($_POST['plan_id'] ?? 0);
        $billing = in_array($_POST['billing_cycle'] ?? '', ['monthly','annual','custom']) ? $_POST['billing_cycle'] : 'monthly';
        $amount  = (float)($_POST['amount'] ?? 0);

        if ($subId && $endsAt) {
            $stmt = $pdo->prepare("
                UPDATE subscriptions
                SET ends_at=?, status=?, plan_id=COALESCE(NULLIF(?,0),plan_id),
                    billing_cycle=?, amount=CASE WHEN ? > 0 THEN ? ELSE amount END
                WHERE id=?
            ");
            $stmt->execute([$endsAt, $status, $planId, $billing, $amount, $amount, $subId]);
            logActivity('extend_subscription', 'admin', "Subscription #$subId updated to $status until $endsAt");
            setFlash('success', 'Subscription updated successfully.');
        } else {
            setFlash('danger', 'Subscription ID and end date are required.');
        }
        redirect(APP_URL . '/admin/subscriptions.php' . (isset($_POST['org_id']) ? '?org=' . (int)$_POST['org_id'] : ''));
    }

    if ($act === 'suspend_subscription') {
        verifyCsrf();
        $subId = (int)($_POST['sub_id'] ?? 0);
        if ($subId) {
            $pdo->prepare("UPDATE subscriptions SET status='suspended' WHERE id=?")->execute([$subId]);
            logActivity('suspend_subscription', 'admin', "Subscription #$subId suspended");
            setFlash('warning', 'Subscription suspended.');
        }
        redirect(APP_URL . '/admin/subscriptions.php' . (isset($_POST['org_id']) ? '?org=' . (int)$_POST['org_id'] : ''));
    }

    if ($act === 'reactivate_subscription') {
        verifyCsrf();
        $subId = (int)($_POST['sub_id'] ?? 0);
        if ($subId) {
            $pdo->prepare("UPDATE subscriptions SET status='active' WHERE id=?")->execute([$subId]);
            logActivity('reactivate_subscription', 'admin', "Subscription #$subId reactivated");
            setFlash('success', 'Subscription reactivated.');
        }
        redirect(APP_URL . '/admin/subscriptions.php' . (isset($_POST['org_id']) ? '?org=' . (int)$_POST['org_id'] : ''));
    }

    if ($act === 'generate_renewal_invoice') {
        verifyCsrf();
        $subId = (int)($_POST['sub_id'] ?? 0);
        $orgId = (int)($_POST['org_id'] ?? 0);
        if ($subId && $orgId) {
            $s      = $pdo->prepare("SELECT s.*, p.name AS plan_name FROM subscriptions s LEFT JOIN subscription_plans p ON s.plan_id=p.id WHERE s.id=?");
            $s->execute([$subId]);
            $subRow = $s->fetch();
            if ($subRow) {
                $amount    = (float)$subRow['amount'];
                $tax       = round($amount * 0.16, 2);
                $total     = $amount + $tax;
                $prefix    = 'INV';
                try { $r = $pdo->query("SELECT `value` FROM system_settings WHERE `key`='invoice_prefix' LIMIT 1"); $prefix = $r->fetchColumn() ?: 'INV'; } catch (Exception $e) {}
                $invoiceNo = $prefix . '-' . strtoupper(substr(md5(uniqid($orgId, true)), 0, 8));
                $dueDate   = date('Y-m-d', strtotime('+30 days'));
                $notes     = 'Renewal: ' . ($subRow['plan_name'] ?? 'Subscription') . ' (' . ($subRow['billing_cycle'] ?? 'monthly') . ')';
                $pdo->prepare("INSERT INTO invoices (org_id, subscription_id, invoice_number, amount, tax, total, status, due_date, notes) VALUES (?,?,?,?,?,?,'sent',?,?)")
                    ->execute([$orgId, $subId, $invoiceNo, $amount, $tax, $total, $dueDate, $notes]);
                $invId = (int)$pdo->lastInsertId();

                // Notify org users in-app and email the client admin
                try {
                    require_once __DIR__ . '/../includes/notifications.php';
                    require_once __DIR__ . '/../includes/mailer.php';
                    notifyOrg($orgId,
                        'New Invoice: ' . $invoiceNo,
                        'A renewal invoice of KES ' . number_format($total, 2) . ' has been generated, due on ' . date('d M Y', strtotime($dueDate)) . '.',
                        'info',
                        APP_URL . '/client/billing.php'
                    );
                    $admRow = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='client_admin' LIMIT 1");
                    $admRow->execute([$orgId]);
                    $adm = $admRow->fetch();
                    if ($adm) {
                        mailer()->sendInvoice($adm['email'], $adm['name'], [
                            'invoice_number' => $invoiceNo,
                            'amount'         => $amount,
                            'tax'            => $tax,
                            'total'          => $total,
                            'due_date'       => date('d M Y', strtotime($dueDate)),
                        ]);
                    }
                } catch (Exception $e) {
                    error_log('[invoice notify] ' . $e->getMessage());
                }

                logActivity('generate_invoice', 'admin', "Renewal invoice {$invoiceNo} for org #{$orgId}");
                setFlash('success', "Renewal invoice <strong>{$invoiceNo}</strong> generated. <a href='" . APP_URL . "/admin/invoice-pdf.php?id={$invId}' target='_blank'>View PDF →</a>");
            }
        }
        redirect(APP_URL . '/admin/subscriptions.php' . ($orgId ? '?org=' . $orgId : ''));
    }

    if ($act === 'save_sub_modules') {
        $subId      = (int)($_POST['sub_id'] ?? 0);
        $moduleIds  = array_map('intval', $_POST['module_ids'] ?? []);

        if ($subId) {
            // Deactivate all current modules
            $pdo->prepare("UPDATE subscription_modules SET status='inactive' WHERE subscription_id=?")->execute([$subId]);

            // Re-insert / reactivate selected modules
            foreach ($moduleIds as $mid) {
                if (!$mid) continue;
                $pdo->prepare("
                    INSERT INTO subscription_modules (subscription_id, module_id, status)
                    VALUES (?, ?, 'active')
                    ON DUPLICATE KEY UPDATE status='active'
                ")->execute([$subId, $mid]);
            }
            logActivity('update_sub_modules', 'admin', "Modules updated for subscription #$subId");
            setFlash('success', 'Module assignments updated.');
        }
        redirect(APP_URL . '/admin/subscriptions.php' . (isset($_POST['org_id']) ? '?org=' . (int)$_POST['org_id'] : ''));
    }
}

// ── Data ──────────────────────────────────────────────────────────
$orgFilter = (int)($_GET['org'] ?? 0);
$whereOrg  = $orgFilter ? "AND s.org_id = $orgFilter" : '';

$subscriptions = $pdo->query("
    SELECT s.*, o.name as org_name, o.email as org_email,
           p.name as plan_name,
           COUNT(DISTINCT sm.module_id) as module_count
    FROM subscriptions s
    JOIN organizations o ON s.org_id = o.id
    LEFT JOIN subscription_plans p ON s.plan_id = p.id
    LEFT JOIN subscription_modules sm ON s.id = sm.subscription_id AND sm.status='active'
    WHERE 1=1 $whereOrg
    GROUP BY s.id ORDER BY s.created_at DESC
")->fetchAll();

$plans   = $pdo->query("SELECT id, name, price_monthly, price_annual FROM subscription_plans WHERE status='active' ORDER BY price_monthly")->fetchAll();
$modules = $pdo->query("SELECT id, name, slug, icon, color FROM modules WHERE status='active' ORDER BY sort_order")->fetchAll();

// Org name for filter banner
$filterOrg = null;
if ($orgFilter) {
    $s = $pdo->prepare("SELECT id, name FROM organizations WHERE id=?");
    $s->execute([$orgFilter]);
    $filterOrg = $s->fetch();
}

$counts = [
    'active'    => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='active'")->fetchColumn(),
    'trial'     => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='trial'")->fetchColumn(),
    'expired'   => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='expired'")->fetchColumn(),
    'cancelled' => $pdo->query("SELECT COUNT(*) FROM subscriptions WHERE status='cancelled'")->fetchColumn(),
];
?>

<div class="page-header">
  <div>
    <h4><i class="fas fa-credit-card me-2 text-green"></i>Subscriptions</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Subscriptions</li>
    </ol></nav>
  </div>
</div>

<?php if ($filterOrg): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-3">
  <i class="fas fa-filter"></i>
  <div>Showing subscriptions for <strong><?= e($filterOrg['name']) ?></strong></div>
  <a href="subscriptions.php" class="btn btn-sm btn-outline-primary ms-auto">
    <i class="fas fa-times me-1"></i>Clear Filter
  </a>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
  <?php foreach ([['Active',$counts['active'],'green','fas fa-check-circle'],['On Trial',$counts['trial'],'warning','fas fa-clock'],['Expired',$counts['expired'],'danger','fas fa-times-circle'],['Cancelled',$counts['cancelled'],'secondary','fas fa-ban']] as $c): ?>
  <div class="col-6 col-md-3">
    <div class="stat-card <?= $c[2] ?>">
      <div class="stat-icon <?= $c[2] ?>-bg"><i class="<?= $c[3] ?>"></i></div>
      <div><div class="stat-value"><?= $c[1] ?></div><div class="stat-label"><?= $c[0] ?></div></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Subscriptions Table -->
<div class="card">
  <div class="card-header">
    <i class="fas fa-list text-green me-2"></i>
    <?= $filterOrg ? 'Subscriptions — ' . e($filterOrg['name']) : 'All Subscriptions' ?>
    <span class="ms-auto badge bg-secondary"><?= count($subscriptions) ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-hover mb-0 data-table">
        <thead>
          <tr>
            <th>#</th>
            <th>Organization</th>
            <th>Plan</th>
            <th>Modules</th>
            <th>Billing</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Expiry</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($subscriptions as $i => $s): ?>
          <?php
            // Fetch active modules for this subscription for the manage modal
            $subMods = $pdo->prepare("SELECT module_id FROM subscription_modules WHERE subscription_id=? AND status='active'");
            $subMods->execute([$s['id']]);
            $activeModIds = $subMods->fetchAll(PDO::FETCH_COLUMN);
          ?>
          <tr>
            <td class="text-muted small"><?= $i + 1 ?></td>
            <td>
              <div class="fw-600"><?= e($s['org_name']) ?></div>
              <div class="text-muted small"><?= e($s['org_email']) ?></div>
            </td>
            <td><span class="badge bg-info text-dark"><?= e($s['plan_name'] ?? 'Custom') ?></span></td>
            <td><span class="badge bg-primary"><?= $s['module_count'] ?> modules</span></td>
            <td class="small text-capitalize"><?= $s['billing_cycle'] ?></td>
            <td class="fw-600"><?= formatCurrency((float)$s['amount']) ?></td>
            <td><?= statusBadge($s['status']) ?></td>
            <td class="small">
              <?php if ($s['status'] === 'trial' && $s['trial_ends_at']): ?>
                <span class="text-warning"><?= formatDate($s['trial_ends_at']) ?></span>
              <?php elseif ($s['ends_at']): ?>
                <?= formatDate($s['ends_at']) ?>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td>
              <div class="d-flex gap-1 flex-wrap">
                <a href="<?= APP_URL ?>/admin/clients.php?view=<?= $s['org_id'] ?>" class="btn btn-xs btn-outline-primary" title="View client">
                  <i class="fas fa-eye"></i>
                </a>
                <button class="btn btn-xs btn-outline-secondary"
                        title="Manage subscription"
                        onclick="openManageModal(<?= htmlspecialchars(json_encode([
                          'id'           => $s['id'],
                          'org_id'       => $s['org_id'],
                          'org_name'     => $s['org_name'],
                          'plan_id'      => $s['plan_id'],
                          'status'       => $s['status'],
                          'ends_at'      => $s['ends_at'],
                          'billing_cycle'=> $s['billing_cycle'],
                          'amount'       => $s['amount'],
                          'active_mods'  => $activeModIds,
                        ]), ENT_QUOTES) ?>)">
                  <i class="fas fa-cog"></i>
                </button>
                <!-- Generate renewal invoice -->
                <form method="POST" class="d-inline" onsubmit="return confirm('Generate a renewal invoice for <?= addslashes($s['org_name']) ?>?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="generate_renewal_invoice">
                  <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="org_id" value="<?= $s['org_id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-warning" title="Generate renewal invoice">
                    <i class="fas fa-file-invoice"></i>
                  </button>
                </form>
                <!-- Suspend / Reactivate -->
                <?php if (in_array($s['status'], ['active','trial'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Suspend <?= addslashes($s['org_name']) ?>? They will lose access immediately.')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="suspend_subscription">
                  <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="org_id" value="<?= $s['org_id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-danger" title="Suspend subscription">
                    <i class="fas fa-pause"></i>
                  </button>
                </form>
                <?php elseif (in_array($s['status'], ['suspended','expired','cancelled'])): ?>
                <form method="POST" class="d-inline" onsubmit="return confirm('Reactivate subscription for <?= addslashes($s['org_name']) ?>?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="reactivate_subscription">
                  <input type="hidden" name="sub_id" value="<?= $s['id'] ?>">
                  <input type="hidden" name="org_id" value="<?= $s['org_id'] ?>">
                  <button type="submit" class="btn btn-xs btn-outline-success" title="Reactivate subscription">
                    <i class="fas fa-play"></i>
                  </button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($subscriptions)): ?>
          <tr><td colspan="9" class="text-center py-4 text-muted">No subscriptions found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Manage Subscription Modal -->
<div class="modal fade" id="manageSubModal" tabindex="-1">
  <div class="modal-dialog modal-xl">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--navy);color:white">
        <h5 class="modal-title"><i class="fas fa-cog me-2"></i>Manage Subscription — <span id="mSubOrgName"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-4">
          <!-- Left: subscription details -->
          <div class="col-lg-5">
            <h6 class="fw-700 text-navy mb-3"><i class="fas fa-credit-card me-2 text-green"></i>Subscription Details</h6>
            <form method="POST" id="subDetailsForm">
              <input type="hidden" name="action" value="extend_subscription">
              <input type="hidden" name="sub_id"  id="mSubId">
              <input type="hidden" name="org_id"  id="mSubOrgId">
              <div class="mb-3">
                <label class="form-label">Plan</label>
                <select name="plan_id" id="mSubPlanId" class="form-select" onchange="autofillAmount()">
                  <option value="">— Keep current plan —</option>
                  <?php foreach ($plans as $p): ?>
                  <option value="<?= $p['id'] ?>"
                          data-monthly="<?= $p['price_monthly'] ?>"
                          data-annual="<?= $p['price_annual'] ?>">
                    <?= e($p['name']) ?> (KES <?= number_format($p['price_monthly']) ?>/mo)
                  </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Status</label>
                <select name="status" id="mSubStatus" class="form-select">
                  <option value="active">Active</option>
                  <option value="trial">Trial</option>
                  <option value="expired">Expired</option>
                  <option value="cancelled">Cancelled</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Billing Cycle</label>
                <select name="billing_cycle" id="mSubBilling" class="form-select" onchange="autofillAmount()">
                  <option value="monthly">Monthly</option>
                  <option value="annual">Annual</option>
                  <option value="custom">Custom</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Amount (KES)</label>
                <div class="input-group">
                  <span class="input-group-text">KES</span>
                  <input type="number" name="amount" id="mSubAmount" class="form-control" min="0" step="1" placeholder="Leave blank to keep current">
                </div>
              </div>
              <div class="mb-3">
                <label class="form-label">Subscription End Date *</label>
                <input type="date" name="ends_at" id="mSubEndsAt" class="form-control" required>
                <div class="mt-1 d-flex gap-1 flex-wrap">
                  <button type="button" class="btn btn-xs btn-outline-secondary" onclick="extendBy(1,'month')">+1 Month</button>
                  <button type="button" class="btn btn-xs btn-outline-secondary" onclick="extendBy(3,'month')">+3 Months</button>
                  <button type="button" class="btn btn-xs btn-outline-secondary" onclick="extendBy(6,'month')">+6 Months</button>
                  <button type="button" class="btn btn-xs btn-outline-secondary" onclick="extendBy(1,'year')">+1 Year</button>
                </div>
              </div>
              <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-save me-2"></i>Save Subscription Changes
              </button>
            </form>
          </div>

          <!-- Right: module assignments -->
          <div class="col-lg-7">
            <h6 class="fw-700 text-navy mb-3"><i class="fas fa-puzzle-piece me-2 text-green"></i>Active Modules</h6>
            <form method="POST" id="subModsForm">
              <input type="hidden" name="action" value="save_sub_modules">
              <input type="hidden" name="sub_id"  id="mSubIdMods">
              <input type="hidden" name="org_id"  id="mSubOrgIdMods">
              <div class="row g-2 mb-3" id="modulesGrid">
                <?php foreach ($modules as $m): ?>
                <div class="col-6 col-md-4">
                  <label class="d-flex align-items-center gap-2 rounded p-2 mod-check-label"
                         style="cursor:pointer;border:1.5px solid var(--gray-200);transition:.15s"
                         data-mod="<?= $m['id'] ?>">
                    <input class="form-check-input flex-shrink-0 mt-0 mod-checkbox"
                           type="checkbox" name="module_ids[]" value="<?= $m['id'] ?>">
                    <span style="width:28px;height:28px;border-radius:8px;background:<?= e($m['color']) ?>1a;color:<?= e($m['color']) ?>;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0">
                      <i class="<?= e($m['icon']) ?>"></i>
                    </span>
                    <span class="small fw-600 text-truncate"><?= e($m['name']) ?></span>
                  </label>
                </div>
                <?php endforeach; ?>
              </div>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleAllMods(true)">Select All</button>
                <button type="button" class="btn btn-xs btn-outline-secondary" onclick="toggleAllMods(false)">Clear All</button>
                <button type="submit" class="btn btn-success ms-auto">
                  <i class="fas fa-save me-2"></i>Save Modules
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php
$extraJs = '<script>
let _currentEndsAt = "";

function openManageModal(s) {
  document.getElementById("mSubOrgName").textContent  = s.org_name;
  document.getElementById("mSubId").value             = s.id;
  document.getElementById("mSubOrgId").value          = s.org_id;
  document.getElementById("mSubIdMods").value         = s.id;
  document.getElementById("mSubOrgIdMods").value      = s.org_id;
  document.getElementById("mSubStatus").value         = s.status;
  document.getElementById("mSubBilling").value        = s.billing_cycle || "monthly";
  document.getElementById("mSubAmount").value         = s.amount || "";
  // Plan
  const planSel = document.getElementById("mSubPlanId");
  planSel.value = s.plan_id || "";
  // End date
  const ed = s.ends_at ? s.ends_at.substring(0, 10) : "";
  document.getElementById("mSubEndsAt").value = ed;
  _currentEndsAt = ed;
  // Modules
  const activeMods = s.active_mods || [];
  document.querySelectorAll(".mod-checkbox").forEach(cb => {
    const checked = activeMods.includes(parseInt(cb.value));
    cb.checked = checked;
    updateModLabel(cb.closest("label"), checked);
  });
  new bootstrap.Modal(document.getElementById("manageSubModal")).show();
}

function updateModLabel(label, checked) {
  label.style.borderColor = checked ? "var(--green)" : "var(--gray-200)";
  label.style.background  = checked ? "var(--green-pale)" : "";
}

document.querySelectorAll(".mod-checkbox").forEach(cb => {
  cb.addEventListener("change", () => updateModLabel(cb.closest("label"), cb.checked));
});

function toggleAllMods(state) {
  document.querySelectorAll(".mod-checkbox").forEach(cb => {
    cb.checked = state;
    updateModLabel(cb.closest("label"), state);
  });
}

function extendBy(n, unit) {
  const current = document.getElementById("mSubEndsAt").value || new Date().toISOString().substring(0,10);
  const d = new Date(current);
  if (unit === "month") d.setMonth(d.getMonth() + n);
  else                  d.setFullYear(d.getFullYear() + n);
  document.getElementById("mSubEndsAt").value = d.toISOString().substring(0, 10);
}

function autofillAmount() {
  const planSel = document.getElementById("mSubPlanId");
  const billing = document.getElementById("mSubBilling").value;
  const opt = planSel.options[planSel.selectedIndex];
  if (!opt || !opt.value) return;
  const price = billing === "annual" ? opt.dataset.annual : opt.dataset.monthly;
  if (price) document.getElementById("mSubAmount").value = price;
}
</script>';
require_once __DIR__ . '/../includes/footer.php';
?>
