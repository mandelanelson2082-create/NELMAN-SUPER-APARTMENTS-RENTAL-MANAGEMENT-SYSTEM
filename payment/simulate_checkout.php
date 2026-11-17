<?php
declare(strict_types=1);

/**
 * SIMULATE-mode mock Pesapal checkout.
 * Stands in for the hosted Pesapal page so the end-to-end flow (redirect ->
 * pay -> callback -> verify -> invoice update) can be demonstrated in XAMPP
 * without live gateway credentials. Never used when real credentials are set.
 */

require_once __DIR__ . '/../config/app.php';

// This is a mock gateway: it must not exist once real Pesapal credentials
// are configured, otherwise it could be used to forge a COMPLETED payment.
if (!pesapal_is_simulate()) {
    http_response_code(404);
    exit('Not found.');
}

$tenant = require_tenant();

$trackingId = (string) ($_GET['order_tracking_id'] ?? $_POST['order_tracking_id'] ?? '');
$payment = $trackingId !== ''
    ? db_one('SELECT * FROM payments WHERE order_tracking_id = ? LIMIT 1', [$trackingId], 's')
    : null;

if (!$payment || (int) $payment['tenant_id'] !== (int) $tenant['id']) {
    http_response_code(404);
    exit('Simulated order not found.');
}

$invoice = $payment['invoice_id'] ? get_invoice((int) $payment['invoice_id']) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $map = ['complete' => 'COMPLETED', 'fail' => 'FAILED', 'cancel' => 'CANCELLED'];
    $status = $map[$_POST['choice'] ?? ''] ?? null;
    if ($status === null) {
        http_response_code(400);
        exit('Invalid checkout choice.');
    }

    $method = in_array($_POST['method'] ?? '', ['M-PESA', 'CARD', 'AIRTEL', 'BANK'], true)
        ? $_POST['method'] : 'M-PESA';
    $conf = $status === 'COMPLETED' ? ('SIM' . random_int(100000, 999999)) : null;

    // Only a still-pending order can be settled; a completed payment must
    // never be flipped to FAILED/CANCELLED by a repeated request.
    $settled = db_exec(
        "UPDATE payments SET status = ?, payment_method = ?, confirmation_code = ?,
            payment_account = ?, transaction_date = NOW(),
            raw_response = ? WHERE id = ? AND status = 'PENDING'",
        [$status, $method, $conf, $payment['payment_account'] ?: $tenant['phone'],
         json_encode(['simulated' => true, 'choice' => $_POST['choice']]), $payment['id']],
        'sssssi'
    );
    if ($settled === 1 && $status === 'COMPLETED') {
        if ($payment['invoice_id']) {
            recalc_invoice((int) $payment['invoice_id']);
        } else {
            apply_tenant_credit((int) $payment['tenant_id']);
        }
    }

    // Simulate the gateway notifying our IPN endpoint (self-call kept simple:
    // we just redirect to the tenant return page which re-verifies).
    redirect(base_url('tenant/payment_status.php?OrderTrackingId=' . urlencode($trackingId)));
}

$pageTitle = 'Simulated Checkout';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="auth-wrapper" style="max-width:520px">
  <div class="card">
    <div class="card-header bg-warning text-dark">
      <i class="bi bi-exclamation-triangle"></i> Simulated Pesapal Checkout (SIMULATE mode)
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        This mock page replaces the hosted Pesapal checkout during development.
        Choose an outcome to exercise the full verification flow.
      </p>
      <table class="table table-sm">
        <tr><th>Tenant</th><td><?= e($tenant['name'] ?? '—') ?></td></tr>
        <tr><th>Invoice</th><td><?= e($invoice['invoice_number'] ?? '—') ?></td></tr>
        <tr><th>M-Pesa phone</th><td><?= e($payment['payment_account'] ?: ($tenant['phone'] ?? '—')) ?></td></tr>
        <tr><th>Amount</th><td class="fw-bold"><?= e(money($payment['amount'])) ?></td></tr>
        <tr><th>Merchant Ref</th><td><code><?= e($payment['merchant_reference']) ?></code></td></tr>
        <tr><th>Tracking ID</th><td><code><?= e($trackingId) ?></code></td></tr>
      </table>

      <?php if ($payment['status'] !== 'PENDING'): ?>
        <div class="alert alert-secondary">This order is already <?= e($payment['status']) ?>.</div>
        <a class="btn btn-dark" href="<?= e(base_url('tenant/dashboard.php')) ?>">Back to Dashboard</a>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="order_tracking_id" value="<?= e($trackingId) ?>">
        <div class="mb-3">
          <label class="form-label">Payment method</label>
          <select name="method" class="form-select">
            <option>M-PESA</option><option>CARD</option><option>AIRTEL</option><option>BANK</option>
          </select>
        </div>
        <div class="d-grid gap-2">
          <button class="btn btn-success" name="choice" value="complete"><i class="bi bi-check-circle"></i> Complete Payment</button>
          <button class="btn btn-danger" name="choice" value="fail"><i class="bi bi-x-circle"></i> Fail Payment</button>
          <button class="btn btn-secondary" name="choice" value="cancel"><i class="bi bi-slash-circle"></i> Cancel</button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
