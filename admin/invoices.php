<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/invoicing.php';
require_admin();

$filterTenant = isset($_GET['tenant_id']) ? (int) $_GET['tenant_id'] : 0;
$filterStatus = $_GET['status'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = $_POST['op'] ?? '';
    if ($op === 'generate') {
        $year = (int) ($_POST['year'] ?? date('Y'));
        $month = (int) ($_POST['month'] ?? date('n'));
        $res = generate_invoices_for_period($year, $month);
        flash_set(
            'success',
            "Generated {$res['created']} invoice(s); {$res['skipped']} skipped. "
            . "Invoice emails sent: {$res['emails_sent']}; failed: {$res['email_failures']}."
        );
        redirect(base_url('admin/invoices.php'));
    }
    if ($op === 'cancel') {
        $iid = (int) ($_POST['id'] ?? 0);
        $inv = get_invoice($iid);
        if ($inv && in_array($inv['status'], ['UNPAID', 'PARTIAL'], true) && (float)$inv['amount_paid'] <= 0) {
            db_exec("UPDATE invoices SET status='CANCELLED' WHERE id=?", [$iid], 'i');
            flash_set('success', 'Invoice cancelled.');
        } else {
            flash_set('error', 'Only unpaid invoices with no payments can be cancelled.');
        }
        redirect(base_url('admin/invoices.php'));
    }
    if ($op === 'create_custom') {
        $tid = (int) ($_POST['tenant_id'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $amount = (float) ($_POST['amount'] ?? 0);
        $due = trim($_POST['due_date'] ?? '') ?: null;
        $tenant = $tid ? get_tenant($tid) : null;
        if (!$tenant) {
            flash_set('error', 'Select a valid tenant.');
        } elseif ($desc === '') {
            flash_set('error', 'A description is required for a custom invoice.');
        } elseif ($amount <= 0) {
            flash_set('error', 'Amount must be greater than zero.');
        } elseif ($due !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
            flash_set('error', 'Invalid due date.');
        } else {
            $number = 'CUS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            try {
                db_insert(
                    'INSERT INTO invoices (tenant_id, invoice_number, type, description, month, year, amount, amount_paid, due_date, status)
                     VALUES (?,?,\'CUSTOM\',?,NULL,NULL,?,0,?,\'UNPAID\')',
                    [$tid, $number, $desc, $amount, $due],
                    'issds'
                );
                flash_set('success', "Custom invoice {$number} created for {$tenant['name']}.");
            } catch (Throwable $ex) {
                flash_set('error', 'Could not create invoice: ' . $ex->getMessage());
            }
        }
        redirect(base_url('admin/invoices.php'));
    }
}

$where = '1=1'; $params = []; $types = '';
if ($filterTenant) { $where .= ' AND i.tenant_id=?'; $params[] = $filterTenant; $types .= 'i'; }
if (in_array($filterStatus, ['UNPAID','PARTIAL','PAID','CANCELLED'], true)) { $where .= ' AND i.status=?'; $params[] = $filterStatus; $types .= 's'; }

$invoices = db_all(
    "SELECT i.*, t.name tenant_name, t.house_id FROM invoices i
       JOIN tenants t ON t.id=i.tenant_id WHERE $where
      ORDER BY i.year DESC, i.month DESC, t.name",
    $params, $types
);
$tenants = db_all('SELECT id,name FROM tenants ORDER BY name');

$pageTitle = 'Invoices';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-receipt"></i> Invoice Management</h1>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-calendar-check"></i> Monthly Rent Invoice Automation</div>
      <div class="card-body">
        <p class="small text-muted">Once the scheduler is installed, invoices are created at 06:00 on the first day of each month for every active tenant with a positive rent amount. Existing invoices for that period are not duplicated.</p>
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="op" value="generate">
          <div class="col-auto"><label class="form-label">Month</label>
            <select class="form-select" name="month">
              <?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= $m==(int)date('n')?'selected':'' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?>
            </select></div>
          <div class="col-auto"><label class="form-label">Year</label>
            <input type="number" class="form-control" name="year" value="<?= (int)date('Y') ?>" style="width:110px"></div>
          <div class="col-auto"><button class="btn btn-dark"><i class="bi bi-lightning-charge"></i> Run now</button></div>
          <div class="col-12"><div class="form-text">Optional manual backfill or retry. The monthly task runs automatically once installed; invoices are created for active tenants regardless of house assignment/status.</div></div>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header"><i class="bi bi-plus-square"></i> Create Custom Invoice</div>
      <div class="card-body">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_field() ?><input type="hidden" name="op" value="create_custom">
          <div class="col-md-5"><label class="form-label">Tenant</label>
            <select class="form-select" name="tenant_id" required>
              <option value="">Select tenant…</option>
              <?php foreach($tenants as $t): ?><option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-7"><label class="form-label">Description</label>
            <input class="form-control" name="description" required placeholder="e.g. Water bill, Security, Repairs" maxlength="200"></div>
          <div class="col-md-5"><label class="form-label">Amount</label>
            <input type="number" step="0.01" min="0.01" class="form-control" name="amount" required></div>
          <div class="col-md-4"><label class="form-label">Due date</label>
            <input type="date" class="form-control" name="due_date"></div>
          <div class="col-md-3"><button class="btn btn-primary w-100"><i class="bi bi-receipt"></i> Create</button></div>
          <div class="col-12"><div class="form-text">Non-rent charges (water, maintenance, penalties). No billing period is attached.</div></div>
        </form>
      </div>
    </div>
  </div>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
  <div class="col-auto"><label class="form-label">Tenant</label>
    <select class="form-select" name="tenant_id">
      <option value="0">All tenants</option>
      <?php foreach($tenants as $t): ?><option value="<?= (int)$t['id'] ?>" <?= $filterTenant==(int)$t['id']?'selected':'' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><label class="form-label">Status</label>
    <select class="form-select" name="status">
      <option value="">All</option>
      <?php foreach(['UNPAID','PARTIAL','PAID','CANCELLED'] as $s): ?><option <?= $filterStatus===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select></div>
  <div class="col-auto"><button class="btn btn-outline-dark">Filter</button></div>
</form>

<div class="card">
  <div class="card-header">Invoices (<?= count($invoices) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Invoice #</th><th>Type</th><th>Tenant</th><th>House</th><th>Period / Description</th><th class="text-end">Amount</th><th class="text-end">Paid</th><th>Due</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($invoices as $i):
        $isCustom = ($i['type'] ?? 'RENT') === 'CUSTOM'; ?>
        <tr>
          <td><code><?= e($i['invoice_number']) ?></code></td>
          <td><?php if ($isCustom): ?><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Custom</span><?php else: ?><span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">Rent</span><?php endif; ?></td>
          <td class="small"><?= e($i['tenant_name']) ?></td>
          <td class="small"><?= e($i['house_id'] ?? '—') ?></td>
          <td class="small"><?= e(invoice_period_label($i)) ?></td>
          <td class="text-end"><?= e(money($i['amount'])) ?></td>
          <td class="text-end"><?= e(money($i['amount_paid'])) ?></td>
          <td class="small"><?= e($i['due_date'] ?? '—') ?></td>
          <td><?= status_badge($i['status']) ?></td>
          <td class="text-end">
            <?php if (in_array($i['status'],['UNPAID','PARTIAL'],true) && (float)$i['amount_paid']<=0): ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Cancel this invoice?')">
                <?= csrf_field() ?><input type="hidden" name="op" value="cancel"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$invoices): ?><tr><td colspan="10" class="text-center text-muted py-4">No invoices found.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
