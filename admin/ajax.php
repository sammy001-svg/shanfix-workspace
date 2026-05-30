<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {

    // ── Subscription status quick-update ─────────────────────────
    case 'update_sub_status':
        $id      = (int)($input['id']     ?? 0);
        $status  = $input['status']        ?? '';
        $allowed = ['active','trial','expired','cancelled'];
        if ($id && in_array($status, $allowed)) {
            $pdo->prepare("UPDATE subscriptions SET status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
        }
        break;

    // ── Organization status toggle ───────────────────────────────
    case 'toggle_org_status':
        $id     = (int)($input['id']     ?? 0);
        $status = $input['status']        ?? 'active';
        if ($id && in_array($status, ['active','inactive','suspended'])) {
            $pdo->prepare("UPDATE organizations SET status=? WHERE id=?")->execute([$status, $id]);
            logActivity('toggle_org_status', 'admin', "Organization #$id set to $status");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
        }
        break;

    // ── Module status toggle ─────────────────────────────────────
    case 'toggle_module_status':
        $id      = (int)($input['id']     ?? 0);
        $current = $input['current']       ?? 'active';
        $status  = $current === 'active' ? 'inactive' : 'active';
        if ($id) {
            $pdo->prepare("UPDATE modules SET status=? WHERE id=?")->execute([$status, $id]);
            logActivity('toggle_module', 'admin', "Module #$id toggled to $status");
            echo json_encode(['success' => true, 'new_status' => $status]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid module ID']);
        }
        break;

    // ── Save module pricing/details ──────────────────────────────
    case 'save_module_pricing':
        $id           = (int)($input['id']             ?? 0);
        $monthlyPrice = (float)($input['monthly_price'] ?? 0);
        $annualPrice  = (float)($input['annual_price']  ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE modules SET monthly_price=?, annual_price=? WHERE id=?")
                ->execute([$monthlyPrice, $annualPrice, $id]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid module ID']);
        }
        break;

    // ── Extend / update subscription ────────────────────────────
    case 'extend_subscription':
        $id      = (int)($input['id']      ?? 0);
        $endsAt  = $input['ends_at']        ?? '';
        $status  = $input['status']         ?? 'active';
        $planId  = (int)($input['plan_id']  ?? 0);
        $allowed = ['active','trial','expired','cancelled'];
        if ($id && $endsAt && in_array($status, $allowed)) {
            $pdo->prepare("
                UPDATE subscriptions
                SET ends_at=?, status=?,
                    plan_id=COALESCE(NULLIF(?,0), plan_id)
                WHERE id=?
            ")->execute([$endsAt, $status, $planId, $id]);
            logActivity('extend_subscription', 'admin', "Subscription #$id extended to $endsAt");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing required fields']);
        }
        break;

    // ── Update modules assigned to a subscription ────────────────
    case 'update_subscription_modules':
        $subId     = (int)($input['subscription_id'] ?? 0);
        $moduleIds = array_map('intval', $input['module_ids'] ?? []);
        if ($subId) {
            $pdo->prepare("UPDATE subscription_modules SET status='inactive' WHERE subscription_id=?")->execute([$subId]);
            foreach ($moduleIds as $mid) {
                if (!$mid) continue;
                $pdo->prepare("
                    INSERT INTO subscription_modules (subscription_id, module_id, status)
                    VALUES (?, ?, 'active')
                    ON DUPLICATE KEY UPDATE status='active'
                ")->execute([$subId, $mid]);
            }
            logActivity('update_sub_modules', 'admin', "Modules updated for subscription #$subId");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid subscription ID']);
        }
        break;

    // ── System settings ──────────────────────────────────────────
    case 'save_settings':
        $section = $input['section'] ?? '';
        $data    = $input['data']    ?? [];
        $allowed = [
            'general'  => ['app_name','app_tagline','support_email','default_currency','default_timezone','trial_days','max_users'],
            'company'  => ['company_address','company_website'],
            'billing'  => ['invoice_prefix','invoice_tax_rate','invoice_footer','invoice_notes','mpesa_paybill','mpesa_account_ref','bank_name','bank_account','bank_branch'],
            'email'    => ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name'],
            'kopokopo' => ['kopokopo_client_id','kopokopo_client_secret','kopokopo_till_number','kopokopo_api_secret','kopokopo_env'],
            'security' => ['session_timeout','max_login_attempts'],
        ];
        if (!isset($allowed[$section])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid section']);
            break;
        }
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed[$section], true)) {
                saveSetting($key, (string)$value);
            }
        }
        echo json_encode(['success' => true]);
        break;

    // ── Test email ───────────────────────────────────────────────
    case 'send_test_email':
        $toEmail = $input['email'] ?? '';
        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email address']);
            break;
        }
        require_once __DIR__ . '/../includes/mailer.php';
        $ok = mailer()->send(
            $toEmail,
            'Test Email — ' . APP_NAME,
            '<h2 style="color:#0B2D4E">SMTP Test</h2><p>Your SMTP settings are working correctly. This test was sent from <strong>' . APP_NAME . '</strong>.</p>'
        );
        echo json_encode([
            'success' => $ok,
            'message' => $ok ? 'Test email sent to ' . $toEmail : 'Failed to send. Check your SMTP credentials and host.',
        ]);
        break;

    // ── Test KopoKopo connection ─────────────────────────────────
    case 'test_kopokopo':
        require_once __DIR__ . '/../includes/kopokopo.php';
        try {
            $kk    = kopokopo();
            $token = $kk->getToken();
            if (!$token) throw new Exception('Empty token returned.');
            echo json_encode([
                'success' => true,
                'message' => 'KopoKopo OAuth token obtained successfully — credentials are valid. Till: ' . ($kk->getTillNumber() ?: 'not set'),
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
            ]);
        }
        break;

    // ── Mark invoice paid ────────────────────────────────────────
    case 'mark_invoice_paid':
        $id = (int)($input['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id=?")->execute([$id]);
            logActivity('mark_invoice_paid', 'admin', "Invoice #$id marked paid");
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid invoice ID']);
        }
        break;

    // ── Send single invoice reminder ──────────────────────────────
    case 'send_invoice_reminder':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid invoice ID']);
            break;
        }
        $invRow = $pdo->prepare("
            SELECT i.*, o.name AS org_name, o.email AS org_email
            FROM invoices i JOIN organizations o ON i.org_id = o.id
            WHERE i.id = ?
        ");
        $invRow->execute([$id]);
        $invRow = $invRow->fetch();
        if (!$invRow || !$invRow['org_email']) {
            http_response_code(400);
            echo json_encode(['error' => 'Invoice or client email not found']);
            break;
        }
        require_once __DIR__ . '/../includes/mailer.php';
        $invData = [
            'invoice_number' => $invRow['invoice_number'],
            'amount'         => $invRow['amount'],
            'tax'            => $invRow['tax'],
            'total'          => $invRow['total'],
            'due_date'       => $invRow['due_date'],
        ];
        $ok = mailer()->sendInvoice($invRow['org_email'], $invRow['org_name'], $invData);
        logActivity('send_invoice_reminder', 'admin', "Reminder sent for invoice {$invRow['invoice_number']}");
        echo json_encode([
            'success' => $ok,
            'message' => $ok
                ? "Reminder sent to {$invRow['org_email']}"
                : 'Failed to send email. Check SMTP settings.',
        ]);
        break;

    // ── Bulk mark paid ────────────────────────────────────────────
    case 'bulk_mark_paid':
        $ids = array_filter(array_map('intval', $input['ids'] ?? []));
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'No invoice IDs provided']);
            break;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE invoices SET status='paid', paid_at=NOW() WHERE id IN ($ph)")->execute($ids);
        logActivity('bulk_mark_paid', 'admin', count($ids) . ' invoices marked paid');
        echo json_encode(['success' => true, 'message' => count($ids) . ' invoice(s) marked as paid.']);
        break;

    // ── Bulk mark overdue ─────────────────────────────────────────
    case 'bulk_mark_overdue':
        $ids = array_filter(array_map('intval', $input['ids'] ?? []));
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'No invoice IDs provided']);
            break;
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE invoices SET status='overdue' WHERE id IN ($ph) AND status != 'paid'")->execute($ids);
        logActivity('bulk_mark_overdue', 'admin', count($ids) . ' invoices marked overdue');
        echo json_encode(['success' => true, 'message' => count($ids) . ' invoice(s) marked as overdue.']);
        break;

    // ── Bulk send reminders ───────────────────────────────────────
    case 'bulk_send_reminder':
        $ids = array_filter(array_map('intval', $input['ids'] ?? []));
        if (empty($ids)) {
            http_response_code(400);
            echo json_encode(['error' => 'No invoice IDs provided']);
            break;
        }
        require_once __DIR__ . '/../includes/mailer.php';
        $ph       = implode(',', array_fill(0, count($ids), '?'));
        $bulkStmt = $pdo->prepare("
            SELECT i.*, o.name AS org_name, o.email AS org_email
            FROM invoices i JOIN organizations o ON i.org_id = o.id
            WHERE i.id IN ($ph) AND i.status != 'paid'
        ");
        $bulkStmt->execute($ids);
        $sent = 0; $failed = 0;
        foreach ($bulkStmt->fetchAll() as $inv) {
            if (!$inv['org_email']) { $failed++; continue; }
            $invData = [
                'invoice_number' => $inv['invoice_number'],
                'amount'         => $inv['amount'],
                'tax'            => $inv['tax'],
                'total'          => $inv['total'],
                'due_date'       => $inv['due_date'],
            ];
            mailer()->sendInvoice($inv['org_email'], $inv['org_name'], $invData) ? $sent++ : $failed++;
        }
        logActivity('bulk_send_reminder', 'admin', "$sent reminders sent, $failed failed");
        echo json_encode([
            'success' => true,
            'message' => "$sent reminder(s) sent" . ($failed ? ", $failed failed." : '.'),
        ]);
        break;

    // ── Delete user ──────────────────────────────────────────────
    case 'delete_user':
        $id = (int)($input['id'] ?? 0);
        if ($id) {
            // Prevent deleting super_admin
            $role = $pdo->prepare("SELECT role FROM users WHERE id=?");
            $role->execute([$id]);
            $role = $role->fetchColumn();
            if ($role === 'super_admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Cannot delete super admin']);
                break;
            }
            $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user ID']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
