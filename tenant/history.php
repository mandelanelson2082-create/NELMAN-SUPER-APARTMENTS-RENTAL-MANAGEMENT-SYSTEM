<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();
$tid = (int) $tenant['id'];

$payments = db_all(
    "SELECT p.*, COALESCE(i.invoice_number, allocated.invoice_numbers) invoice_number
       FROM payments p
       LEFT JOIN invoices i ON i.id = p.invoice_id
       LEFT JOIN (
            SELECT a.payment_id,
                   GROUP_CONCAT(i.invoice_number ORDER BY i.due_date, i.created_at, i.id SEPARATOR ', ') invoice_numbers
              FROM payment_allocations a
              JOIN invoices i ON i.id = a.invoice_id
             GROUP BY a.payment_id
       ) allocated ON allocated.payment_id = p.id
      WHERE p.tenant_id = ? ORDER BY p.created_at DESC",
    [$tid], 'i'
);

$pageTitle = 'Payment History';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<h1 class="h4 mb-3"><i class="bi bi-clock-history"></i> My Payment History</h1>
<div class="card">
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Date</th><th>Invoice</th><th>Merchant Ref</th><th>Method</th>
          <th>Confirmation</th><th class="text-end">Amount</th><th>Status</th><th></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$payments): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">No payments recorded yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($payments as $p): ?>
          <tr>
            <td class="small"><?= e(date('d M Y H:i', strtotime($p['created_at']))) ?></td>
            <td><code><?= e($p['invoice_number'] ?? '—') ?></code></td>
            <td class="small"><code><?= e($p['merchant_reference']) ?></code></td>
            <td class="small"><?= e($p['payment_method'] ?? '—') ?></td>
            <td class="small"><?= e($p['confirmation_code'] ?? '—') ?></td>
            <td class="text-end"><?= e(money($p['amount'])) ?></td>
            <td><?= status_badge($p['status']) ?></td>
            <td><?php if ($p['status'] === 'COMPLETED'): ?>
              <a class="btn btn-sm btn-outline-success" href="<?= e(base_url('payment/receipt.php?payment_id=' . (int) $p['id'])) ?>">PDF receipt</a>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
