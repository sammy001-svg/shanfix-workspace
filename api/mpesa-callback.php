<?php
/**
 * M-Pesa Daraja Callback Handler
 * URL: https://yourdomain.com/api/mpesa-callback.php
 * Configure this URL in your Daraja app settings
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mpesa.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
error_log('[M-Pesa Callback] ' . date('Y-m-d H:i:s') . ' — ' . $raw);

$result = Mpesa::parseCallback();

if ($result['success']) {
    try {
        $receipt    = $result['receipt'];
        $amount     = $result['amount'];
        $checkoutId = $result['checkout_id'];

        // Look up pending payment by checkout_id
        $pending = $pdo->prepare("SELECT * FROM mpesa_pending WHERE checkout_id=?");
        $pending->execute([$checkoutId]);
        $pending = $pending->fetch();

        if ($pending && $pending['invoice_id']) {
            // Mark invoice as paid
            $pdo->prepare("UPDATE invoices SET status='paid', mpesa_receipt=?, checkout_id=? WHERE id=? AND org_id=?")
                ->execute([$receipt, $checkoutId, $pending['invoice_id'], $pending['org_id']]);

            // Create in-app notification for the org
            $notifSql = "INSERT INTO notifications (org_id, title, message, type, link)
                         VALUES (?, 'Payment Received', ?, 'success', ?)";
            $pdo->prepare($notifSql)->execute([
                $pending['org_id'],
                "M-Pesa payment of KES " . number_format($amount, 2) . " received. Receipt: {$receipt}",
                '/client/billing.php',
            ]);

            // Clean up pending record
            $pdo->prepare("DELETE FROM mpesa_pending WHERE checkout_id=?")->execute([$checkoutId]);

            // Send payment receipt email to the org admin
            try {
                $adminStmt = $pdo->prepare("SELECT name, email FROM users WHERE org_id=? AND role='client_admin' LIMIT 1");
                $adminStmt->execute([$pending['org_id']]);
                $admin = $adminStmt->fetch();
                if ($admin) {
                    require_once __DIR__ . '/../includes/mailer.php';
                    $receiptBody = "
                    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
                      <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
                        <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
                      </div>
                      <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
                        <h2 style='color:#0B2D4E;margin-top:0'>Payment Confirmed</h2>
                        <p>Dear <strong>" . htmlspecialchars($admin['name']) . "</strong>,</p>
                        <p>We have received your M-Pesa payment of <strong>KES " . number_format($amount, 2) . "</strong>.</p>
                        <table style='width:100%;border-collapse:collapse;margin:16px 0'>
                          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Receipt No.</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>" . htmlspecialchars($receipt) . "</td></tr>
                          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Amount Paid</td><td style='padding:8px;border:1px solid #eee;color:#1A8A4E;font-weight:700'>KES " . number_format($amount, 2) . "</td></tr>
                          <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Date</td><td style='padding:8px;border:1px solid #eee'>" . date('d M Y, h:i A') . "</td></tr>
                        </table>
                        <div style='text-align:center;margin:24px 0'>
                          <a href='" . APP_URL . "/client/billing.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>View Billing &rarr;</a>
                        </div>
                        <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
                        <p style='color:#999;font-size:.8rem;margin:0'>&copy; " . date('Y') . " " . APP_NAME . "</p>
                      </div>
                    </div>";
                    mailer()->send($admin['email'], 'Payment Received — ' . APP_NAME, $receiptBody);
                }
            } catch (Exception $mailEx) {
                error_log('[M-Pesa Callback] Receipt email failed: ' . $mailEx->getMessage());
            }
        }

        // Log activity
        $pdo->prepare("INSERT INTO activity_log (action, module, description, ip) VALUES ('mpesa_payment','billing',?,?)")
            ->execute(["M-Pesa payment received: KES {$amount}, Receipt: {$receipt}", $_SERVER['REMOTE_ADDR'] ?? '']);

        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

    } catch (Exception $e) {
        error_log('[M-Pesa Callback Error] ' . $e->getMessage());
        echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Internal Error']);
    }
} else {
    error_log('[M-Pesa Callback] Payment failed: ' . $result['message']);
    // Still return 0 so Safaricom doesn't retry
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
}
