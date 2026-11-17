<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../payment/service.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (($_POST['action'] ?? '') === 'record_cash_payment') {
        try {
            $paymentId = record_cash_payment(
                (int) ($_POST['tenant_id'] ?? 0),
                (int) ($_POST['invoice_id'] ?? 0),
                (string) ($_POST['category'] ?? ''),
                trim((string) ($_POST['amount'] ?? ''))
            );
            flash_set('success', 'Cash payment recorded and allocated. Payment #' . $paymentId . '.');
        } catch (Throwable $ex) {
            flash_set('error', 'Could not record cash payment: ' . $ex->getMessage());
        }
        redirect(base_url('admin/payments.php'));
    }

    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    if ($paymentId <= 0) {
        flash_set('error', 'Invalid payment selected.');
        redirect(base_url('admin/payments.php'));
    }

    try {
        $payment = db_one('SELECT * FROM payments WHERE id = ?', [$paymentId], 'i');
        if (!$payment || !$payment['order_tracking_id']) {
            throw new RuntimeException('This payment has no Pesapal order tracking ID to verify.');
        }

        $updatedPayment = reconcile_payment($payment);
        if (($updatedPayment['status'] ?? '') === 'COMPLETED') {
            $message = pesapal_is_simulate()
                ? 'Simulated payment status refreshed.'
                : 'Pesapal confirmed the payment as completed.';
            flash_set('success', $message);
        } elseif (($updatedPayment['status'] ?? '') === 'PENDING') {
            flash_set('warning', 'Pesapal still reports this payment as pending. No completion was recorded.');
        } else {
            flash_set('error', 'Pesapal reports this payment as ' . ($updatedPayment['status'] ?? 'unknown') . '.');
        }
    } catch (Throwable $ex) {
        flash_set('error', 'Could not verify payment with Pesapal: ' . $ex->getMessage());
    }
    redirect(base_url('admin/payments.php'));
}

$filterTenant = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : 0;
$filterStatus = $_GET['status'] ?? '';

$where = '1=1'; $params = []; $types = '';
if ($filterTenant) { $where .= ' AND p.tenant_id=?'; $params[] = $filterTenant; $types .= 'i'; }
if (in_array($filterStatus, ['PENDING','COMPLETED','FAILED','CANCELLED'], true)) { $where .= ' AND p.status=?'; $params[] = $filterStatus; $types .= 's'; }

$payments = db_all(
    "SELECT p.*, t.name tenant_name, t.house_id,
            COALESCE(i.invoice_number, allocated.invoice_numbers) invoice_number
       FROM payments p
       JOIN tenants t ON t.id=p.tenant_id
       LEFT JOIN invoices i ON i.id=p.invoice_id
       LEFT JOIN (
            SELECT a.payment_id,
                   GROUP_CONCAT(i.invoice_number ORDER BY i.due_date, i.created_at, i.id SEPARATOR ', ') invoice_numbers
              FROM payment_allocations a
              JOIN invoices i ON i.id=a.invoice_id
             GROUP BY a.payment_id
       ) allocated ON allocated.payment_id=p.id
      WHERE $where
      ORDER BY p.created_at DESC
      LIMIT 500",
    $params, $types
);
$tenants = db_all('SELECT id,name FROM tenants ORDER BY name');
$cashInvoices = db_all(
    "SELECT i.id, i.tenant_id, i.type, i.invoice_number,
            GREATEST(i.amount - i.amount_paid, 0) outstanding
       FROM invoices i
      WHERE i.status IN ('UNPAID','PARTIAL') AND i.amount > i.amount_paid
      ORDER BY i.due_date, i.created_at, i.id"
);

$totals = db_one(
    "SELECT
       COALESCE(SUM(CASE WHEN status='COMPLETED' THEN amount END),0) completed,
       COALESCE(SUM(CASE WHEN status='PENDING' THEN amount END),0) pending,
       COUNT(*) c
     FROM payments p WHERE $where",
    $params, $types
);

$pageTitle = 'Payments';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-credit-card-2-front"></i> Payment History</h1>
  <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#cashPaymentForm" aria-expanded="false" aria-controls="cashPaymentForm">
    <i class="bi bi-cash-coin"></i> Record cash payment
  </button>
</div>

<div class="collapse mb-3" id="cashPaymentForm">
  <div class="card card-body">
    <h2 class="h6">Record tenant cash payment</h2>
    <p class="text-muted small">The selected invoice is paid first. Any remainder is applied to other outstanding invoices oldest-first; excess remains as tenant rent credit.</p>
    <form method="post" class="row g-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="record_cash_payment">
      <div class="col-md-4">
        <label class="form-label" for="cashTenant">Tenant</label>
        <select class="form-select" id="cashTenant" name="tenant_id" required>
          <option value="">Select tenant</option>
          <?php foreach ($tenants as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label" for="cashCategory">Payment type</label>
        <select class="form-select" id="cashCategory" name="category" required>
          <option value="">Select type</option>
          <option value="RENT">Rent</option>
          <option value="CUSTOM">Other charges</option>
        </select>
      </div>
      <div class="col-md-5">
        <label class="form-label" for="cashInvoice">Outstanding invoice</label>
        <select class="form-select" id="cashInvoice" name="invoice_id" required disabled>
          <option value="">Select a tenant and payment type first</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label" for="cashAmount">Amount received</label>
        <input class="form-control" id="cashAmount" name="amount" type="number" min="0.01" step="0.01" required>
      </div>
      <div class="col-12">
        <button class="btn btn-success" type="submit"><i class="bi bi-check-circle"></i> Save cash payment</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="card stat-card p-3"><div class="stat-label">Completed</div><div class="stat-value text-success"><?= e(money_short($totals['completed'])) ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3"><div class="stat-label">Pending</div><div class="stat-value text-secondary"><?= e(money_short($totals['pending'])) ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3"><div class="stat-label">Transactions</div><div class="stat-value"><?= (int)$totals['c'] ?></div></div></div>
</div>
<script>
(() => {
  const tenantSelect = document.getElementById('cashTenant');
  const categorySelect = document.getElementById('cashCategory');
  const invoiceSelect = document.getElementById('cashInvoice');
  const invoices = <?= json_encode($cashInvoices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  function refreshCashInvoices() {
    const tenantId = tenantSelect.value;
    const category = categorySelect.value;
    const matchingInvoices = invoices.filter(invoice =>
      String(invoice.tenant_id) === tenantId && invoice.type === category
    );

    invoiceSelect.replaceChildren();
    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = matchingInvoices.length
      ? 'Select outstanding invoice'
      : 'No outstanding invoices for this selection';
    invoiceSelect.appendChild(placeholder);
    for (const invoice of matchingInvoices) {
      const option = document.createElement('option');
      option.value = invoice.id;
      option.textContent = `${invoice.invoice_number} — balance ${Number(invoice.outstanding).toFixed(2)}`;
      invoiceSelect.appendChild(option);
    }
    invoiceSelect.disabled = matchingInvoices.length === 0;
  }

  tenantSelect.addEventListener('change', refreshCashInvoices);
  categorySelect.addEventListener('change', refreshCashInvoices);
})();
</script>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto"><label class="form-label">Tenant</label>
    <select class="form-select" name="tenant_id">
      <option value="0">All tenants</option>
      <?php foreach($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $filterTenant==(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><label class="form-label">Status</label>
    <select class="form-select" name="status">
      <option value="">All</option>
      <?php foreach(['PENDING','COMPLETED','FAILED','CANCELLED'] as $s): ?><option <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><button class="btn btn-outline-dark">Filter</button></div>
  <?php if ($filterTenant): ?><div class="col-auto"><a class="btn btn-outline-secondary" href="<?= e(base_url('admin/payments.php')) ?>">Clear</a></div><?php endif; ?>
</form>

<div class="card">
  <div class="card-header">Transactions (<?= count($payments) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover table-sm mb-0 align-middle">
      <thead><tr><th>Date</th><th>Tenant</th><th>Invoice</th><th>Method</th><th>Merchant Ref</th><th>Confirmation</th><th class="text-end">Amount</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="small"><?= e(date('d M Y H:i', strtotime($p['transaction_date'] ?: $p['created_at']))) ?></td>
          <td class="small"><?= e($p['tenant_name']) ?> <span class="text-muted">(<?= e($p['house_id'] ?? '—') ?>)</span></td>
          <td class="small"><code><?= e($p['invoice_number'] ?? '—') ?></code></td>
          <td class="small"><?= e($p['payment_method'] ?? '—') ?></td>
          <td class="small"><code><?= e($p['merchant_reference']) ?></code></td>
          <td class="small"><?= e($p['confirmation_code'] ?? '—') ?></td>
          <td class="text-end small"><?= e(money($p['amount'])) ?></td>
          <td><?= status_badge($p['status']) ?></td>
          <td>
            <?php if ($p['status'] !== 'COMPLETED' && $p['order_tracking_id']): ?>
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                <button class="btn btn-sm btn-outline-success" type="submit">Check Pesapal status</button>
              </form>
            <?php elseif ($p['status'] !== 'COMPLETED'): ?>
              <span class="text-muted small">No Pesapal order</span>
            <?php else: ?>
              <a class="btn btn-sm btn-outline-success" href="<?= e(base_url('payment/receipt.php?payment_id=' . (int) $p['id'])) ?>">PDF receipt</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$payments): ?><tr><td colspan="9" class="text-center text-muted py-4">No payments found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
