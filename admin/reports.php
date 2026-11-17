<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

$tab = $_GET['tab'] ?? 'revenue';

// ---- Revenue by month (current year) ----
$year = (int) date('Y');
$revenueRows = db_all(
    "SELECT MONTH(transaction_date) m, SUM(amount) total
       FROM payments WHERE status='COMPLETED' AND YEAR(transaction_date)=?
      GROUP BY m ORDER BY m", [$year], 'i');
$revByMonth = array_fill(1, 12, 0.0);
foreach ($revenueRows as $r) { $revByMonth[(int)$r['m']] = round((float)$r['total'], 2); }

// ---- Occupancy ----
$totalHouses = (int) db_value("SELECT COUNT(*) FROM houses");
$occupied = (int) db_value("SELECT COUNT(*) FROM houses WHERE status='OCCUPIED'");
$vacant = $totalHouses - $occupied;
$occRate = $totalHouses ? round($occupied/$totalHouses*100,1) : 0.0;

// ---- Arrears by tenant ----
$arrearsRows = [];
foreach (active_tenants() as $t) {
    $s = tenant_stats((int)$t['id']);
    if ($s['arrears'] > 0) {
        $arrearsRows[] = ['name'=>$t['name'],'house'=>$t['house_id'],'arrears'=>$s['arrears']];
    }
}
usort($arrearsRows, fn($a,$b)=>$b['arrears']<=>$a['arrears']);
$totalArrears = array_sum(array_column($arrearsRows,'arrears'));

// ---- Payments report ----
$payments = db_all(
    "SELECT p.*, t.name tenant_name, i.invoice_number
       FROM payments p JOIN tenants t ON t.id=p.tenant_id
       LEFT JOIN invoices i ON i.id=p.invoice_id
      ORDER BY p.created_at DESC LIMIT 200");

$nav = 'admin';
$pageTitle = 'Reports';
require_once __DIR__ . '/../partials/header.php';
?>
<h1 class="h4 mb-3"><i class="bi bi-graph-up"></i> Management Reports</h1>

<ul class="nav nav-tabs mb-3">
  <?php $tabs = ['revenue'=>'Revenue','arrears'=>'Arrears','occupancy'=>'Occupancy','payments'=>'Payments'];
  foreach($tabs as $k=>$label): ?>
    <li class="nav-item"><a class="nav-link <?= $tab===$k?'active':'' ?>" href="<?= e(base_url('admin/reports.php?tab='.$k)) ?>"><?= e($label) ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab==='revenue'): ?>
  <div class="card">
    <div class="card-header">Revenue Report — <?= $year ?></div>
    <div class="card-body"><canvas id="revBar" height="90"></canvas></div>
    <div class="table-responsive">
      <table class="table table-sm mb-0">
        <thead><tr><th>Month</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
        <?php for($m=1;$m<=12;$m++): ?>
          <tr><td><?= date('F', mktime(0,0,0,$m,1)) ?></td><td class="text-end"><?= e(money($revByMonth[$m])) ?></td></tr>
        <?php endfor; ?>
        <tr class="table-light fw-bold"><td>Total</td><td class="text-end"><?= e(money(array_sum($revByMonth))) ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab==='arrears'): ?>
  <div class="card">
    <div class="card-header">Arrears Report — Total outstanding: <?= e(money($totalArrears)) ?></div>
    <div class="table-responsive">
      <table class="table table-hover mb-0 align-middle">
        <thead><tr><th>Tenant</th><th>House</th><th class="text-end">Arrears</th></tr></thead>
        <tbody>
        <?php foreach($arrearsRows as $a): ?>
          <tr><td><?= e($a['name']) ?></td><td><code><?= e($a['house'] ?? '—') ?></code></td><td class="text-end text-danger"><?= e(money($a['arrears'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if(!$arrearsRows): ?><tr><td colspan="3" class="text-center text-muted py-4">No outstanding arrears. 🎉</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab==='occupancy'): ?>
  <div class="card">
    <div class="card-header">Occupancy Report</div>
    <div class="card-body">
      <div class="row text-center g-3">
        <div class="col-6 col-md-3"><div class="stat-label">Total Houses</div><div class="stat-value"><?= $totalHouses ?></div></div>
        <div class="col-6 col-md-3"><div class="stat-label">Occupied</div><div class="stat-value text-primary"><?= $occupied ?></div></div>
        <div class="col-6 col-md-3"><div class="stat-label">Vacant</div><div class="stat-value text-success"><?= $vacant ?></div></div>
        <div class="col-6 col-md-3"><div class="stat-label">Occupancy Rate</div><div class="stat-value"><?= e($occRate) ?>%</div></div>
      </div>
      <div class="progress mt-4" style="height:24px">
        <div class="progress-bar bg-primary" style="width:<?= e($occRate) ?>%">Occupied <?= e($occRate) ?>%</div>
      </div>
    </div>
  </div>

<?php else: ?>
  <div class="card">
    <div class="card-header d-flex justify-content-between">
      <span>Payment Report</span>
      <button class="btn btn-sm btn-outline-secondary no-print" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle">
        <thead><tr><th>Date</th><th>Tenant</th><th>Invoice</th><th>Method</th><th>Confirmation</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($payments as $p): ?>
          <tr>
            <td class="small"><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
            <td class="small"><?= e($p['tenant_name']) ?></td>
            <td class="small"><code><?= e($p['invoice_number'] ?? '—') ?></code></td>
            <td class="small"><?= e($p['payment_method'] ?? '—') ?></td>
            <td class="small"><?= e($p['confirmation_code'] ?? '—') ?></td>
            <td class="text-end small"><?= e(money($p['amount'])) ?></td>
            <td><?= status_badge($p['status']) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payments.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php
if ($tab==='revenue') {
  $labels = []; $data = [];
  for($m=1;$m<=12;$m++){ $labels[]=date('M',mktime(0,0,0,$m,1)); $data[]=$revByMonth[$m]; }
  $pageScripts = '<script>new Chart(document.getElementById("revBar"),{type:"bar",data:{labels:'.json_encode($labels).',datasets:[{label:"Revenue",data:'.json_encode($data).',backgroundColor:"#0d6efd"}]},options:{plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});</script>';
}
require_once __DIR__ . '/../partials/footer.php';
?>
