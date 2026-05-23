<?php
/**
 * KopoKopo M-Pesa STK Push Initiator
 * POST: { phone, amount, invoice_id }
 * Returns JSON: { success, checkout_id, message }
 *
 * Called by client/billing.php initiateMpesa() via fetch().
 * Frontend then polls /api/check-payment.php?id={checkout_id}
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

$phone     = trim($_POST['phone']       ?? '');
$amount    = (float)($_POST['amount']   ?? 0);
$invoiceId = (int)($_POST['invoice_id'] ?? 0);

// ── Input validation ──────────────────────────────────────────────
if (!$phone || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Phone number and amount are required.']);
    exit;
}
if ($amount < 1) {
    echo json_encode(['success' => false, 'message' => 'Minimum payment amount is KES 1.']);
    exit;
}

// Validate invoice belongs to this org
if ($invoiceId) {
    $chk = $pdo->prepare("SELECT id, total FROM invoices WHERE id=? AND org_id=? AND status IN ('sent','pending','overdue')");
    $chk->execute([$invoiceId, $orgId]);
    $invRow = $chk->fetch();
    if (!$invRow) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found or already paid.']);
        exit;
    }
    // Use exact invoice total to prevent under-payment
    $amount = (float)$invRow['total'];
}

// ── Initiate STK Push via KopoKopo ───────────────────────────────
try {
    $kk          = kopokopo();
    $callbackUrl = APP_URL . '/api/mpesa-callback.php';

    $paymentId = $kk->initiateStk(
        $phone,
        $amount,
        $callbackUrl,
        [
            'invoice_id' => (string)$invoiceId,
            'org_id'     => (string)$orgId,
            'first_name' => $user['name'] ?? '',
            'email'      => $user['email'] ?? '',
        ]
    );

    // Store pending record (checkout_id = KopoKopo payment_id)
    $pdo->prepare("
        INSERT INTO mpesa_pending (org_id, invoice_id, checkout_id, amount, phone, status)
        VALUES (?, ?, ?, ?, ?, 'pending')
        ON DUPLICATE KEY UPDATE
            invoice_id = VALUES(invoice_id),
            amount     = VALUES(amount),
            phone      = VALUES(phone),
            status     = 'pending'
    ")->execute([$orgId, $invoiceId ?: null, $paymentId, $amount, $phone]);

    logActivity('kopokopo_stk', 'billing',
        "KopoKopo STK Push initiated — KES {$amount} to {$phone} (payment #{$paymentId})");

    echo json_encode([
        'success'     => true,
        'checkout_id' => $paymentId,
        'message'     => 'STK push sent to your phone. Enter your M-Pesa PIN to complete payment.',
    ]);

} catch (Exception $e) {
    error_log('[KopoKopo STK] ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Payment initiation failed: ' . $e->getMessage(),
    ]);
}
