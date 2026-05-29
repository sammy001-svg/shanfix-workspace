<?php
/**
 * AJAX endpoint: validate and apply a promo code.
 * POST: { code: string, amount: float }
 * Returns JSON: { valid, discount, final_price, message }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Must be logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['valid' => false, 'message' => 'Unauthorized']);
    exit;
}

// Rate limit: max 20 attempts per user per 15 minutes
$userId = (int)($_SESSION['user_id'] ?? 0);
if (!rateLimit('promo_code', (string)$userId, 20, 900)) {
    http_response_code(429);
    echo json_encode(['valid' => false, 'message' => 'Too many attempts. Please wait a few minutes.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['valid' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true);
$code   = trim($body['code']   ?? $_POST['code']   ?? '');
$amount = (float)($body['amount'] ?? $_POST['amount'] ?? 0);

if (!$code) {
    echo json_encode(['valid' => false, 'message' => 'Please enter a promo code.']);
    exit;
}

if ($amount <= 0) {
    echo json_encode(['valid' => false, 'message' => 'Invalid amount.']);
    exit;
}

// Use the applyPromoCode() helper from functions.php
$result = applyPromoCode($code, $amount);

echo json_encode([
    'valid'       => $result['valid'],
    'discount'    => $result['discount'],
    'final_price' => $result['final_price'],
    'message'     => $result['message'],
    'promo_id'    => $result['promo_id'] ?? null,
    'code'        => strtoupper($code),
]);
