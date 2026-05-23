<?php
/**
 * KopoKopo Payment Status Poller
 * GET /api/check-payment.php?id={checkout_id}
 *
 * Called by client/billing.php every 3 s after STK push is sent.
 * Returns JSON: { status, receipt, message }
 *   status  — 'pending' | 'completed' | 'failed'
 *   receipt — M-Pesa receipt number (when completed)
 *   message — human-readable description
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$user       = currentUser();
$orgId      = (int)$user['org_id'];
$checkoutId = trim($_GET['id'] ?? $_POST['id'] ?? '');

if (!$checkoutId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing payment ID.']);
    exit;
}

try {
    $st = $pdo->prepare("
        SELECT status, mpesa_receipt
        FROM   mpesa_pending
        WHERE  checkout_id = ? AND org_id = ?
        LIMIT  1
    ");
    $st->execute([$checkoutId, $orgId]);
    $row = $st->fetch();

    if (!$row) {
        echo json_encode(['status' => 'pending', 'receipt' => '', 'message' => 'Payment initialising…']);
        exit;
    }

    $status  = strtolower($row['status']);
    $receipt = $row['mpesa_receipt'] ?? '';

    $message = match ($status) {
        'completed' => 'Payment confirmed. M-Pesa receipt: ' . $receipt,
        'failed'    => 'Payment failed or was cancelled.',
        default     => 'Waiting for M-Pesa confirmation…',
    };

    echo json_encode(['status' => $status, 'receipt' => $receipt, 'message' => $message]);

} catch (Exception $e) {
    error_log('[check-payment] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Server error. Please refresh.']);
}
