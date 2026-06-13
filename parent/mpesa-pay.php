<?php
/**
 * Parent Portal — M-Pesa STK Push endpoint
 * POST (JSON): { fee_id, phone, amount }
 * Returns JSON: { success, message, checkout_id? }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/mpesa.php';

header('Content-Type: application/json');

// ── Auth guard ────────────────────────────────────────────────────────────────
if (empty($_SESSION['par_id']) || empty($_SESSION['par_org_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$parOrgId = (int)$_SESSION['par_org_id'];
$parSids  = $_SESSION['par_sids'] ?? [];

// ── Input ─────────────────────────────────────────────────────────────────────
$feeId = (int)($_POST['fee_id'] ?? 0);
$phone = preg_replace('/\D/', '', trim($_POST['phone'] ?? ''));
$amount = (float)($_POST['amount'] ?? 0);

if (!$feeId || !$phone || $amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid input. Please enter a valid phone and amount.']);
    exit;
}

// Normalize phone: strip leading 0 or +, ensure starts 254
if (str_starts_with($phone, '0'))   $phone = '254' . substr($phone, 1);
if (str_starts_with($phone, '+'))   $phone = substr($phone, 1);
if (!str_starts_with($phone, '254')) $phone = '254' . $phone;

if (strlen($phone) !== 12 || !ctype_digit($phone)) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format. Use 07XXXXXXXX or 254XXXXXXXXX.']);
    exit;
}

// ── Verify fee belongs to this parent's child ─────────────────────────────────
try {
    $stmt = $pdo->prepare(
        "SELECT f.id, f.student_id, f.fee_type, f.balance, f.currency
         FROM sch_fees f
         WHERE f.id = ? AND f.org_id = ? AND f.balance > 0"
    );
    $stmt->execute([$feeId, $parOrgId]);
    $fee = $stmt->fetch();
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

if (!$fee || !in_array((int)$fee['student_id'], $parSids, true)) {
    echo json_encode(['success' => false, 'message' => 'Fee not found or access denied.']);
    exit;
}

if ($fee['currency'] !== 'KES') {
    echo json_encode(['success' => false, 'message' => 'M-Pesa payment is only available for KES invoices.']);
    exit;
}

// Clamp amount to balance
$amount = min($amount, (float)$fee['balance']);
if ($amount < 1) {
    echo json_encode(['success' => false, 'message' => 'Minimum payment is KES 1.']);
    exit;
}

// ── Load M-Pesa settings ──────────────────────────────────────────────────────
$mpesaConfig = [
    'consumer_key'    => getSetting('mpesa_consumer_key',    ''),
    'consumer_secret' => getSetting('mpesa_consumer_secret', ''),
    'shortcode'       => getSetting('mpesa_shortcode',       ''),
    'passkey'         => getSetting('mpesa_passkey',         ''),
    'env'             => getSetting('mpesa_env',             'sandbox'),
    'callback_url'    => APP_URL . '/api/mpesa-callback.php',
];

if (!$mpesaConfig['consumer_key'] || !$mpesaConfig['shortcode']) {
    echo json_encode(['success' => false, 'message' => 'M-Pesa is not configured on this school yet. Please contact the administrator.']);
    exit;
}

// ── Trigger STK Push ──────────────────────────────────────────────────────────
$mpesa = new Mpesa($mpesaConfig);
$ref   = 'FEE-' . $feeId . '-' . $fee['student_id'];
$desc  = 'School Fees';

$result = $mpesa->stkPush($phone, $amount, $ref, $desc);

if ($result['success']) {
    // Log a pending payment record so admin can see the request
    try {
        $receiptNo = 'MPESA-PENDING-' . strtoupper(substr($result['checkout_id'], -8));
        $pdo->prepare(
            "INSERT INTO sch_fee_payments
             (fee_id, org_id, amount_paid, payment_method, payment_date, receipt_no, paid_by, notes)
             VALUES (?, ?, ?, 'mpesa', CURDATE(), ?, 'Parent Portal (M-Pesa)', ?)"
        )->execute([
            $feeId,
            $parOrgId,
            $amount,
            $receiptNo,
            'STK Push initiated. CheckoutID: ' . $result['checkout_id'],
        ]);

        // Update fee balance optimistically (admin can revert if payment fails)
        $pdo->prepare(
            "UPDATE sch_fees SET
                paid    = paid + ?,
                balance = GREATEST(0, balance - ?),
                status  = CASE WHEN GREATEST(0, balance - ?) = 0 THEN 'paid'
                               ELSE 'partial' END
             WHERE id = ? AND org_id = ?"
        )->execute([$amount, $amount, $amount, $feeId, $parOrgId]);
    } catch (Throwable $e) {
        // Non-fatal: STK push succeeded, payment recording can be done by admin
        error_log('[school/mpesa-pay] ' . $e->getMessage());
    }

    echo json_encode([
        'success'     => true,
        'message'     => 'M-Pesa prompt sent! Check your phone (' . substr($phone, 0, 6) . 'XXXXXX) and enter your PIN to complete payment.',
        'checkout_id' => $result['checkout_id'],
    ]);
} else {
    echo json_encode(['success' => false, 'message' => $result['message']]);
}
