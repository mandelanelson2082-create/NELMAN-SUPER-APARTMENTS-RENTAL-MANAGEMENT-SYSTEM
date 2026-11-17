<?php
declare(strict_types=1);

/**
 * Tenant return page after the Pesapal checkout redirect (proposal 4.9).
 * We re-query the transaction status server-side before showing any result --
 * a browser redirect alone is never treated as proof of payment.
 */

require_once __DIR__ . '/../payment/service.php';

$tenant = require_tenant();

$trackingId = (string) ($_GET['OrderTrackingId'] ?? $_GET['order_tracking_id'] ?? '');
$payment = find_payment(null, $trackingId);

if (!$payment || (int) $payment['tenant_id'] !== (int) $tenant['id']) {
    flash_set('error', 'Payment record not found.');
    redirect(base_url('tenant/dashboard.php'));
}

// Re-verify with the gateway (idempotent for already-completed payments).
$payment = reconcile_payment($payment) ?: $payment;
$invoice = $payment['invoice_id'] ? get_invoice((int) $payment['invoice_id']) : null;

$pageTitle = 'Payment Status';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';

$alert = match ($payment['status']) {
    'COMPLETED' => ['success', 'check-circle', 'Payment Successful'],
    'PENDING'   => ['warning', 'hourglass-split', 'Payment Pending'],
    'FAILED'    => ['danger', 'x-circle', 'Payment Failed'],
    'CANCELLED' => ['secondary', 'slash-circle', 'Payment Cancelled'],
    default     => ['secondary', 'question-circle', $payment['status']],
};
?>
<div class="row justify-content-center">
  <div class="col-md-8">
    <div class="card">
      <div class="card-body text-center p-4">
        <i class="bi bi-<?= $alert[1] ?> display-3 text-<?= $alert[0] ?>"></i>
        <h1 class="h4 mt-2"><?= e($alert[2]) ?></h1>
        <p class="text-muted">
          <?php if ($payment['status'] === 'PENDING'): ?>
            The transaction is still being confirmed. This page will update once Pesapal notifies us.
          <?php elseif ($payment['status'] === 'COMPLETED'): ?>
            Your rent payment was received. It has been applied to outstanding invoices; any remaining amount is available as rent credit for future invoices. Thank you.
          <?php else: ?>
            No funds were captured for this attempt. You may retry from your dashboard.
          <?php endif; ?>
        </p>

        <table class="table table-sm text-start mt-3">
          <tr><th style="width:40%">Amount</th><td><?= e(money($payment['amount'])) ?></td></tr>
          <tr><th>Invoice</th><td><?= e($invoice['invoice_number'] ?? '—') ?></td></tr>
          <?php if ($invoice): ?>
            <tr><th>Invoice Status</th><td><?= status_badge($invoice['status']) ?></td></tr>
          <?php endif; ?>
          <tr><th>Method</th><td><?= e($payment['payment_method'] ?? '—') ?></td></tr>
          <tr><th>Confirmation</th><td><?= e($payment['confirmation_code'] ?? '—') ?></td></tr>
          <tr><th>Merchant Ref</th><td><code><?= e($payment['merchant_reference']) ?></code></td></tr>
          <tr><th>Tracking ID</th><td><code><?= e($payment['order_tracking_id'] ?? '—') ?></code></td></tr>
        </table>

        <a href="<?= e(base_url('tenant/dashboard.php')) ?>" class="btn btn-primary"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="<?= e(base_url('tenant/history.php')) ?>" class="btn btn-outline-secondary"><i class="bi bi-clock-history"></i> Payment History</a>
        <?php if ($payment['status'] === 'COMPLETED'): ?>
          <a href="<?= e(base_url('payment/receipt.php?payment_id=' . (int) $payment['id'])) ?>" class="btn btn-success"><i class="bi bi-file-earmark-pdf"></i> Download PDF receipt</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
