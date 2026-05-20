<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireSuperAdmin();

header('Content-Type: application/json');

$input  = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'update_sub_status':
        $id     = (int)($input['id']     ?? 0);
        $status = $input['status'] ?? '';
        $allowed = ['active','trial','expired','cancelled'];
        if ($id && in_array($status, $allowed)) {
            $pdo->prepare("UPDATE subscriptions SET status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid parameters']);
        }
        break;

    case 'toggle_org_status':
        $id     = (int)($input['id'] ?? 0);
        $status = $input['status'] ?? 'active';
        if ($id && in_array($status, ['active','inactive','suspended'])) {
            $pdo->prepare("UPDATE organizations SET status=? WHERE id=?")->execute([$status, $id]);
            echo json_encode(['success' => true]);
        }
        break;

    case 'save_settings':
        $section = $input['section'] ?? '';
        $data    = $input['data']    ?? [];
        $allowed = [
            'general'  => ['app_name','support_email','default_currency','default_timezone','trial_days','max_users'],
            'email'    => ['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name'],
            'mpesa'    => ['mpesa_consumer_key','mpesa_consumer_secret','mpesa_shortcode','mpesa_passkey','mpesa_env'],
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

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
}
