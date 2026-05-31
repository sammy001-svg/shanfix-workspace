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
        // Reject obviously wrong prices (module prices should be between 0 and 9,999,999 KES)
        if ($monthlyPrice < 0 || $monthlyPrice > 9_999_999) {
            http_response_code(400);
            echo json_encode(['error' => "Monthly price KES {$monthlyPrice} is out of range (0 – 9,999,999)."]);
            break;
        }
        if ($annualPrice < 0 || $annualPrice > 99_999_999) {
            http_response_code(400);
            echo json_encode(['error' => "Annual price KES {$annualPrice} is out of range (0 – 99,999,999)."]);
            break;
        }
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
            'general'  => ['app_name','app_tagline','support_email','default_currency','default_timezone','trial_days','max_users','usd_rate'],
            'company'  => ['company_address','company_website','company_phone','company_hours'],
            'billing'  => ['invoice_prefix','invoice_tax_rate','invoice_footer','invoice_notes','mpesa_paybill','mpesa_account_ref','bank_name','bank_account','bank_branch'],
            'email'    => ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name'],
            'kopokopo' => ['kopokopo_client_id','kopokopo_client_secret','kopokopo_till_number','kopokopo_api_secret','kopokopo_env'],
            'sms'      => ['sms_enabled','at_username','at_api_key','at_shortcode','at_env'],
            'security' => ['session_timeout','max_login_attempts'],
        ];
        if (!isset($allowed[$section])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid section']);
            break;
        }
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed[$section], true)) continue;

            // ── Per-key validation and sanitisation ──────────────────
            if ($key === 'invoice_tax_rate') {
                // Tax rate must be a sensible percentage: 0–100 %
                $rate = (float)$value;
                if ($rate < 0 || $rate > 100) {
                    echo json_encode(['success' => false, 'error' => "Tax rate must be between 0 and 100 (you entered {$rate}%). Please correct it."]);
                    break 2; // break out of foreach AND case
                }
                $value = number_format($rate, 2, '.', '');
            } elseif ($key === 'invoice_prefix') {
                $value = preg_replace('/[^A-Z0-9\-_]/i', '', strtoupper(trim((string)$value)));
                if (empty($value)) $value = 'INV';
            } elseif (in_array($key, ['trial_days','max_users','max_login_attempts','session_timeout'], true)) {
                $value = (string)max(0, (int)$value);
            } elseif ($key === 'usd_rate') {
                $rate = (float)$value;
                if ($rate <= 0) $rate = 130;
                $value = number_format(min($rate, 99999), 4, '.', '');
            } elseif ($key === 'sms_enabled') {
                $value = $value === '1' ? '1' : '0';
            }

            saveSetting($key, (string)$value);
        }
        echo json_encode(['success' => true]);
        break;

    // ── Test email — SMTP-only, no silent fallback, full diagnostics ────────────
    case 'send_test_email':
        $toEmail = trim($input['email'] ?? '');
        if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
            break;
        }
        require_once __DIR__ . '/../includes/mailer.php';

        // Pre-flight: are SMTP credentials configured?
        $smtpHost = getSetting('smtp_host', '');
        $smtpUser = getSetting('smtp_user', '');
        $smtpPass = getSetting('smtp_pass', '');
        if (!$smtpHost) {
            echo json_encode([
                'success' => false,
                'message' => 'SMTP host is not configured. Fill in and save your SMTP settings first.',
            ]);
            break;
        }
        if (!$smtpUser) {
            echo json_encode([
                'success' => false,
                'message' => 'SMTP username is empty. Enter your email address and save settings.',
            ]);
            break;
        }

        // Build test body
        $testBody = "<div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;background:#f0f4f8'>
            <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
                <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
            </div>
            <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
                <h2 style='color:#1A8A4E;margin-top:0'>✅ SMTP Configuration Test</h2>
                <p>This email confirms that your SMTP settings are working correctly on <strong>" . APP_NAME . "</strong>.</p>
                <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                    <tr><td style='padding:8px;border:1px solid #eee;color:#666'>SMTP Host</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>{$smtpHost}</td></tr>
                    <tr><td style='padding:8px;border:1px solid #eee;color:#666'>SMTP User</td><td style='padding:8px;border:1px solid #eee'>{$smtpUser}</td></tr>
                    <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Sent at</td><td style='padding:8px;border:1px solid #eee'>" . date('d M Y, H:i:s T') . "</td></tr>
                </table>
                <p style='color:#64748b;font-size:.85rem'>If you received this, email notifications are working. You can safely ignore this test message.</p>
            </div>
        </div>";

        try {
            // Force SMTP — bypass the silent php mail() fallback by calling sendSmtp directly
            // We do this by creating a Mailer and attempting a direct SMTP send
            $m = mailer();
            // Use reflection to call sendSmtp directly so we get the real exception
            $ref = new ReflectionClass($m);
            $sendSmtp = $ref->getMethod('sendSmtp');
            $sendSmtp->setAccessible(true);
            $wrapped = "<!DOCTYPE html><html><head><meta charset='UTF-8'></head><body style='margin:0;padding:0;background:#f0f4f8'>{$testBody}</body></html>";
            $sendSmtp->invokeArgs($m, [$toEmail, '', 'SMTP Test — ' . APP_NAME, $wrapped, strip_tags($testBody)]);
            echo json_encode(['success' => true, 'message' => "Test email sent successfully to {$toEmail} via SMTP."]);
        } catch (Throwable $e) {
            $errMsg  = $e->getMessage();
            // Clean up socket error codes for better readability
            $friendly = $errMsg;
            if (str_contains($errMsg, 'Connection refused') || str_contains($errMsg, 'connect failed')) {
                $friendly = "Connection refused — the SMTP host ({$smtpHost}) on the configured port rejected the connection. Check the host address and port number.";
            } elseif (str_contains($errMsg, '535') || str_contains($errMsg, 'Authentication') || str_contains($errMsg, 'credentials')) {
                $friendly = "Authentication failed — your SMTP username or password is incorrect.";
            } elseif (str_contains($errMsg, '530') || str_contains($errMsg, 'STARTTLS')) {
                $friendly = "Encryption mismatch — try switching between TLS, SSL, and None.";
            } elseif (str_contains($errMsg, 'SSL') || str_contains($errMsg, 'tls')) {
                $friendly = "SSL/TLS error — try a different Encryption setting (TLS ↔ SSL) or verify your host supports it.";
            } elseif (str_contains($errMsg, 'timeout') || str_contains($errMsg, 'timed out')) {
                $friendly = "Connection timed out — the SMTP host is unreachable. Check your host name and firewall rules.";
            }
            echo json_encode([
                'success' => false,
                'message' => $friendly,
                'debug'   => "[{$smtpHost}:" . getSetting('smtp_port','587') . " / " . getSetting('smtp_enc','tls') . "] " . $errMsg,
            ]);
        }
        break;

    // ── Delete subscription plan ─────────────────────────────────
    case 'delete_plan':
        $id = (int)($input['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid plan ID.']);
            break;
        }
        // Block delete if any active/trial subscriptions use this plan
        $inUse = $pdo->prepare("SELECT COUNT(*) FROM subscriptions WHERE plan_id=? AND status IN ('active','trial')");
        $inUse->execute([$id]);
        if ((int)$inUse->fetchColumn() > 0) {
            echo json_encode(['success' => false, 'error' => 'Cannot delete — plan has active subscribers. Deactivate it instead.']);
            break;
        }
        $pdo->prepare("DELETE FROM subscription_plans WHERE id=?")->execute([$id]);
        logActivity('delete_plan', 'admin', "Deleted subscription plan #$id");
        echo json_encode(['success' => true]);
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
            SELECT i.id, i.invoice_number,
                   CAST(i.amount AS DECIMAL(12,2)) AS amount,
                   CAST(i.tax    AS DECIMAL(12,2)) AS tax,
                   CAST(i.total  AS DECIMAL(12,2)) AS total,
                   i.status, i.due_date, i.notes,
                   o.name AS org_name, o.email AS org_email
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
            SELECT i.id, i.invoice_number,
                   CAST(i.amount AS DECIMAL(12,2)) AS amount,
                   CAST(i.tax    AS DECIMAL(12,2)) AS tax,
                   CAST(i.total  AS DECIMAL(12,2)) AS total,
                   i.status, i.due_date, i.notes,
                   o.name AS org_name, o.email AS org_email
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

    // ── Test SMS (Africa's Talking) ──────────────────────────────
    case 'test_sms':
        require_once __DIR__ . '/../includes/sms.php';
        $smsEnabled = getSetting('sms_enabled', '0');
        if ($smsEnabled !== '1') {
            echo json_encode(['success' => false, 'message' => 'SMS is disabled. Enable it in SMS settings first.']);
            break;
        }
        $adminPhone = getSetting('support_phone', '');
        if (!$adminPhone) {
            // Try the super-admin's phone
            $row = $pdo->query("SELECT phone FROM users WHERE role='super_admin' AND phone IS NOT NULL AND phone != '' LIMIT 1")->fetch();
            $adminPhone = $row['phone'] ?? '';
        }
        if (!$adminPhone) {
            echo json_encode(['success' => false, 'message' => 'No phone number found. Add a support phone in General settings.']);
            break;
        }
        try {
            $result = sms()->send($adminPhone, APP_NAME . ' SMS test message — your SMS integration is working correctly!');
            if ($result['success']) {
                echo json_encode(['success' => true, 'message' => 'Test SMS sent to ' . $adminPhone . '. Check your phone.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'AT API error: ' . ($result['error'] ?? 'Unknown')]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Exception: ' . $e->getMessage()]);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
