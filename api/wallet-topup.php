<?php
/**
 * Wallet Top-Up — KopoKopo M-Pesa STK Push
 * POST: { phone, amount }   (JSON or form-encoded)
 * Returns JSON: { success, checkout_id, message }
 *
 * On payment confirmed, mpesa-callback.php detects wallet_top_up=1
 * in metadata and credits organizations.wallet_balance.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kopokopo.php';

header('Content-Type: application/json');
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$phone  = trim($_POST['phone']  ?? '');
$amount = (float)($_POST['amount'] ?? 0);

if (!$phone || $amount < 10) {
    echo json_encode(['success' => false, 'message' => 'Phone number and a minimum top-up of KES 10 are required.']);
    exit;
}
if ($amount > 150000) {
    echo json_encode(['success' => false, 'message' => 'Maximum single top-up is KES 150,000.']);
    exit;
}

try {
    // Create a pending wallet transaction first to get an ID for metadata
    $pdo->prepare("
        INSERT INTO wallet_transactions (org_id, type, amount, balance_after, description, status)
        VALUES (?, 'topup', ?, 0, 'M-Pesa wallet top-up', 'pending')
    ")->execute([$orgId, $amount]);
    $walletTxId = (int)$pdo->lastInsertId();

    $kk          = kopokopo();
    $callbackUrl = APP_URL . '/api/mpesa-callback.php';

    $paymentId = $kk->initiateStk(
        $phone,
        $amount,
        $callbackUrl,
        [
            'wallet_top_up'   => '1',
            'wallet_tx_id'    => (string)$walletTxId,
            'org_id'          => (string)$orgId,
            'first_name'      => $user['name'] ?? '',
            'email'           => $user['email'] ?? '',
        ]
    );

    // Store in mpesa_pending as reference (no invoice_id for wallet top-ups)
    $pdo->prepare("
        INSERT INTO mpesa_pending (org_id, invoice_id, checkout_id, amount, phone, status)
        VALUES (?, NULL, ?, ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE amount=VALUES(amount), phone=VALUES(phone), status='pending'
    ")->execute([$orgId, $paymentId, $amount, $phone]);

    // Link checkout_id back to wallet transaction
    $pdo->prepare("UPDATE wallet_transactions SET checkout_id=? WHERE id=?")
        ->execute([$paymentId, $walletTxId]);

    logActivity('wallet_topup_initiated', 'billing',
        "Wallet top-up STK: KES {$amount} to {$phone} (tx #{$walletTxId})");

    echo json_encode([
        'success'     => true,
        'checkout_id' => $paymentId,
        'message'     => 'STK push sent. Enter your M-Pesa PIN to top up your wallet.',
    ]);

} catch (Exception $e) {
    error_log('[Wallet TopUp] ' . $e->getMessage());
    // Clean up pending tx on failure
    if (!empty($walletTxId)) {
        $pdo->prepare("UPDATE wallet_transactions SET status='failed' WHERE id=?")->execute([$walletTxId]);
    }
    echo json_encode(['success' => false, 'message' => 'Top-up initiation failed: ' . $e->getMessage()]);
}
