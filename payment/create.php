<?php
declare(strict_types=1);

/**
 * Tenant-initiated payment (proposal 1.11 / 3.12 / 4.7).
 * POST { invoice_id, phone, amount } -> validate -> create PENDING payment -> submit Pesapal order
 * -> store tracking id -> redirect tenant to checkout.
 */

require_once __DIR__ . '/service.php';

$tenant = require_tenant();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(base_url('tenant/dashboard.php'));
}
verify_csrf();

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$requestedAmount = trim((string) ($_POST['amount'] ?? ''));
$phone = preg_replace('/[\s()\-]+/', '', trim((string) ($_POST['phone'] ?? '')));
$invoice = $invoiceId ? get_invoice($invoiceId) : null;

// --- validation ---
if ($invoice && (int) $invoice['tenant_id'] !== (int) $tenant['id']) {
    flash_set('error', 'Invoice does not belong to you.');
    redirect(base_url('tenant/dashboard.php'));
}
if (!is_numeric($requestedAmount) || (float) $requestedAmount <= 0) {
    flash_set('error', 'Enter a valid payment amount.');
    redirect(base_url('tenant/pay.php' . ($invoiceId ? '?invoice_id=' . $invoiceId : '')));
}
$amount = round((float) $requestedAmount, 2);
if (!preg_match('/^\+?[0-9]{7,15}$/', (string) $phone)) {
    flash_set('error', 'Enter a valid phone number for the M-Pesa prompt.');
    redirect(base_url('tenant/pay.php' . ($invoiceId ? '?invoice_id=' . $invoiceId : '')));
}

// Every tenant-initiated rent payment is held as credit first. On verified completion,
// credit is allocated oldest-invoice-first and any remainder stays available for future rent.
$merchantRef = generate_merchant_reference();
$paymentId = db_insert(
    'INSERT INTO payments (tenant_id, invoice_id, amount, currency, merchant_reference, payment_account, status)
     VALUES (?,?,?,?,?,?,?)',
    [$tenant['id'], null, $amount, DEFAULT_CURRENCY, $merchantRef, $phone, 'PENDING'],
    'iidssss'
);

// --- submit order to Pesapal ---
try {
    $client = new PesapalClient();

    $cfg = pesapal_config();
    $ipnUrl = $cfg['ipn_url'] ?: (current_scheme_host() . base_url('payment/callback.php'));
    $redirectUrl = $cfg['redirect_url'] ?: (current_scheme_host() . base_url('tenant/payment_status.php'));

    $nameParts = explode(' ', trim($tenant['name']), 2);
    $orderRequest = [
        'merchant_reference' => $merchantRef,
        'currency' => DEFAULT_CURRENCY,
        'amount' => $amount,
        'description' => $invoice ? ($invoice['description'] ?: ('Invoice ' . $invoice['invoice_number'])) : 'Advance rent payment',
        'callback_url' => $redirectUrl,   // where the tenant returns after paying
        'notification_id' => pesapal_notification_id($ipnUrl),
        'email' => $tenant['email'] ?: ($tenant['phone'] . '@example.com'),
        'phone' => $phone,
        'first_name' => $nameParts[0],
        'last_name' => $nameParts[1] ?? '',
    ];
    try {
        $order = $client->submitOrder($orderRequest);
    } catch (PesapalApiException $ex) {
        if ($ex->apiCode !== 'InvalidIpnId') {
            throw $ex;
        }
        $orderRequest['notification_id'] = pesapal_notification_id($ipnUrl, true);
        $order = $client->submitOrder($orderRequest);
    }

    db_exec(
        'UPDATE payments SET order_tracking_id = ?, raw_response = ? WHERE id = ?',
        [$order['order_tracking_id'], json_encode($order['raw']), $paymentId],
        'ssi'
    );

    redirect($order['redirect_url']);
} catch (Throwable $ex) {
    db_exec("UPDATE payments SET status = 'FAILED', raw_response = ? WHERE id = ?",
        [substr($ex->getMessage(), 0, 500), $paymentId], 'si');
    flash_set('error', 'Could not start payment: ' . $ex->getMessage());
    redirect(base_url('tenant/dashboard.php'));
}

/** Best-effort absolute scheme+host for building public callback URLs. */
function current_scheme_host(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $https . '://' . $host;
}

/** Reuse the IPN registration for this callback URL and Pesapal environment. */
function pesapal_notification_id(string $ipnUrl, bool $forceRefresh = false): string
{
    if (pesapal_is_simulate()) {
        return '';
    }

    $base = pesapal_config()['base'];
    $registeredUrl = setting('pesapal_ipn_registered_url');
    $registeredBase = setting('pesapal_ipn_registered_base');
    $notificationId = setting('pesapal_ipn_notification_id');
    if (!$forceRefresh && $registeredUrl === $ipnUrl && $registeredBase === $base && $notificationId !== null) {
        return $notificationId;
    }

    $client = new PesapalClient();
    $notificationId = $client->registerIpn($ipnUrl);
    set_setting('pesapal_ipn_registered_url', $ipnUrl);
    set_setting('pesapal_ipn_registered_base', $base);
    set_setting('pesapal_ipn_notification_id', $notificationId);
    return $notificationId;
}
