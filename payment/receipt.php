<?php
declare(strict_types=1);

require_once __DIR__ . '/service.php';

$paymentId = (int) ($_GET['payment_id'] ?? 0);
if ($paymentId <= 0) {
    http_response_code(400);
    exit('Invalid payment selected.');
}

$tenant = current_tenant();
$admin = current_admin();
if (!$tenant && !$admin) {
    require_tenant();
}

$payment = db_one('SELECT id, tenant_id, status FROM payments WHERE id = ?', [$paymentId], 'i');
if (!$payment || (!$admin && (int) $payment['tenant_id'] !== (int) $tenant['id'])) {
    http_response_code(404);
    exit('Payment record not found.');
}
if ($payment['status'] !== 'COMPLETED') {
    http_response_code(409);
    exit('A receipt is available only after payment is completed.');
}

try {
    $pdfPath = generate_payment_receipt_pdf($paymentId);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="receipt-' . $paymentId . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, no-store, max-age=0');
    readfile($pdfPath);
} catch (Throwable $ex) {
    error_log('Payment receipt generation failed for payment ' . $paymentId . ': ' . $ex->getMessage());
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'The payment is complete, but the PDF receipt could not be generated. Please try again later.';
}
