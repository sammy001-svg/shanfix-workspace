<?php
/**
 * OrbitDesk Workspace — KopoKopo M-Pesa Integration
 * API: https://api.kopokopo.com  (production)
 *      https://sandbox.kopokopo.com (sandbox)
 *
 * Flow:
 *   1. kopokopo()->initiateStk() → sends STK push, returns payment_id
 *   2. Store payment_id in mpesa_pending.checkout_id
 *   3. KopoKopo POSTs webhook to /api/mpesa-callback.php on success
 *   4. verifyWebhook() validates HMAC-SHA256 signature
 *   5. Invoice is marked paid, org is notified
 */
class KopoKopo
{
    private string $clientId;
    private string $clientSecret;
    private string $tillNumber;
    private string $apiSecret;
    private string $baseUrl;

    private ?string $cachedToken    = null;
    private int     $tokenExpiresAt = 0;

    public function __construct(array $cfg)
    {
        $this->clientId     = $cfg['client_id']     ?? '';
        $this->clientSecret = $cfg['client_secret'] ?? '';
        $this->tillNumber   = $cfg['till_number']   ?? '';
        $this->apiSecret    = $cfg['api_secret']    ?? '';
        $this->baseUrl      = ($cfg['env'] ?? 'sandbox') === 'production'
            ? 'https://api.kopokopo.com'
            : 'https://sandbox.kopokopo.com';
    }

    // ── OAuth2 token (client_credentials) ────────────────────────

    public function getToken(): string
    {
        if ($this->cachedToken && time() < $this->tokenExpiresAt) {
            return $this->cachedToken;
        }

        $ch = curl_init($this->baseUrl . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body    = curl_exec($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('KopoKopo auth cURL error: ' . $curlErr);
        }
        $data = json_decode($body ?: '{}', true);
        if (empty($data['access_token'])) {
            throw new Exception('KopoKopo token error: ' . ($data['error_description'] ?? "HTTP $status"));
        }
        $this->cachedToken    = $data['access_token'];
        $this->tokenExpiresAt = time() + (int)($data['expires_in'] ?? 3600) - 60;
        return $this->cachedToken;
    }

    // ── STK Push initiation ────────────────────────────────────────

    /**
     * Send an M-Pesa STK push via KopoKopo.
     *
     * @param string $phone       Customer phone (07xx or +2547xx or 2547xx)
     * @param float  $amount      Amount in KES (rounded to nearest integer)
     * @param string $callbackUrl Webhook URL KopoKopo will POST result to
     * @param array  $metadata    Arbitrary key→value pairs sent back in webhook
     * @return string             KopoKopo payment_id (store in mpesa_pending.checkout_id)
     * @throws Exception          On network error or API rejection
     */
    public function initiateStk(string $phone, float $amount, string $callbackUrl, array $metadata = []): string
    {
        $token = $this->getToken();

        $payload = [
            'payment_channel' => 'M-PESA STK Push',
            'till_number'     => $this->tillNumber,
            'subscriber'      => [
                'phone_number' => $this->formatPhone($phone),
                'first_name'   => $metadata['first_name'] ?? '',
                'last_name'    => $metadata['last_name']  ?? '',
                'email'        => $metadata['email']      ?? '',
            ],
            'amount'   => ['currency' => 'KES', 'value' => (int)round($amount)],
            'metadata' => $metadata,
            '_links'   => ['callback_url' => $callbackUrl],
        ];

        $responseHeaders = [];
        $ch = curl_init($this->baseUrl . '/api/v1/incoming_payments');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$responseHeaders) {
                $parts = explode(':', $h, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($h);
            },
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body    = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('KopoKopo STK cURL error: ' . $curlErr);
        }
        if ($httpCode !== 201) {
            $dec = json_decode($body ?: '{}', true);
            throw new Exception('KopoKopo STK rejected [' . $httpCode . ']: '
                . ($dec['error_description'] ?? $dec['message'] ?? $body));
        }

        // Payment ID is in the Location header
        // e.g. https://sandbox.kopokopo.com/api/v1/incoming_payments/{payment_id}
        $location  = $responseHeaders['location'] ?? '';
        $paymentId = $location ? basename(parse_url(rtrim($location, "\r\n"), PHP_URL_PATH)) : '';

        if (!$paymentId) {
            throw new Exception('KopoKopo did not return a payment ID in Location header.');
        }
        return $paymentId;
    }

    // ── Webhook signature verification ────────────────────────────

    /**
     * Verify a KopoKopo webhook signature.
     * KopoKopo sends HMAC-SHA256(raw_body, api_secret) in X-KopoKopo-Signature header.
     */
    public function verifyWebhook(string $rawBody, string $signature): bool
    {
        if (empty($this->apiSecret) || empty($signature)) {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $this->apiSecret);
        return hash_equals($expected, strtolower($signature));
    }

    // ── Payment status check ──────────────────────────────────────

    /**
     * Fetch the current status of a payment from KopoKopo.
     * Returns ['status' => 'Pending'|'Received'|'Failed', 'reference' => 'MPESA-RECEIPT', ...]
     */
    public function checkPayment(string $paymentId): array
    {
        $token = $this->getToken();
        $ch = curl_init($this->baseUrl . '/api/v1/incoming_payments/' . urlencode($paymentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body    = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception('KopoKopo status check cURL error: ' . $curlErr);
        }
        return json_decode($body ?: '{}', true) ?? [];
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Normalise phone to international format: +254XXXXXXXXX
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '254' . substr($digits, 1);
        }
        if (str_starts_with($digits, '254') && strlen($digits) === 12) {
            return '+' . $digits;
        }
        // Already includes country code without +
        return '+' . ltrim($digits, '+');
    }

    /**
     * Parse a KopoKopo webhook payload into a normalised array.
     * Handles both v1 event format and v2 data/attributes format.
     *
     * @return array [
     *   'payment_id'    => string,
     *   'status'        => string,   // 'Received' | 'Failed' | 'Pending'
     *   'receipt'       => string,   // M-Pesa receipt number
     *   'amount'        => float,
     *   'phone'         => string,
     *   'metadata'      => array,
     *   'event_type'    => string,
     * ]
     */
    public static function parseWebhook(array $data): array
    {
        // v1: { event: { type, resource: { ... } } }
        $resource  = $data['event']['resource']   ?? [];
        $eventType = $data['event']['type']        ?? '';

        // v2: { data: { id, attributes: { status, event: { resource: {...} } } } }
        if (empty($resource) && isset($data['data']['attributes'])) {
            $attrs    = $data['data']['attributes'];
            $resource = $attrs['event']['resource'] ?? $attrs;
            $eventType = $attrs['event']['type']    ?? ($data['data']['type'] ?? '');
        }

        $meta = $resource['metadata'] ?? [];

        return [
            'payment_id' => $resource['id']                  ?? ($data['data']['id'] ?? ''),
            'status'     => $resource['status']              ?? 'Pending',
            'receipt'    => $resource['reference']           ?? '',
            'amount'     => (float)($resource['amount']      ?? 0),
            'phone'      => $resource['sender_phone_number'] ?? '',
            'metadata'   => is_array($meta) ? $meta : [],
            'event_type' => $eventType,
        ];
    }

    public function getTillNumber(): string { return $this->tillNumber; }
}

// ── Global factory ────────────────────────────────────────────────

/**
 * Returns a KopoKopo instance configured from system_settings.
 */
function kopokopo(): KopoKopo
{
    $cfg = [];
    try {
        if (function_exists('getSettings')) {
            $cfg = getSettings([
                'kopokopo_client_id', 'kopokopo_client_secret',
                'kopokopo_till_number', 'kopokopo_api_secret', 'kopokopo_env',
            ]);
        }
    } catch (Exception $e) { /* DB not ready */ }

    return new KopoKopo([
        'client_id'     => $cfg['kopokopo_client_id']     ?? (defined('KK_CLIENT_ID')     ? KK_CLIENT_ID     : ''),
        'client_secret' => $cfg['kopokopo_client_secret'] ?? (defined('KK_CLIENT_SECRET') ? KK_CLIENT_SECRET : ''),
        'till_number'   => $cfg['kopokopo_till_number']   ?? (defined('KK_TILL_NUMBER')   ? KK_TILL_NUMBER   : ''),
        'api_secret'    => $cfg['kopokopo_api_secret']    ?? (defined('KK_API_SECRET')    ? KK_API_SECRET    : ''),
        'env'           => ($cfg['kopokopo_env']          ?? (defined('KK_ENV')           ? KK_ENV           : '')) ?: 'sandbox',
    ]);
}
