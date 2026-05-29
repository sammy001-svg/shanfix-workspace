<?php
/**
 * OrbitDesk Workspace — Africa's Talking SMS Integration
 * Supports: Single SMS, Bulk SMS, Account Balance
 * No Composer required — pure PHP with cURL.
 */
class AfricasTalking
{
    private string $username;
    private string $apiKey;
    private string $shortCode;
    private string $env;      // 'sandbox' | 'live'
    private string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->username  = $config['username']   ?? '';
        $this->apiKey    = $config['api_key']    ?? '';
        $this->shortCode = $config['short_code'] ?? 'AfricasTalking';
        $this->env       = $config['env']        ?? 'sandbox';
        $this->baseUrl   = $this->env === 'live'
            ? 'https://api.africastalking.com'
            : 'https://api.sandbox.africastalking.com';
    }

    // ── Public API ─────────────────────────────────────────────────

    /**
     * Send SMS to one or many recipients.
     *
     * @param string|array $to      '254712345678' or ['+254712345678', '+254798765432']
     * @param string       $message Message body (max 160 chars for single SMS)
     * @return array ['success'=>bool, 'sent'=>int, 'failed'=>int, 'messages'=>[...], 'error'=>'...']
     */
    public function send(string|array $to, string $message): array
    {
        $recipients = is_array($to) ? $to : [$to];
        $formatted  = array_map([$this, 'formatPhone'], $recipients);
        $numbers    = implode(',', $formatted);

        $payload = [
            'username'    => $this->username,
            'to'          => $numbers,
            'message'     => $message,
            'from'        => $this->shortCode ?: null,
        ];
        // Africa's Talking ignores null 'from'; remove if empty
        if (empty($payload['from'])) {
            unset($payload['from']);
        }

        $result = $this->post('/version1/messaging', $payload);

        if (isset($result['SMSMessageData'])) {
            $data     = $result['SMSMessageData'];
            $messages = $data['Recipients'] ?? [];
            $sent     = 0;
            $failed   = 0;

            foreach ($messages as $msg) {
                $statusCode = (int)($msg['statusCode'] ?? 0);
                // 101 = success; anything else is failure
                if ($statusCode === 101) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            return [
                'success'  => $sent > 0,
                'sent'     => $sent,
                'failed'   => $failed,
                'messages' => $messages,
                'error'    => $sent === 0 ? ($data['Message'] ?? 'All messages failed') : '',
            ];
        }

        $error = $result['error'] ?? $result['message'] ?? 'Unknown error from Africa\'s Talking API';
        return [
            'success'  => false,
            'sent'     => 0,
            'failed'   => count($recipients),
            'messages' => [],
            'error'    => $error,
        ];
    }

    /**
     * Send bulk SMS (alias for send() with an array of recipients).
     *
     * @param array  $recipients Array of phone numbers
     * @param string $message    Message body
     * @return array Same structure as send()
     */
    public function sendBulk(array $recipients, string $message): array
    {
        return $this->send($recipients, $message);
    }

    /**
     * Check Africa's Talking account balance.
     *
     * @return array ['success'=>bool, 'balance'=>'KES 10.50', 'error'=>'...']
     */
    public function balance(): array
    {
        $url = $this->baseUrl . '/version1/user?username=' . urlencode($this->username);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response ?: '{}', true) ?? [];

        if ($httpCode === 200 && isset($data['UserData']['balance'])) {
            return [
                'success' => true,
                'balance' => $data['UserData']['balance'],
                'error'   => '',
            ];
        }

        return [
            'success' => false,
            'balance' => '',
            'error'   => $data['error'] ?? $data['message'] ?? 'Failed to fetch balance',
        ];
    }

    // ── Private Helpers ────────────────────────────────────────────

    /**
     * POST form-encoded data to Africa's Talking API endpoint.
     *
     * @param string $endpoint e.g. '/version1/messaging'
     * @param array  $data     Form fields
     * @return array Decoded JSON response
     */
    private function post(string $endpoint, array $data): array
    {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                'apiKey: ' . $this->apiKey,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            error_log('[AfricasTalking] cURL error: ' . $curlErr);
            return ['error' => 'Network error: ' . $curlErr];
        }

        $decoded = json_decode($response ?: '{}', true);
        if ($decoded === null) {
            error_log('[AfricasTalking] Invalid JSON response (HTTP ' . $httpCode . '): ' . $response);
            return ['error' => 'Invalid API response'];
        }

        return $decoded;
    }

    /**
     * Normalize a Kenyan phone number to E.164 (+254XXXXXXXXX).
     * Handles: 07xx, 01xx, 254xxx, +254xxx formats.
     *
     * @param string $phone Raw phone number
     * @return string Normalized phone in +254 format
     */
    private function formatPhone(string $phone): string
    {
        // Strip everything except digits and leading +
        $cleaned = preg_replace('/[^\d+]/', '', trim($phone));

        // Remove all non-digits for manipulation
        $digits = preg_replace('/\D/', '', $cleaned);

        // Already has country code without +
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        // Leading 07xx → +2547xx
        if (str_starts_with($digits, '07') && strlen($digits) === 10) {
            return '+254' . substr($digits, 1);
        }

        // Leading 01xx → +2541xx
        if (str_starts_with($digits, '01') && strlen($digits) === 10) {
            return '+254' . substr($digits, 1);
        }

        // Already in +254 format
        if (str_starts_with($cleaned, '+254') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        // Fallback: return as-is with + prefix if doesn't have it
        return str_starts_with($cleaned, '+') ? $cleaned : '+' . $digits;
    }
}

// ── Global helper function ─────────────────────────────────────────

/**
 * Return a configured AfricasTalking instance loaded from system_settings.
 * Keys: at_username, at_api_key, at_shortcode, at_env
 *
 * Usage: sms()->send('0712345678', 'Hello!');
 */
function sms(): AfricasTalking
{
    static $instance = null;
    if ($instance !== null) return $instance;

    $cfg = getSettings(['at_username', 'at_api_key', 'at_shortcode', 'at_env']);

    $instance = new AfricasTalking([
        'username'   => $cfg['at_username']  ?? '',
        'api_key'    => $cfg['at_api_key']   ?? '',
        'short_code' => $cfg['at_shortcode'] ?? '',
        'env'        => $cfg['at_env']       ?? 'sandbox',
    ]);

    return $instance;
}
