<?php
/**
 * OrbitDesk Workspace — SMTP Mailer
 * Uses PHP's built-in socket-based SMTP (no Composer required)
 * Drop-in for cPanel hosting with SMTP settings
 */
class Mailer
{
    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $encryption; // 'tls' | 'ssl' | 'none'
    private string $fromName;
    private string $fromEmail;

    public function __construct(array $config = [])
    {
        $this->host       = $config['host']       ?? 'localhost';
        $this->port       = (int)($config['port'] ?? 587);
        $this->username   = $config['username']   ?? '';
        $this->password   = $config['password']   ?? '';
        $this->encryption = $config['encryption'] ?? 'tls';
        $this->fromName   = $config['from_name']  ?? APP_NAME;
        $this->fromEmail  = $config['from_email'] ?? 'noreply@orbitdesk.co.ke';
    }

    /**
     * Send an email
     * @param string|array $to      'email@example.com' or ['name'=>'John','email'=>'...']
     * @param string       $subject Email subject
     * @param string       $body    HTML body
     * @param string|null  $text    Plain-text fallback (auto-generated if null)
     */
    public function send($to, string $subject, string $body, ?string $text = null): bool
    {
        if (is_array($to)) {
            $toEmail = $to['email'];
            $toName  = $to['name'] ?? '';
        } else {
            $toEmail = $to;
            $toName  = '';
        }

        $text = $text ?? strip_tags($body);
        $body = $this->wrapHtml($subject, $body);

        // Try SMTP first; fall back to PHP mail()
        try {
            return $this->sendSmtp($toEmail, $toName, $subject, $body, $text);
        } catch (Exception $e) {
            error_log('[Mailer] SMTP failed: ' . $e->getMessage() . ' — trying mail()');
            return $this->sendPhpMail($toEmail, $toName, $subject, $body);
        }
    }

    // ── Templated email helpers ──────────────────────────────────

    public function sendWelcome(string $toEmail, string $name, string $orgName): bool
    {
        $subject = "Welcome to {$orgName}'s Workspace — " . APP_NAME;
        $body    = $this->template("Welcome, {$name}!", "
            <p>Your workspace for <strong>{$orgName}</strong> has been set up successfully on " . APP_NAME . ".</p>
            <p>You can now access your dashboard and start using your selected modules.</p>
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/auth/login.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                Access Your Dashboard →
              </a>
            </div>
            <p style='color:#666;font-size:.85rem'>Your 14-day free trial has started. No credit card needed.</p>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    public function sendInvoice(string $toEmail, string $name, array $invoice): bool
    {
        $subject = "Invoice {$invoice['invoice_number']} — " . APP_NAME;
        $body    = $this->template("Invoice {$invoice['invoice_number']}", "
            <p>Dear <strong>{$name}</strong>,</p>
            <p>Please find your invoice details below:</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Invoice #</td><td style='padding:8px;border:1px solid #eee;font-weight:700'>{$invoice['invoice_number']}</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Amount</td><td style='padding:8px;border:1px solid #eee'>KES " . number_format($invoice['amount'],2) . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Tax (VAT)</td><td style='padding:8px;border:1px solid #eee'>KES " . number_format($invoice['tax'],2) . "</td></tr>
              <tr style='background:#f0f9f4'><td style='padding:8px;border:1px solid #eee;font-weight:700'>Total Due</td><td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>KES " . number_format($invoice['total'],2) . "</td></tr>
              <tr><td style='padding:8px;border:1px solid #eee;color:#666'>Due Date</td><td style='padding:8px;border:1px solid #eee;color:#e67e22;font-weight:600'>{$invoice['due_date']}</td></tr>
            </table>
            <p style='color:#666;font-size:.85rem'>Pay via M-Pesa: Paybill <strong>" . (function_exists('getSettings') ? (getSettings(['mpesa_paybill'])['mpesa_paybill'] ?: 'N/A') : 'N/A') . "</strong>, Account: <strong>{$invoice['invoice_number']}</strong></p>
            <a href='" . APP_URL . "/client/billing.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
              View &amp; Pay Invoice
            </a>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    public function sendPasswordReset(string $toEmail, string $name, string $token): bool
    {
        $link    = APP_URL . "/auth/reset-password.php?token={$token}";
        $subject = 'Password Reset Request — ' . APP_NAME;
        $body    = $this->template('Reset Your Password', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>We received a request to reset your password. Click the button below to proceed:</p>
            <div style='text-align:center;margin:24px 0'>
              <a href='{$link}' style='background:#0B2D4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                Reset Password
              </a>
            </div>
            <p style='color:#666;font-size:.85rem'>This link expires in 1 hour. If you didn't request this, please ignore this email.</p>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    public function sendSubscriptionExpiry(
        string $toEmail,
        string $name,
        int    $daysLeft,
        string $planName,
        float  $amount
    ): bool {
        $plural  = $daysLeft === 1 ? 'day' : 'days';
        $subject = "Action Required: {$planName} expires in {$daysLeft} {$plural} — " . APP_NAME;
        $body    = $this->template('Subscription Renewal Reminder ⚠️', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Your <strong>{$planName}</strong> subscription on " . APP_NAME . " expires in
               <strong style='color:#e67e22'>{$daysLeft} {$plural}</strong>.</p>
            <table style='width:100%;border-collapse:collapse;margin:16px 0'>
              <tr>
                <td style='padding:8px;border:1px solid #eee;color:#666'>Plan</td>
                <td style='padding:8px;border:1px solid #eee;font-weight:700'>{$planName}</td>
              </tr>
              <tr style='background:#f0f9f4'>
                <td style='padding:8px;border:1px solid #eee;color:#666'>Renewal Amount</td>
                <td style='padding:8px;border:1px solid #eee;font-weight:700;color:#1A8A4E'>
                  KES " . number_format($amount, 2) . "
                </td>
              </tr>
              <tr>
                <td style='padding:8px;border:1px solid #eee;color:#666'>Days Remaining</td>
                <td style='padding:8px;border:1px solid #eee;font-weight:700;color:#e67e22'>
                  {$daysLeft} {$plural}
                </td>
              </tr>
            </table>
            <p>Renew before it expires to avoid service interruption and loss of data access.</p>
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/client/billing.php'
                 style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;
                        text-decoration:none;font-weight:700;display:inline-block'>
                Renew My Subscription →
              </a>
            </div>
            <p style='color:#666;font-size:.85rem'>
              Pay via M-Pesa or contact our team for assistance.
            </p>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    public function sendSuspension(string $toEmail, string $name, string $planName): bool
    {
        $subject = 'Your Account Has Been Suspended — ' . APP_NAME;
        $body    = $this->template('Account Suspended', "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Your <strong>{$planName}</strong> subscription has expired and your account has been
               <strong style='color:#ef4444'>suspended</strong>.</p>
            <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;
                        padding:16px;margin:16px 0'>
              <p style='margin:0;color:#dc2626;font-weight:600'>
                ⚠ Your workspace is currently inaccessible to all users.
              </p>
            </div>
            <p>Your data is safe and will be retained for <strong>30 days</strong>.
               Renew your subscription to regain full access immediately.</p>
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/client/billing.php'
                 style='background:#ef4444;color:white;padding:12px 28px;border-radius:50px;
                        text-decoration:none;font-weight:700;display:inline-block'>
                Reactivate My Account →
              </a>
            </div>
            <p style='color:#666;font-size:.85rem'>
              Need help? Contact us at " . (function_exists('getSettings') ? (getSettings(['support_email'])['support_email'] ?: 'support@orbitdesk.co.ke') : 'support@orbitdesk.co.ke') . ".
            </p>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    public function sendTrialExpiry(string $toEmail, string $name, int $daysLeft): bool
    {
        $subject = "Your trial expires in {$daysLeft} day" . ($daysLeft===1?'':'s') . " — " . APP_NAME;
        $body    = $this->template("Trial Ending Soon ⏰", "
            <p>Hi <strong>{$name}</strong>,</p>
            <p>Your free trial on " . APP_NAME . " expires in <strong>{$daysLeft} day" . ($daysLeft===1?'':'s') . "</strong>.</p>
            <p>To keep access to all your modules and data, upgrade to a paid plan now.</p>
            <div style='text-align:center;margin:24px 0'>
              <a href='" . APP_URL . "/client/billing.php' style='background:#1A8A4E;color:white;padding:12px 28px;border-radius:50px;text-decoration:none;font-weight:700;display:inline-block'>
                Upgrade My Plan →
              </a>
            </div>
        ");
        return $this->send($toEmail, $subject, $body);
    }

    // ── Internals ───────────────────────────────────────────────

    private function template(string $heading, string $content): string
    {
        return "
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:600px;margin:0 auto;background:#f0f4f8;padding:24px'>
          <div style='background:#0B2D4E;padding:20px 28px;border-radius:12px 12px 0 0;text-align:center'>
            <span style='color:white;font-size:1.2rem;font-weight:800'>" . APP_NAME . "</span>
          </div>
          <div style='background:white;padding:32px;border-radius:0 0 12px 12px'>
            <h2 style='color:#0B2D4E;margin-top:0'>{$heading}</h2>
            {$content}
            <hr style='border:none;border-top:1px solid #eee;margin:24px 0'>
            <p style='color:#999;font-size:.8rem;margin:0'>
              &copy; " . date('Y') . " " . APP_NAME . " &bull;
              <a href='" . APP_URL . "' style='color:#1A8A4E'>Visit Website</a> &bull;
              If you did not sign up, ignore this email.
            </p>
          </div>
        </div>";
    }

    private function wrapHtml(string $subject, string $body): string
    {
        return "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>{$subject}</title></head><body style='margin:0;padding:0;background:#f0f4f8'>{$body}</body></html>";
    }

    private function sendPhpMail(string $toEmail, string $toName, string $subject, string $body): bool
    {
        $headers  = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $headers .= "Reply-To: {$this->fromEmail}\r\n";
        $to = $toName ? "\"{$toName}\" <{$toEmail}>" : $toEmail;
        return mail($to, $subject, $body, $headers);
    }

    private function sendSmtp(string $toEmail, string $toName, string $subject, string $body, string $text): bool
    {
        $sock = match($this->encryption) {
            'ssl'  => fsockopen("ssl://{$this->host}", $this->port, $errno, $errstr, 30),
            default => fsockopen($this->host, $this->port, $errno, $errstr, 30),
        };

        if (!$sock) throw new Exception("SMTP connect failed: {$errstr}");

        $this->expect($sock, '220');
        $this->cmd($sock, "EHLO " . gethostname(), '250');

        if ($this->encryption === 'tls') {
            $this->cmd($sock, 'STARTTLS', '220');
            stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->cmd($sock, "EHLO " . gethostname(), '250');
        }

        if ($this->username) {
            $this->cmd($sock, 'AUTH LOGIN', '334');
            $this->cmd($sock, base64_encode($this->username), '334');
            $this->cmd($sock, base64_encode($this->password), '235');
        }

        $this->cmd($sock, "MAIL FROM:<{$this->fromEmail}>", '250');
        $this->cmd($sock, "RCPT TO:<{$toEmail}>", '250');
        $this->cmd($sock, 'DATA', '354');

        $boundary = md5(time());
        $msg  = "From: {$this->fromName} <{$this->fromEmail}>\r\n";
        $msg .= "To: {$toName} <{$toEmail}>\r\n";
        $msg .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $msg .= "MIME-Version: 1.0\r\n";
        $msg .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
        $msg .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$text}\r\n";
        $msg .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$body}\r\n";
        $msg .= "--{$boundary}--";

        fwrite($sock, $msg . "\r\n.\r\n");
        $this->expect($sock, '250');
        $this->cmd($sock, 'QUIT', '221');
        fclose($sock);
        return true;
    }

    private function cmd($sock, string $cmd, string $expect): void
    {
        fwrite($sock, $cmd . "\r\n");
        $this->expect($sock, $expect);
    }

    private function expect($sock, string $code): void
    {
        $response = '';
        while ($line = fgets($sock, 512)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
        if (!str_starts_with(trim($response), $code)) {
            throw new Exception("SMTP expected {$code}, got: " . trim($response));
        }
    }
}

// ── Global helpers ──────────────────────────────────────────────

/**
 * Returns a pre-configured Mailer instance using SMTP constants
 * defined by includes/settings.php (loaded via config/database.php).
 */
function mailer(): Mailer
{
    $cfg = [];
    try {
        if (function_exists('getSettings')) {
            $cfg = getSettings(['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_enc','mail_from','mail_from_name']);
        }
    } catch (Exception $e) { /* DB not ready yet */ }

    return new Mailer([
        'host'       => ($cfg['smtp_host']      ?? '') ?: (defined('SMTP_HOST')      ? SMTP_HOST      : 'localhost'),
        'port'       => (int)(($cfg['smtp_port'] ?? '') ?: (defined('SMTP_PORT')      ? SMTP_PORT      : 587)),
        'username'   => ($cfg['smtp_user']      ?? '') ?: (defined('SMTP_USER')      ? SMTP_USER      : ''),
        'password'   => ($cfg['smtp_pass']      ?? '') ?: (defined('SMTP_PASS')      ? SMTP_PASS      : ''),
        'encryption' => ($cfg['smtp_enc']       ?? '') ?: (defined('SMTP_ENC')       ? SMTP_ENC       : 'tls'),
        'from_name'  => ($cfg['mail_from_name'] ?? '') ?: (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME),
        'from_email' => ($cfg['mail_from']      ?? '') ?: (defined('MAIL_FROM')      ? MAIL_FROM      : 'noreply@orbitdesk.co.ke'),
    ]);
}

function sendEmail($to, string $subject, string $body): bool
{
    return mailer()->send($to, $subject, $body);
}
