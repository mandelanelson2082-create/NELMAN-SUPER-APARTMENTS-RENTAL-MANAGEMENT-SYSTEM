<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();
$tid = (int) $tenant['id'];

$invoices = db_all(
    'SELECT * FROM invoices WHERE tenant_id = ? ORDER BY year DESC, month DESC LIMIT 24',
    [$tid], 'i'
);
$payments = db_all(
    'SELECT * FROM payments WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 8',
    [$tid], 'i'
);

$stats = tenant_stats($tid);
$arrears = $stats['arrears'];
$rent = (float) $tenant['rent_amount'];
$currentPeriodDue = (float) db_value(
    "SELECT COALESCE(SUM(GREATEST(amount - amount_paid, 0)), 0) FROM invoices
     WHERE tenant_id = ? AND type = 'RENT'
       AND month = MONTH(CURRENT_DATE()) AND year = YEAR(CURRENT_DATE())
       AND status IN ('UNPAID', 'PARTIAL')",
    [$tid],
    'i'
);
$overdueInvoices = (int) db_value(
    "SELECT COUNT(*) FROM invoices
     WHERE tenant_id = ? AND status IN ('UNPAID', 'PARTIAL') AND due_date < CURRENT_DATE()",
    [$tid],
    'i'
);
$paymentStatus = $overdueInvoices > 0 ? 'OVERDUE' : ($arrears > 0 ? 'PAYMENT DUE' : 'UP TO DATE');
$paymentStatusClass = $overdueInvoices > 0 ? 'overdue' : ($arrears > 0 ? 'due' : 'current');

$pageTitle = 'Dashboard';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="tenant-dashboard-page">
  <section class="tenant-dashboard-intro">
    <div>
      <div class="tenant-dashboard-eyebrow">Tenant overview</div>
      <h1>Welcome back, <?= e($tenant['name']) ?></h1>
      <p>Your rent and payment overview at a glance.</p>
    </div>
    <a class="btn btn-success tenant-dashboard-pay-button" href="<?= e(base_url('tenant/pay.php')) ?>">
      Make a payment <i class="bi bi-arrow-right"></i>
    </a>
  </section>

  <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3 mb-4 tenant-dashboard-stats">
    <div class="col">
      <article class="card tenant-dashboard-stat tenant-dashboard-stat-rent h-100">
        <div class="tenant-dashboard-stat-top">
          <span class="tenant-dashboard-stat-icon"><i class="bi bi-currency-dollar"></i></span>
          <span class="tenant-dashboard-stat-caption">Per month</span>
        </div>
        <h2>Monthly rent</h2>
        <div class="tenant-dashboard-stat-value"><?= e(money($rent)) ?></div>
        <p>Your agreed monthly rent</p>
      </article>
    </div>
    <div class="col">
      <article class="card tenant-dashboard-stat tenant-dashboard-stat-due h-100">
        <div class="tenant-dashboard-stat-top">
          <span class="tenant-dashboard-stat-icon"><i class="bi bi-clock"></i></span>
          <span class="tenant-dashboard-stat-caption">Current period</span>
        </div>
        <h2>Rent due</h2>
        <div class="tenant-dashboard-stat-value"><?= e(money($currentPeriodDue)) ?></div>
        <p>Amount currently due</p>
      </article>
    </div>
    <div class="col">
      <article class="card tenant-dashboard-stat tenant-dashboard-stat-status h-100">
        <div class="tenant-dashboard-stat-top">
          <span class="tenant-dashboard-stat-icon"><i class="bi bi-shield-check"></i></span>
          <span class="tenant-dashboard-stat-caption">Payment health</span>
        </div>
        <h2>Payment status</h2>
        <div><span class="tenant-payment-status tenant-payment-status-<?= e($paymentStatusClass) ?>"><?= e($paymentStatus) ?></span></div>
        <p>Based on your current invoices</p>
      </article>
    </div>
    <div class="col">
      <article class="card tenant-dashboard-stat tenant-dashboard-stat-balance h-100">
        <div class="tenant-dashboard-stat-top">
          <span class="tenant-dashboard-stat-icon"><i class="bi bi-credit-card"></i></span>
          <span class="tenant-dashboard-stat-caption">Total balance</span>
        </div>
        <h2>Outstanding balance</h2>
        <div class="tenant-dashboard-stat-value"><?= e(money($arrears)) ?></div>
        <p>Remaining unpaid amount</p>
      </article>
    </div>
  </div>

  <section class="card tenant-quick-access mb-4">
    <div class="tenant-quick-access-heading">
      <div class="tenant-dashboard-eyebrow">Quick access</div>
      <h2>Tenant services</h2>
      <p>Pick up where you need to.</p>
    </div>
    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-4 g-3">
      <div class="col">
        <a class="tenant-service-link tenant-service-link-primary" href="<?= e(base_url('tenant/pay.php')) ?>">
          <span class="tenant-service-icon"><i class="bi bi-credit-card"></i></span>
          <span><strong>Make a payment</strong><small>Pay rent securely</small></span>
          <i class="bi bi-arrow-right tenant-service-arrow"></i>
        </a>
      </div>
      <div class="col">
        <a class="tenant-service-link" href="<?= e(base_url('tenant/invoices.php')) ?>">
          <span class="tenant-service-icon"><i class="bi bi-receipt"></i></span>
          <span><strong>View invoices</strong><small>Check charges and balances</small></span>
          <i class="bi bi-arrow-right tenant-service-arrow"></i>
        </a>
      </div>
      <div class="col">
        <a class="tenant-service-link" href="<?= e(base_url('tenant/history.php')) ?>">
          <span class="tenant-service-icon"><i class="bi bi-clock-history"></i></span>
          <span><strong>Payment history</strong><small>Review your payments</small></span>
          <i class="bi bi-arrow-right tenant-service-arrow"></i>
        </a>
      </div>
      <div class="col">
        <a class="tenant-service-link" href="<?= e(base_url('tenant/rental_information.php')) ?>">
          <span class="tenant-service-icon"><i class="bi bi-house"></i></span>
          <span><strong>Rental information</strong><small>View your rental details</small></span>
          <i class="bi bi-arrow-right tenant-service-arrow"></i>
        </a>
      </div>
    </div>
  </section>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt"></i> My Invoices</span>
        <a class="small" href="<?= e(base_url('tenant/invoices.php')) ?>">View all &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
          <thead><tr><th>Invoice</th><th>Period</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php if (!$invoices): ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No invoices yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($invoices as $inv):
            $due = round((float)$inv['amount'] - (float)$inv['amount_paid'], 2);
            $isCustom = ($inv['type'] ?? 'RENT') === 'CUSTOM';
          ?>
            <tr>
              <td><code><?= e($inv['invoice_number']) ?></code></td>
              <td><?= e(invoice_period_label($inv)) ?></td>
              <td class="text-end"><?= e(money($inv['amount'])) ?></td>
              <td class="text-end"><?= e(money($inv['amount_paid'])) ?></td>
              <td><?= status_badge($inv['status']) ?></td>
              <td class="text-end">
                <?php if (in_array($inv['status'], ['UNPAID','PARTIAL'], true) && $due > 0): ?>
                  <a class="btn btn-sm btn-success" href="<?= e(base_url('tenant/pay.php?invoice_id=' . $inv['id'])) ?>">
                    <i class="bi bi-credit-card"></i> <?= $isCustom ? 'Pay' : 'Pay Rent' ?>
                  </a>
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
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-clock-history"></i> Recent Payments</div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Date</th><th class="text-end">Amount</th><th>Method</th><th>Status</th></tr></thead>
          <tbody>
          <?php if (!$payments): ?>
            <tr><td colspan="4" class="text-center text-muted py-4">No payments yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($payments as $p): ?>
            <tr>
              <td class="small"><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
              <td class="text-end small"><?= e(money($p['amount'])) ?></td>
              <td class="small"><?= e($p['payment_method'] ?? '—') ?></td>
              <td><?= status_badge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer bg-white text-end">
        <a class="small" href="<?= e(base_url('tenant/history.php')) ?>">View full history &rarr;</a>
      </div>
    </div>
  </div>
</div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
