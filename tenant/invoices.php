<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();
$invoices = db_all(
    'SELECT * FROM invoices WHERE tenant_id = ? ORDER BY year DESC, month DESC, due_date DESC, id DESC',
    [(int) $tenant['id']],
    'i'
);
$totalInvoiced = 0.0;
$totalPaid = 0.0;
$remainingBalance = 0.0;
$invoiceCount = 0;
foreach ($invoices as $invoice) {
    if ($invoice['status'] === 'CANCELLED') {
        continue;
    }

    $invoiceCount++;
    $totalInvoiced += (float) $invoice['amount'];
    $totalPaid += (float) $invoice['amount_paid'];
    $remainingBalance += max(0, (float) $invoice['amount'] - (float) $invoice['amount_paid']);
}

$pageTitle = 'My Invoices';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="tenant-invoice-heading">
  <div>
    <h1 class="h3 mb-1"><i class="bi bi-receipt"></i> My Invoices</h1>
    <p class="text-muted mb-0">Review your rent charges, payments, and remaining balances.</p>
  </div>
  <a class="btn btn-success flex-shrink-0" href="<?= e(base_url('tenant/pay.php')) ?>"><i class="bi bi-credit-card"></i> Pay Rent</a>
</div>

<div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-3 mb-4 tenant-invoice-summary">
  <div class="col">
    <div class="card tenant-invoice-summary-card tenant-invoice-summary-total h-100">
      <div class="card-body">
        <div class="tenant-invoice-summary-label">Total Invoiced</div>
        <div class="tenant-invoice-summary-value"><?= e(money($totalInvoiced)) ?></div>
        <div class="tenant-invoice-summary-note"><?= $invoiceCount ?> <?= $invoiceCount === 1 ? 'invoice' : 'invoices' ?></div>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card tenant-invoice-summary-card tenant-invoice-summary-paid h-100">
      <div class="card-body">
        <div class="tenant-invoice-summary-label">Total Paid</div>
        <div class="tenant-invoice-summary-value"><?= e(money($totalPaid)) ?></div>
        <div class="tenant-invoice-summary-note">Payments recorded</div>
      </div>
    </div>
  </div>
  <div class="col">
    <div class="card tenant-invoice-summary-card tenant-invoice-summary-balance h-100">
      <div class="card-body">
        <div class="tenant-invoice-summary-label">Remaining Balance</div>
        <div class="tenant-invoice-summary-value"><?= e(money($remainingBalance)) ?></div>
        <div class="tenant-invoice-summary-note">Across your invoices</div>
      </div>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-header">Invoices (<?= count($invoices) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Invoice</th>
          <th>Period / Description</th>
          <th class="text-end">Amount</th>
          <th class="text-end">Paid</th>
          <th class="text-end">Balance</th>
          <th>Due Date</th>
          <th>Status</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$invoices): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">You don't have any invoices yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $invoice):
          $balance = max(0, round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2));
          $canPay = in_array($invoice['status'], ['UNPAID', 'PARTIAL'], true) && $balance > 0;
          $isCustom = ($invoice['type'] ?? 'RENT') === 'CUSTOM';
        ?>
          <tr>
            <td><code><?= e($invoice['invoice_number']) ?></code></td>
            <td>
              <span class="badge <?= $isCustom ? 'bg-info-subtle text-info-emphasis border border-info-subtle' : 'bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle' ?>"><?= $isCustom ? 'Custom' : 'Rent' ?></span>
              <span class="ms-1"><?= e(invoice_period_label($invoice)) ?></span>
            </td>
            <td class="text-end"><?= e(money($invoice['amount'])) ?></td>
            <td class="text-end"><?= e(money($invoice['amount_paid'])) ?></td>
            <td class="text-end"><?= e(money($balance)) ?></td>
            <td class="small"><?= e($invoice['due_date'] ?? '—') ?></td>
            <td><?= status_badge($invoice['status']) ?></td>
            <td class="text-end">
              <?php if ($canPay): ?>
                <a class="btn btn-sm btn-success" href="<?= e(base_url('tenant/pay.php?invoice_id=' . (int) $invoice['id'])) ?>"><i class="bi bi-credit-card"></i> Pay</a>
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
