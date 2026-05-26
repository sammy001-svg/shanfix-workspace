<?php
/**
 * KopoKopo Webhook Receiver
 * Configure this URL in your KopoKopo dashboard as the callback_url
 * for incoming payment webhooks.
 *
 * URL: https://yourdomain.com/api/mpesa-callback.php
 *
 * Security: Requests are verified using HMAC-SHA256 signature in
 *           X-KopoKopo-Signature header.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/kopokopo.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_KOPOKOPO_SIGNATURE'] ?? '';

// ── Always log raw payload for debugging ─────────────────────────
error_log('[KopoKopo Callback] ' . date('Y-m-d H:i:s') . ' sig=' . $signature . ' body=' . $rawBody);

// ── Verify webhook signature ──────────────────────────────────────
$kk = kopokopo();
if (!$kk->verifyWebhook($rawBody, $signature)) {
    error_log('[KopoKopo Callback] INVALID SIGNATURE — rejected');
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// ── Parse payload ─────────────────────────────────────────────────
$data    = json_decode($rawBody, true) ?? [];
$parsed  = KopoKopo::parseWebhook($data);

$paymentId  = $parsed['payment_id'];
$status     = $parsed['status'];       // 'Received' | 'Failed' | 'Pending'
$receipt    = $parsed['receipt'];      // M-Pesa receipt e.g. QFE1234567
$amount     = $parsed['amount'];
$phone      = $parsed['phone'];
$meta       = $parsed['metadata'];
$eventType  = $parsed['event_type'];

$invoiceId    = (int)($meta['invoice_id']  ?? 0);
$orgId        = (int)($meta['org_id']      ?? 0);
$isWalletTopUp = ($meta['wallet_top_up'] ?? '') === '1';
$walletTxId   = (int)($meta['wallet_tx_id'] ?? 0);

// ── Log callback to audit table ───────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO payment_callbacks
            (provider, event_type, checkout_id, invoice_id, org_id, amount, phone, mpesa_receipt, status, raw_payload)
        VALUES ('kopokopo', ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$eventType, $paymentId, $invoiceId ?: null, $orgId ?: null,
                 $amount, $phone, $receipt, $status, $rawBody]);
} catch (Exception $e) {
    error_log('[KopoKopo Callback] audit log failed: ' . $e->getMessage());
}

// ── Only process successful payments ─────────────────────────────
if (strtolower($status) !== 'received') {
    error_log('[KopoKopo Callback] Non-success status: ' . $status);
    echo json_encode(['received' => true]);
    exit;
}

try {
    // Find the pending record — match by payment_id (checkout_id) or invoice+org
    $pending = null;

    if ($paymentId) {
        $st = $pdo->prepare("SELECT * FROM mpesa_pending WHERE checkout_id=? LIMIT 1");
        $st->execute([$paymentId]);
        $pending = $st->fetch();
    }
    // Fallback: look up by invoice + org from metadata
    if (!$pending && $invoiceId && $orgId) {
        $st = $pdo->prepare("SELECT * FROM mpesa_pending WHERE invoice_id=? AND org_id=? AND status='pending' ORDER BY created_at DESC LIMIT 1");
        $st->execute([$invoiceId, $orgId]);
        $pending = $st->fetch();
    }

    if (!$pending) {
        error_log('[KopoKopo Callback] No pending record found for payment_id=' . $paymentId);
        echo json_encode(['received' => true]);
        exit;
    }

    $resolvedOrgId     = $orgId     ?: (int)$pending['org_id'];
    $resolvedInvoiceId = $invoiceId ?: (int)$pending['invoice_id'];

    // ── Mark mpesa_pending as completed ──────────────────────────
    $pdo->prepare("UPDATE mpesa_pending SET status='completed', mpesa_receipt=? WHERE id=?")
        ->execute([$receipt, $pending['id']]);

    // ── Wallet top-up: credit balance ─────────────────────────────
    if ($isWalletTopUp && $resolvedOrgId) {
        $txId = $walletTxId ?: (int)($pdo->prepare("SELECT id FROM wallet_transactions WHERE checkout_id=? LIMIT 1")
            ->execute([$paymentId]) ? $pdo->query("SELECT id FROM wallet_transactions WHERE checkout_id=" . $pdo->quote($paymentId) . " LIMIT 1")->fetchColumn() : 0);

        $pdo->beginTransaction();
        try {
            // Lock org row and get current balance
            $balRow = $pdo->prepare("SELECT wallet_balance FROM organizations WHERE id=? FOR UPDATE");
            $balRow->execute([$resolvedOrgId]);
            $currentBal = (float)($balRow->fetchColumn() ?: 0);
            $newBal     = $currentBal + (float)$amount;

            $pdo->prepare("UPDATE organizations SET wallet_balance=? WHERE id=?")
                ->execute([$newBal, $resolvedOrgId]);

            $pdo->prepare("UPDATE wallet_transactions SET status='completed', balance_after=?, mpesa_receipt=?, checkout_id=? WHERE id=?")
                ->execute([$newBal, $receipt, $paymentId, $txId]);

            $pdo->commit();

            notifyOrg(
                $resolvedOrgId,
                'Wallet Topped Up — KES ' . number_format($amount, 2),
                'Your wallet has been credited KES ' . number_format($amount, 2) . '. New balance: KES ' . number_format($newBal, 2) . '. Receipt: ' . $receipt,
                'success',
                APP_URL . '/client/billing.php?tab=wallet'
            );

            $adminStmt = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='client_admin' LIMIT 1");
            $adminStmt->execute([$resolvedOrgId]);
            $admin = $adminStmt->fetch();
            if ($admin) {
                $body = "
                <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
                  <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
                    <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
                  </div>
                  <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
                    <div style='background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:16px;margin-bottom:20px;text-align:center'>
                      <div style='font-size:1.5rem;font-weight:800;color:#065f46'>✅ Wallet Topped Up</div>
                    </div>
                    <p>Dear <strong>" . htmlspecialchars($admin['name']) . "</strong>,</p>
                    <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>M-Pesa Receipt</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#065f46'>" . htmlspecialchars($receipt) . "</td></tr>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Amount Loaded</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>KES " . number_format($amount, 2) . "</td></tr>
                      <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Wallet Balance</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>KES " . number_format($newBal, 2) . "</td></tr>
                    </table>
                    <div style='text-align:center;margin:24px 0'>
                      <a href='" . APP_URL . "/client/billing.php?tab=wallet' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                        View Wallet &rarr;
                      </a>
                    </div>
                  </div>
                </div>";
                mailer()->send($admin['email'], 'Wallet Top-Up Confirmed — ' . APP_NAME, $body);
            }

            $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('wallet_topup','billing',?,?)")
                ->execute(["Wallet credited KES {$amount}. New balance: KES {$newBal}. Receipt: {$receipt}", $_SERVER['REMOTE_ADDR'] ?? 'webhook']);

        } catch (Exception $ex) {
            $pdo->rollBack();
            error_log('[Wallet TopUp Callback] ' . $ex->getMessage());
        }

        echo json_encode(['received' => true]);
        exit;
    }

    // ── Mark invoice as paid ──────────────────────────────────────
    if ($resolvedInvoiceId) {
        $pdo->prepare("
            UPDATE invoices
            SET status='paid', paid_at=NOW(), mpesa_receipt=?, checkout_id=?
            WHERE id=? AND org_id=? AND status IN ('sent','pending','overdue','draft')
        ")->execute([$receipt, $paymentId, $resolvedInvoiceId, $resolvedOrgId]);

        // Activate module if this invoice is linked to one
        $invRow = $pdo->prepare("SELECT module_id, subscription_id FROM invoices WHERE id=?");
        $invRow->execute([$resolvedInvoiceId]);
        $inv = $invRow->fetch();

        if ($inv && !empty($inv['module_id'])) {
            $pdo->prepare("
                INSERT INTO subscription_modules (subscription_id, module_id, status)
                VALUES (?, ?, 'active')
                ON DUPLICATE KEY UPDATE status='active'
            ")->execute([$inv['subscription_id'], $inv['module_id']]);
        }

        // Renew subscription if this was a renewal invoice
        $subRow = $pdo->prepare("SELECT id FROM subscriptions WHERE org_id=? AND status IN ('expired','active') LIMIT 1");
        $subRow->execute([$resolvedOrgId]);
        $sub = $subRow->fetch();
        if ($sub) {
            $pdo->prepare("UPDATE subscriptions SET status='active' WHERE id=?")
                ->execute([$sub['id']]);
        }
    }

    // ── In-app notification ───────────────────────────────────────
    notifyOrg(
        $resolvedOrgId,
        'Payment Received — KES ' . number_format($amount, 2),
        'M-Pesa payment of KES ' . number_format($amount, 2) . ' confirmed. Receipt: ' . $receipt . '.',
        'success',
        APP_URL . '/client/billing.php'
    );

    // ── Email receipt to org admin ────────────────────────────────
    $adminStmt = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='client_admin' LIMIT 1");
    $adminStmt->execute([$resolvedOrgId]);
    $admin = $adminStmt->fetch();
    if ($admin) {
        $body = "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <div style='background:#ecfdf5;border:1px solid #6ee7b7;border-radius:8px;padding:16px;margin-bottom:20px;text-align:center'>
              <i style='font-size:2rem'>✅</i>
              <div style='font-size:1.5rem;font-weight:800;color:#065f46;margin-top:8px'>Payment Confirmed</div>
            </div>
            <p>Dear <strong>" . htmlspecialchars($admin['name']) . "</strong>,</p>
            <p>We have received your M-Pesa payment.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>M-Pesa Receipt</td>
                  <td style='padding:8px;border:1px solid #eee;font-weight:700;color:#065f46'>" . htmlspecialchars($receipt) . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Amount Paid</td>
                  <td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>KES " . number_format($amount, 2) . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Payment Date</td>
                  <td style='padding:8px;border:1px solid #eee'>" . date('d M Y, h:i A') . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Provider</td>
                  <td style='padding:8px;border:1px solid #eee'>M-Pesa via KopoKopo</td></tr>
            </table>
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/client/billing.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                View Billing Dashboard &rarr;
              </a>
            </div>
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='color:#999;font-size:.8rem;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
          </div>
        </div>";
        mailer()->send($admin['email'], 'Payment Confirmed — ' . APP_NAME, $body);
    }

    // ── Activity log ──────────────────────────────────────────────
    $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('kopokopo_payment','billing',?,?)")
        ->execute([
            "M-Pesa payment received: KES {$amount}, Receipt: {$receipt}, Invoice: {$resolvedInvoiceId}",
            $_SERVER['REMOTE_ADDR'] ?? 'webhook',
        ]);

    echo json_encode(['received' => true]);

} catch (Exception $e) {
    error_log('[KopoKopo Callback Error] ' . $e->getMessage());
    // Return 200 so KopoKopo doesn't retry endlessly for server errors
    echo json_encode(['received' => true, 'note' => 'processing_error']);
}
