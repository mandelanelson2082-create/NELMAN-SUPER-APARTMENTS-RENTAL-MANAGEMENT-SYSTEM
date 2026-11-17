<?php
declare(strict_types=1);

/**
 * Pesapal IPN / callback endpoint (proposal 4.9).
 *
 * Pesapal notifies this URL (GET or POST) with the order tracking id.
 * We DO NOT trust the payload amount/status -- we re-query the transaction
 * status from Pesapal, then update the payment and its invoice.
 *
 * Configure this as the IPN URL: .../payment/callback.php
 */

require_once __DIR__ . '/service.php';

$trackingId = (string) (
    $_GET['OrderTrackingId']
    ?? $_GET['order_tracking_id']
    ?? $_POST['OrderTrackingId']
    ?? $_POST['order_tracking_id']
    ?? ''
);
$merchantRef = (string) (
    $_GET['OrderMerchantReference']
    ?? $_GET['merchant_reference']
    ?? $_POST['OrderMerchantReference']
    ?? $_POST['merchant_reference']
    ?? ''
);
$notificationType = (string) (
    $_GET['OrderNotificationType']
    ?? $_GET['orderNotificationType']
    ?? $_POST['OrderNotificationType']
    ?? $_POST['orderNotificationType']
    ?? ''
);

header('Content-Type: application/json');

if ($trackingId === '' && $merchantRef === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing order tracking id']);
    exit;
}

$payment = find_payment($merchantRef !== '' ? $merchantRef : null, $trackingId !== '' ? $trackingId : null);
if (!$payment) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Payment record not found']);
    exit;
}

try {
    reconcile_payment($payment);
    echo json_encode([
        'orderNotificationType' => $notificationType,
        'orderTrackingId' => $trackingId !== '' ? $trackingId : $payment['order_tracking_id'],
        'orderMerchantReference' => $merchantRef !== '' ? $merchantRef : $payment['merchant_reference'],
        'status' => 200,
    ]);
} catch (Throwable $ex) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $ex->getMessage()]);
}
