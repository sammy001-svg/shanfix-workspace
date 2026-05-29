<?php
// ── Bootstrap (POST handlers before HTML) ────────────────────────
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

$user = currentUser();

// ── Default templates seed data ──────────────────────────────────
$defaults = [
    'loan_approved'       => ['subject' => 'Your Loan Application Has Been Approved', 'body' => "<p>Dear {{member_name}},</p><p>We are pleased to inform you that your loan application of <strong>{{amount}}</strong> has been approved.</p><p>Disbursement will be made by {{date}}. Please log in to your portal to review the terms.</p><p><a href='{{link}}'>View Loan Details</a></p><p>Regards,<br>{{org_name}}</p>"],
    'invoice_sent'        => ['subject' => 'New Invoice Available — {{org_name}}', 'body' => "<p>Dear {{member_name}},</p><p>A new invoice for <strong>{{amount}}</strong> is now available on your account.</p><p><a href='{{link}}'>View &amp; Pay Invoice</a></p><p>Regards,<br>{{org_name}}</p>"],
    'subscription_expiry' => ['subject' => 'Your Subscription is Expiring Soon', 'body' => "<p>Hi {{member_name}},</p><p>Your subscription with <strong>{{org_name}}</strong> will expire on <strong>{{date}}</strong>.</p><p>To avoid service interruption, please renew before the expiry date.</p><p><a href='{{link}}'>Renew Subscription</a></p>"],
    'new_member'          => ['subject' => 'Welcome to {{org_name}}!', 'body' => "<p>Welcome, {{member_name}}!</p><p>Your account has been created on <strong>{{org_name}}</strong>. You can now access all your services through the member portal.</p><p><a href='{{link}}'>Log In to Your Account</a></p>"],
    'payment_received'    => ['subject' => 'Payment Confirmation — {{amount}} Received', 'body' => "<p>Dear {{member_name}},</p><p>We have successfully received your payment of <strong>{{amount}}</strong> on {{date}}.</p><p>Your account has been updated accordingly. <a href='{{link}}'>View Receipt</a></p><p>Thank you,<br>{{org_name}}</p>"],
    'password_reset'      => ['subject' => 'Password Reset Request', 'body' => "<p>Hi {{member_name}},</p><p>We received a request to reset the password for your account. Click the link below to set a new password:</p><p><a href='{{link}}'>Reset My Password</a></p><p>If you did not request this, please ignore this email. The link expires in 1 hour.</p><p>{{org_name}}</p>"],
    'loan_repayment_due'  => ['subject' => 'Loan Repayment Reminder — Due {{date}}', 'body' => "<p>Dear {{member_name}},</p><p>This is a reminder that your loan repayment of <strong>{{amount}}</strong> is due on <strong>{{date}}</strong>.</p><p>Please ensure timely payment to maintain your credit standing.</p><p><a href='{{link}}'>Pay Now</a></p><p>{{org_name}}</p>"],
    'ticket_resolved'     => ['subject' => 'Your Support Ticket Has Been Resolved', 'body' => "<p>Hi {{member_name}},</p><p>Great news! Your support request has been resolved by our team. Please log in to review the resolution and close the ticket.</p><p><a href='{{link}}'>View Ticket</a></p><p>If you need further assistance, feel free to open a new ticket.</p><p>{{org_name}} Support Team</p>"],
];

$labels = [
    'loan_approved'       => 'Loan Application Approved',
    'invoice_sent'        => 'New Invoice Available',
    'subscription_expiry' => 'Subscription Expiring Soon',
    'new_member'          => 'Welcome to the Platform',
    'payment_received'    => 'Payment Confirmation',
    'password_reset'      => 'Password Reset Request',
    'loan_repayment_due'  => 'Loan Repayment Reminder',
    'ticket_resolved'     => 'Support Ticket Resolved',
];

// ── Seed defaults if not present ─────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS notification_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        event_key VARCHAR(64) NOT NULL,
        label VARCHAR(128) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        body LONGTEXT NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        org_id INT NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_event_org (event_key, org_id)
    )");
    foreach ($defaults as $key => $tpl) {
        $pdo->prepare("INSERT IGNORE INTO notification_templates (event_key, label, subject, body, is_active, org_id)
                       VALUES (?, ?, ?, ?, 1, NULL)")
            ->execute([$key, $labels[$key], $tpl['subject'], $tpl['body']]);
    }
} catch (Exception $e) { /* silently skip if migration not ready */ }

// ── POST: save_template ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_template') {
        $id      = (int)($_POST['tpl_id'] ?? 0);
        $subject = sanitize($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $active  = isset($_POST['is_active']) ? 1 : 0;
        $orgId   = ($_POST['org_id'] === '') ? null : (int)$_POST['org_id'];

        try {
            if ($id > 0) {
                $pdo->prepare("UPDATE notification_templates SET subject=?, body=?, is_active=?, org_id=?, updated_at=NOW() WHERE id=?")
                    ->execute([$subject, $body, $active, $orgId, $id]);
                setFlash('success', 'Template updated successfully.');
            } else {
                $key   = sanitize($_POST['event_key'] ?? '');
                $label = $labels[$key] ?? $key;
                $pdo->prepare("INSERT INTO notification_templates (event_key, label, subject, body, is_active, org_id) VALUES (?,?,?,?,?,?)")
                    ->execute([$key, $label, $subject, $body, $active, $orgId]);
                setFlash('success', 'Template created successfully.');
            }
        } catch (Exception $e) {
            setFlash('danger', 'Save failed: ' . $e->getMessage());
        }
        redirect(APP_URL . '/admin/notification-templates.php');
    }

    if ($action === 'toggle_template') {
        $id = (int)($_POST['tpl_id'] ?? 0);
        try {
            $pdo->prepare("UPDATE notification_templates SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
        } catch (Exception $e) {
            setFlash('danger', 'Toggle failed.');
        }
        redirect(APP_URL . '/admin/notification-templates.php');
    }
}

// ── Load templates ────────────────────────────────────────────────
$templates = [];
try {
    $stmt = $pdo->query("SELECT * FROM notification_templates ORDER BY FIELD(event_key,'" . implode("','", array_keys($defaults)) . "')");
    $templates = $stmt->fetchAll();
} catch (Exception $e) {}

// ── Load orgs for selector ────────────────────────────────────────
$orgs = [];
try {
    $orgs = $pdo->query("SELECT id, name FROM organizations ORDER BY name")->fetchAll();
} catch (Exception $e) {}

$pageTitle = 'Notification Templates';
require_once __DIR__ . '/../includes/header-admin.php';
?>

<div class="page-header d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div>
    <h4><i class="fas fa-bell me-2 text-green"></i>Notification Templates</h4>
    <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0">
      <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
      <li class="breadcrumb-item active">Notification Templates</li>
    </ol></nav>
  </div>
</div>

<?= flashAlert() ?>

<div class="row g-4">
  <?php foreach ($templates as $tpl): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card shadow-sm border-0 h-100">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between mb-2">
          <div>
            <span class="badge bg-secondary font-monospace mb-1"><?= e($tpl['event_key']) ?></span>
            <h6 class="mb-0 fw-bold"><?= e($tpl['label']) ?></h6>
          </div>
          <?= statusBadge($tpl['is_active'] ? 'active' : 'inactive') ?>
        </div>
        <p class="text-muted small mb-3 text-truncate" title="<?= e($tpl['subject']) ?>">
          <i class="fas fa-envelope me-1"></i><?= e($tpl['subject']) ?>
        </p>
        <div class="d-flex align-items-center gap-2">
          <button class="btn btn-sm btn-outline-primary flex-fill"
            onclick="openEditModal(<?= htmlspecialchars(json_encode($tpl), ENT_QUOTES) ?>)">
            <i class="fas fa-edit me-1"></i>Edit
          </button>
          <form method="POST" class="mb-0">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_template">
            <input type="hidden" name="tpl_id" value="<?= (int)$tpl['id'] ?>">
            <button type="submit" class="btn btn-sm <?= $tpl['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
              title="<?= $tpl['is_active'] ? 'Deactivate' : 'Activate' ?>">
              <i class="fas fa-<?= $tpl['is_active'] ? 'pause' : 'play' ?>"></i>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ── Edit Modal ───────────────────────────────────────────────── -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="POST" id="editForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="tpl_id" id="tpl_id">
      <input type="hidden" name="event_key" id="edit_event_key">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="editModalLabel"><i class="fas fa-bell me-2 text-green"></i>Edit Notification Template</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label fw-semibold">Event Key</label>
            <div id="edit_key_display" class="badge bg-secondary font-monospace fs-6 p-2"></div>
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="edit_subject">Subject Line</label>
            <input type="text" class="form-control" name="subject" id="edit_subject" required maxlength="255">
          </div>
          <div class="mb-3">
            <label class="form-label fw-semibold" for="edit_body">Email Body <span class="text-muted small fw-normal">(HTML supported)</span></label>
            <textarea class="form-control font-monospace" name="body" id="edit_body" rows="10" required></textarea>
          </div>
          <div class="alert alert-light border small mb-3">
            <strong>Available Variables:</strong><br>
            <code>{{org_name}}</code> — Organisation name &nbsp;
            <code>{{member_name}}</code> — Recipient name &nbsp;
            <code>{{amount}}</code> — Currency amount &nbsp;
            <code>{{date}}</code> — Relevant date &nbsp;
            <code>{{link}}</code> — Action URL
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold">Org Override</label>
              <select class="form-select" name="org_id" id="edit_org_id">
                <option value="">System Default (all orgs)</option>
                <?php foreach ($orgs as $org): ?>
                <option value="<?= (int)$org['id'] ?>"><?= e($org['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6 d-flex align-items-end">
              <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" value="1">
                <label class="form-check-label fw-semibold" for="edit_is_active">Active</label>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Template</button>
        </div>
      </div>
    </form>
  </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function openEditModal(tpl) {
    document.getElementById('tpl_id').value        = tpl.id;
    document.getElementById('edit_event_key').value = tpl.event_key;
    document.getElementById('edit_key_display').textContent = tpl.event_key;
    document.getElementById('edit_subject').value  = tpl.subject;
    document.getElementById('edit_body').value     = tpl.body;
    document.getElementById('edit_is_active').checked = tpl.is_active == 1;
    const orgSel = document.getElementById('edit_org_id');
    orgSel.value = tpl.org_id ?? '';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}
</script>
JS;
require_once __DIR__ . '/../includes/footer.php';
