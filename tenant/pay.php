<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);
$invoice = $invoiceId ? get_invoice($invoiceId) : null;

if ($invoice && (int) $invoice['tenant_id'] !== (int) $tenant['id']) {
    flash_set('error', 'Invoice not found.');
    redirect(base_url('tenant/dashboard.php'));
}

$stats = tenant_stats((int) $tenant['id']);
$due = $invoice ? round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2) : $stats['arrears'];
$house = $tenant['house_id'] ? get_house($tenant['house_id']) : null;
$isCustom = ($invoice['type'] ?? 'RENT') === 'CUSTOM';
$defaultAmount = $due > 0 ? $due : max(0.01, (float) $tenant['rent_amount']);

$pageTitle = 'Pay Rent';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-8">
    <div class="card">
      <div class="card-header"><i class="bi bi-credit-card-2-front"></i> Pay Rent</div>
      <div class="card-body">
        <?php if ($invoice): ?>
          <table class="table">
            <tr><th style="width:40%">Invoice Number</th><td><code><?= e($invoice['invoice_number']) ?></code></td></tr>
            <tr><th><?= $isCustom ? 'Description' : 'Period' ?></th><td><?= e(invoice_period_label($invoice)) ?></td></tr>
            <tr><th>Invoice Amount</th><td><?= e(money($invoice['amount'])) ?></td></tr>
            <tr><th>Already Paid</th><td><?= e(money($invoice['amount_paid'])) ?></td></tr>
            <tr class="table-light"><th>Current Balance</th><td class="fw-bold <?= $due > 0 ? 'text-danger' : 'text-success' ?>"><?= e(money($due)) ?></td></tr>
          </table>
        <?php else: ?>
          <div class="row g-2 mb-3">
            <div class="col-md-6"><div class="border rounded p-3"><div class="small text-muted">Current outstanding</div><strong><?= e(money($stats['arrears'])) ?></strong></div></div>
            <div class="col-md-6"><div class="border rounded p-3"><div class="small text-muted">Available rent credit</div><strong class="text-success"><?= e(money($stats['credit'])) ?></strong></div></div>
          </div>
        <?php endif; ?>

        <div class="alert alert-info small">
          <i class="bi bi-shield-lock"></i>
          You will be redirected to Pesapal to complete the payment. Enter your M-Pesa PIN only on Pesapal's secure page; this system never asks for or stores it.
          Payments first clear your oldest outstanding invoices. Any extra amount is retained as rent credit and automatically applied to future invoices.
          <?php if (pesapal_is_simulate()): ?><br><strong>SIMULATE mode:</strong> a local mock checkout will be shown.<?php endif; ?>
        </div>

        <form method="post" action="<?= e(base_url('payment/create.php')) ?>" class="row g-3">
          <?= csrf_field() ?>
          <?php if ($invoice): ?><input type="hidden" name="invoice_id" value="<?= (int) $invoice['id'] ?>"><?php endif; ?>
          <div class="col-md-6">
            <label class="form-label">M-Pesa phone number</label>
            <input type="tel" class="form-control" name="phone" required value="<?= e($tenant['phone']) ?>" inputmode="tel" autocomplete="tel" placeholder="e.g. 0712345678">
            <div class="form-text">Pesapal uses this number for the M-Pesa payment prompt.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Amount to pay (<?= e(DEFAULT_CURRENCY) ?>)</label>
            <input type="number" class="form-control" name="amount" required min="0.01" step="0.01" value="<?= e($defaultAmount) ?>">
            <div class="form-text">You may pay more than the current balance as advance rent.</div>
          </div>
          <div class="col-12 d-flex gap-2">
            <button class="btn btn-success"><i class="bi bi-arrow-right-circle"></i> Continue to Pesapal</button>
            <a class="btn btn-outline-secondary" href="<?= e(base_url('tenant/dashboard.php')) ?>">Cancel</a>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
