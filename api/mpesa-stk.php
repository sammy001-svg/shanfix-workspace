<?php
/**
 * M-Pesa STK Push Initiator
 * POST: { phone, amount, invoice_id }
 * Returns JSON: { success, checkout_id, message }
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mpesa.php';

header('Content-Type: application/json');
requireLogin();

$user  = currentUser();
$orgId = (int)$user['org_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$phone     = trim($_POST['phone']      ?? '');
$amount    = (float)($_POST['amount']  ?? 0);
$invoiceId = (int)($_POST['invoice_id'] ?? 0);

if (!$phone || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Phone number and amount are required.']);
    exit;
}

// Validate invoice belongs to this org (if provided)
if ($invoiceId) {
    $chk = $pdo->prepare("SELECT id FROM invoices WHERE id=? AND org_id=? AND status IN ('sent','pending')");
    $chk->execute([$invoiceId, $orgId]);
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Invoice not found or already paid.']);
        exit;
    }
}

try {
    $mpesa      = new Mpesa();
    $ref        = $invoiceId ? ('INV-' . $invoiceId) : 'SUBSCRIPTION';
    $desc       = 'OrbitDesk Workspace Payment';
    $checkoutId = $mpesa->stkPush($phone, (int)$amount, $ref, $desc);

    if (!$checkoutId) {
        echo json_encode(['success' => false, 'message' => 'STK push failed. Check M-Pesa settings.']);
        exit;
    }

    // Store checkout_id → invoice mapping
    $pdo->prepare("INSERT INTO mpesa_pending (org_id, invoice_id, checkout_id, amount, phone) VALUES (?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE invoice_id=VALUES(invoice_id), amount=VALUES(amount)")
        ->execute([$orgId, $invoiceId ?: null, $checkoutId, $amount, $phone]);

    logActivity('mpesa_stk', 'billing', "STK push initiated — KES {$amount} to {$phone}");

    echo json_encode([
        'success'     => true,
        'checkout_id' => $checkoutId,
        'message'     => 'STK push sent. Check your phone and enter your M-Pesa PIN.',
    ]);
} catch (Exception $e) {
    error_log('[STK Push] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'M-Pesa error: ' . $e->getMessage()]);
}
