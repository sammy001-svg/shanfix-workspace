<?php
/**
 * OrbitDesk Workspace — M-Pesa Daraja API Integration
 * Supports: STK Push (Lipa Na M-Pesa Online), C2B, B2C, Transaction Status
 */
class Mpesa
{
    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;
    private string $env; // 'sandbox' | 'live'
    private string $baseUrl;

    public function __construct(array $config = [])
    {
        // Load from config or fall back to DB settings
        global $pdo;
        $this->consumerKey    = $config['consumer_key']    ?? '';
        $this->consumerSecret = $config['consumer_secret'] ?? '';
        $this->shortcode      = $config['shortcode']       ?? '';
        $this->passkey        = $config['passkey']         ?? '';
        $this->env            = $config['env']             ?? 'sandbox';
        $this->callbackUrl    = $config['callback_url']    ?? (APP_URL . '/api/mpesa-callback.php');
        $this->baseUrl        = $this->env === 'live'
            ? 'https://api.safaricom.co.ke'
            : 'https://sandbox.safaricom.co.ke';
    }

    /**
     * Get OAuth access token
     */
    public function getAccessToken(): ?string
    {
        $url  = $this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials';
        $auth = base64_encode($this->consumerKey . ':' . $this->consumerSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ["Authorization: Basic $auth"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if (!$response) return null;
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Initiate STK Push (Lipa Na M-Pesa Online)
     * @param string $phone   Customer phone: 254XXXXXXXXX
     * @param float  $amount  Amount in KES (whole number)
     * @param string $ref     Account reference (e.g. invoice number)
     * @param string $desc    Transaction description
     */
    public function stkPush(string $phone, float $amount, string $ref = 'Payment', string $desc = 'OrbitDesk Subscription'): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['success' => false, 'message' => 'Failed to get access token'];

        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $phone     = $this->formatPhone($phone);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int) round($amount),
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => substr($ref, 0, 20),
            'TransactionDesc'   => substr($desc, 0, 13),
        ];

        $url = $this->baseUrl . '/mpesa/stkpush/v1/processrequest';
        $result = $this->post($url, $payload, $token);

        if (isset($result['ResponseCode']) && $result['ResponseCode'] === '0') {
            return [
                'success'       => true,
                'checkout_id'   => $result['CheckoutRequestID'],
                'merchant_id'   => $result['MerchantRequestID'],
                'message'       => 'STK push sent. Ask customer to check their phone.',
            ];
        }

        return [
            'success' => false,
            'message' => $result['errorMessage'] ?? $result['ResponseDescription'] ?? 'STK push failed',
        ];
    }

    /**
     * Check STK Push status
     */
    public function stkQuery(string $checkoutRequestId): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['success' => false, 'message' => 'Token error'];

        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $url    = $this->baseUrl . '/mpesa/stkpushquery/v1/query';
        $result = $this->post($url, $payload, $token);

        $code = $result['ResultCode'] ?? null;
        return [
            'success' => $code === 0 || $code === '0',
            'paid'    => $code === 0 || $code === '0',
            'message' => $result['ResultDesc'] ?? 'Unknown status',
            'raw'     => $result,
        ];
    }

    /**
     * B2C — Send money to customer (refund, disbursement)
     */
    public function b2c(string $phone, float $amount, string $remarks = 'Refund'): array
    {
        $token = $this->getAccessToken();
        if (!$token) return ['success' => false, 'message' => 'Token error'];

        $payload = [
            'InitiatorName'      => 'testapi',
            'SecurityCredential' => '',
            'CommandID'          => 'BusinessPayment',
            'Amount'             => (int) round($amount),
            'PartyA'             => $this->shortcode,
            'PartyB'             => $this->formatPhone($phone),
            'Remarks'            => substr($remarks, 0, 100),
            'QueueTimeOutURL'    => $this->callbackUrl,
            'ResultURL'          => $this->callbackUrl,
            'Occasion'           => '',
        ];

        $url    = $this->baseUrl . '/mpesa/b2c/v1/paymentrequest';
        $result = $this->post($url, $payload, $token);

        return [
            'success' => isset($result['ResponseCode']) && $result['ResponseCode'] === '0',
            'message' => $result['ResponseDescription'] ?? 'Unknown',
            'raw'     => $result,
        ];
    }

    /**
     * Parse STK callback from Safaricom
     */
    public static function parseCallback(): array
    {
        $raw  = file_get_contents('php://input');
        $data = json_decode($raw, true);

        $body = $data['Body']['stkCallback'] ?? [];
        $code = $body['ResultCode'] ?? -1;

        if ($code !== 0) {
            return ['success' => false, 'message' => $body['ResultDesc'] ?? 'Failed'];
        }

        $items = $body['CallbackMetadata']['Item'] ?? [];
        $meta  = [];
        foreach ($items as $item) {
            $meta[$item['Name']] = $item['Value'] ?? null;
        }

        return [
            'success'       => true,
            'amount'        => $meta['Amount']            ?? 0,
            'receipt'       => $meta['MpesaReceiptNumber'] ?? '',
            'phone'         => $meta['PhoneNumber']       ?? '',
            'transaction_date' => $meta['TransactionDate'] ?? '',
            'checkout_id'   => $body['CheckoutRequestID'] ?? '',
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0'))   $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+254')) $phone = substr($phone, 1);
        return $phone;
    }

    private function post(string $url, array $payload, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $token",
                'Content-Type: application/json',
            ],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response ?: '{}', true) ?? [];
    }
}

// ── M-Pesa callback handler (include from api/mpesa-callback.php) ──
// Usage example:
//   $mpesa = new Mpesa(['consumer_key'=>'...','consumer_secret'=>'...','shortcode'=>'174379','passkey'=>'...']);
//   $result = $mpesa->stkPush('0712345678', 500, 'INV-001', 'OrbitDesk Invoice');
//   if ($result['success']) { /* store checkout_id, poll for completion */ }
