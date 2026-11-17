<?php
declare(strict_types=1);

/**
 * Pesapal API 3.0 client.
 *
 * Handles: OAuth token, submit order, transaction status, IPN registration.
 * In SIMULATE mode no external calls are made -- a local mock checkout drives
 * the same lifecycle so the flow is fully testable without credentials.
 */
class PesapalApiException extends RuntimeException
{
    public string $apiCode;

    public function __construct(string $apiCode, string $message)
    {
        $this->apiCode = $apiCode;
        parent::__construct($message);
    }
}

class PesapalClient
{
    private string $base;
    private string $key;
    private string $secret;
    private bool $simulate;
    private ?string $token = null;

    public function __construct()
    {
        $cfg = pesapal_config();
        $this->key = $cfg['key'];
        $this->secret = $cfg['secret'];
        $this->simulate = $cfg['simulate'];
        $this->base = $cfg['base'];
    }

    public function isSimulated(): bool
    {
        return $this->simulate;
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** OAuth bearer token (real modes only). */
    public function token(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }
        $res = $this->request('POST', '/api/Auth/RequestToken', [
            'consumer_key' => $this->key,
            'consumer_secret' => $this->secret,
        ]);
        $this->token = $res['token'] ?? '';
        if ($this->token === '') {
            throw new RuntimeException('Pesapal auth failed: ' . json_encode($res));
        }
        return $this->token;
    }

    /**
     * Submit a payment order.
     * Returns ['order_tracking_id'=>string, 'redirect_url'=>string, 'raw'=>array]
     */
    public function submitOrder(array $order): array
    {
        if ($this->simulate) {
            $tracking = 'SIM-' . strtoupper(bin2hex(random_bytes(6)));
            return [
                'order_tracking_id' => $tracking,
                'redirect_url' => base_url('payment/simulate_checkout.php?order_tracking_id=' . urlencode($tracking)),
                'raw' => ['simulated' => true, 'order_tracking_id' => $tracking],
            ];
        }

        $payload = [
            'id' => $order['merchant_reference'],
            'currency' => $order['currency'],
            'amount' => (float) $order['amount'],
            'description' => $order['description'],
            'callback_url' => $order['callback_url'],
            'notification_id' => $order['notification_id'] ?? '',
            'billing_address' => [
                'email_address' => $order['email'],
                'phone_number' => $order['phone'],
                'first_name' => $order['first_name'],
                'last_name' => $order['last_name'] ?? '',
            ],
        ];

        $res = $this->request('POST', '/api/Transactions/SubmitOrderRequest', $payload, $this->token());
        if (isset($res['error'])) {
            $apiCode = (string) ($res['error']['code'] ?? '');
            throw new PesapalApiException($apiCode, 'Pesapal submitOrder failed: ' . json_encode($res));
        }
        $tracking = $res['order_tracking_id'] ?? '';
        $redirect = $res['redirect_url'] ?? '';
        if ($tracking === '' || $redirect === '') {
            throw new RuntimeException('Pesapal submitOrder failed: ' . json_encode($res));
        }
        return ['order_tracking_id' => $tracking, 'redirect_url' => $redirect, 'raw' => $res];
    }

    /**
     * Retrieve transaction status.
     * Returns a normalized array:
     *   ['status'=>PENDING|COMPLETED|FAILED|CANCELLED, 'payment_method'=>?, 'confirmation_code'=>?,
     *    'payment_account'=>?, 'amount'=>?, 'raw'=>array]
     */
    public function transactionStatus(string $orderTrackingId): array
    {
        if ($this->simulate) {
            // Read the locally-stored simulated outcome.
            $p = db_one(
                'SELECT * FROM payments WHERE order_tracking_id = ? LIMIT 1',
                [$orderTrackingId],
                's'
            );
            if (!$p) {
                return ['status' => 'FAILED', 'raw' => ['error' => 'unknown order']];
            }
            return [
                'status' => $p['status'],
                'payment_method' => $p['payment_method'],
                'confirmation_code' => $p['confirmation_code'],
                'payment_account' => $p['payment_account'],
                'amount' => $p['amount'],
                'currency' => $p['currency'],
                'raw' => ['simulated' => true, 'status' => $p['status']],
            ];
        }

        $res = $this->request(
            'GET',
            '/api/Transactions/GetTransactionStatus?orderTrackingId=' . urlencode($orderTrackingId),
            null,
            $this->token()
        );

        $description = strtoupper(trim((string) ($res['payment_status_description'] ?? '')));
        $descriptionMap = [
            'COMPLETED' => 'COMPLETED',
            'FAILED' => 'FAILED',
            'INVALID' => 'FAILED',
            'REVERSED' => 'FAILED',
            'CANCELLED' => 'CANCELLED',
            'PENDING' => 'PENDING',
        ];
        $codeMap = ['0' => 'FAILED', '1' => 'COMPLETED', '2' => 'FAILED', '3' => 'FAILED'];
        $code = (string) ($res['payment_status_code'] ?? $res['status_code'] ?? '');
        $status = $descriptionMap[$description] ?? ($codeMap[$code] ?? 'PENDING');

        return [
            'status' => $status,
            'payment_method' => $res['payment_method'] ?? null,
            'confirmation_code' => $res['confirmation_code'] ?? null,
            'payment_account' => $res['payment_account'] ?? ($res['message'] ?? null),
            'amount' => $res['amount'] ?? null,
            'currency' => $res['currency'] ?? null,
            'raw' => $res,
        ];
    }

    /** Register the IPN URL and return its notification id (real modes). */
    public function registerIpn(string $ipnUrl): string
    {
        if ($this->simulate) {
            return '';
        }
        $res = $this->request('POST', '/api/URLSetup/RegisterIPN', [
            'url' => $ipnUrl,
            'ipn_notification_type' => 'GET',
        ], $this->token());
        $id = (string) ($res['ipn_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('Pesapal IPN registration failed: ' . json_encode($res));
        }
        return $id;
    }

    /* ------------------------- low-level HTTP ------------------------- */

    private function request(string $method, string $path, ?array $body, ?string $token = null): array
    {
        $url = $this->base . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize the Pesapal HTTP request.');
        }
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
        ];
        if ($token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 45,
        ]);
        if ($body !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = curl_exec($ch);
        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Pesapal cURL error: ' . $err);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("Pesapal returned non-JSON (HTTP $httpCode): " . substr((string) $response, 0, 300));
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $apiCode = (string) ($decoded['error']['code'] ?? $decoded['code'] ?? '');
            throw new PesapalApiException(
                $apiCode,
                "Pesapal request failed (HTTP $httpCode): " . substr((string) $response, 0, 300)
            );
        }
        return $decoded;
    }
}
