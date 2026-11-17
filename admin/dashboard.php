<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

// ---- headline stats ----
$totalTenants = (int) db_value("SELECT COUNT(*) FROM tenants");
$totalHouses  = (int) db_value("SELECT COUNT(*) FROM houses");
$totalProps   = (int) db_value("SELECT COUNT(*) FROM properties");
$occupied     = (int) db_value("SELECT COUNT(*) FROM houses WHERE status='OCCUPIED'");
$vacant       = (int) db_value("SELECT COUNT(*) FROM houses WHERE status='VACANT'");
$revenue      = (float) db_value("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='COMPLETED'");
$arrears      = (float) db_value("SELECT COALESCE(SUM(amount - amount_paid),0) FROM invoices WHERE status IN ('UNPAID','PARTIAL')");
$occupancy    = $totalHouses > 0 ? round($occupied / $totalHouses * 100, 1) : 0.0;

// ---- property financial breakdown ----
$propRows = db_all(
    "SELECT p.id, p.name, p.location,
        (SELECT COUNT(*) FROM houses h WHERE h.property_id=p.id) units,
        (SELECT COUNT(*) FROM houses h WHERE h.property_id=p.id AND h.status='OCCUPIED') occupied,
        (SELECT COALESCE(SUM(h.rent_amount),0) FROM houses h WHERE h.property_id=p.id AND h.status='OCCUPIED') expectation,
        (SELECT COALESCE(SUM(py.amount),0) FROM payments py
            JOIN tenants t ON t.id=py.tenant_id JOIN houses h ON h.id=t.house_id
           WHERE h.property_id=p.id AND py.status='COMPLETED') collected,
        (SELECT COALESCE(SUM(i.amount - i.amount_paid),0) FROM invoices i
            JOIN tenants t ON t.id=i.tenant_id JOIN houses h ON h.id=t.house_id
           WHERE h.property_id=p.id AND i.status IN ('UNPAID','PARTIAL')) arrears
       FROM properties p ORDER BY p.name"
);


// ---- revenue trend (last 6 months) ----
$trend = db_all(
    "SELECT DATE_FORMAT(transaction_date,'%Y-%m') ym, SUM(amount) total
       FROM payments WHERE status='COMPLETED' AND transaction_date >= (NOW() - INTERVAL 6 MONTH)
      GROUP BY ym ORDER BY ym"
);
$trendLabels = []; $trendData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $trendLabels[] = date('M Y', strtotime($ym . '-01'));
    $found = 0.0;
    foreach ($trend as $t) { if ($t['ym'] === $ym) { $found = (float) $t['total']; break; } }
    $trendData[] = round($found, 2);
}

// ---- latest payments ----
$latestPayments = db_all(
    "SELECT p.*, t.name tenant_name FROM payments p
       JOIN tenants t ON t.id = p.tenant_id
      ORDER BY p.created_at DESC LIMIT 6"
);

// ---- arrears risk monitor ----
$riskRows = [];
foreach (active_tenants() as $t) {
    $r = tenant_risk((int) $t['id']);
    $riskRows[] = ['tenant' => $t, 'risk' => $r];
}
usort($riskRows, fn($a, $b) => ['HIGH'=>0,'MODERATE'=>1,'LOW'=>2][$b['risk']['level']] <=> ['HIGH'=>0,'MODERATE'=>1,'LOW'=>2][$a['risk']['level']]);
$riskRows = array_slice($riskRows, 0, 8);

$pageTitle = 'Dashboard';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="admin-dashboard-page">
  <header class="admin-dashboard-hero">
    <div>
      <div class="admin-dashboard-eyebrow">Portfolio overview</div>
      <h1>Dashboard</h1>
      <p>Monitor occupancy, payments, and property performance at a glance.</p>
    </div>
    <div class="admin-dashboard-actions">
      <a class="btn btn-outline-light" href="<?= e(base_url('admin/reports.php')) ?>"><i class="bi bi-graph-up"></i> View reports</a>
      <a class="btn btn-light" href="<?= e(base_url('admin/tenants.php')) ?>"><i class="bi bi-people"></i> Manage tenants</a>
    </div>
  </header>

<div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 row-cols-xxl-5 g-3 mb-4 admin-dashboard-stats">
  <?php
  $cards = [
    ['Properties', $totalProps, 'buildings', 'property', $totalHouses . ' registered units'],
    ['Total Tenants', $totalTenants, 'people', 'tenants', 'Registered tenant accounts'],
    ['Occupancy', $occupancy . '%', 'pie-chart', 'occupancy', $occupied . ' occupied · ' . $vacant . ' vacant'],
    ['Collected Revenue', money_short($revenue), 'cash-stack', 'revenue', 'Completed payments'],
    ['Outstanding Arrears', money_short($arrears), 'exclamation-triangle', 'arrears', 'Unpaid invoice balance'],
  ];
  foreach ($cards as [$label, $value, $icon, $color, $note]): ?>
    <div class="col">
      <article class="card admin-dashboard-stat admin-dashboard-stat-<?= e($color) ?> h-100">
        <div class="admin-dashboard-stat-top">
          <span class="admin-dashboard-stat-icon"><i class="bi bi-<?= e($icon) ?>"></i></span>
          <span class="admin-dashboard-stat-label"><?= e($label) ?></span>
        </div>
        <div class="admin-dashboard-stat-value"><?= e((string) $value) ?></div>
        <div class="admin-dashboard-stat-note"><?= e($note) ?></div>
      </article>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($propRows): ?>
<div class="card admin-dashboard-panel mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-buildings"></i> Property Financial Breakdown</span>
    <a class="small" href="<?= e(base_url('admin/properties.php')) ?>">Manage properties &rarr;</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle">
      <thead>
        <tr>
          <th>Property</th>
          <th>Location</th>
          <th class="text-center">Occupancy</th>
          <th class="text-end">Monthly Expectation</th>
          <th class="text-end">Collected</th>
          <th class="text-end">Arrears</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($propRows as $p):
          $units = (int) $p['units']; $occ = (int) $p['occupied'];
          $occPct = $units > 0 ? round($occ / $units * 100) : 0; ?>
        <tr>
          <td><strong><?= e($p['name']) ?></strong></td>
          <td class="small text-muted"><?= e($p['location'] ?: '—') ?></td>
          <td style="min-width:140px">
            <div class="d-flex align-items-center gap-2">
              <div class="progress flex-grow-1" style="height:8px">
                <div class="progress-bar bg-primary" style="width:<?= $occPct ?>%"></div>
              </div>
              <span class="small"><?= $occ ?>/<?= $units ?></span>
            </div>
          </td>
          <td class="text-end small"><?= e(money($p['expectation'])) ?></td>
          <td class="text-end small text-success"><?= e(money($p['collected'])) ?></td>
          <td class="text-end small <?= (float) $p['arrears'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= e(money($p['arrears'])) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/houses.php?property_id=' . (int) $p['id'])) ?>"><i class="bi bi-house-door"></i> Units</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card admin-dashboard-panel">
      <div class="card-header"><i class="bi bi-graph-up"></i> Revenue Trend (last 6 months)</div>
      <div class="card-body"><canvas id="revChart" height="120"></canvas></div>
    </div>

    <div class="card admin-dashboard-panel mt-3">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-credit-card-2-front"></i> Latest Payments</span>
        <a class="small" href="<?= e(base_url('admin/payments.php')) ?>">View all &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
          <thead><tr><th>Date</th><th>Tenant</th><th>Method</th><th class="text-end">Amount</th><th>Status</th></tr></thead>
          <tbody>
          <?php foreach ($latestPayments as $p): ?>
            <tr>
              <td class="small"><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
              <td class="small"><?= e($p['tenant_name']) ?></td>
              <td class="small"><?= e($p['payment_method'] ?? '—') ?></td>
              <td class="text-end small"><?= e(money($p['amount'])) ?></td>
              <td><?= status_badge($p['status']) ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$latestPayments): ?><tr><td colspan="5" class="text-center text-muted py-3">No payments yet.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card admin-dashboard-panel">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-exclamation-triangle"></i> Arrears Risk Monitoring</span>
        <a class="small" href="<?= e(base_url('admin/arrears.php')) ?>">All &rarr;</a>
      </div>
      <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Tenant</th><th class="text-end">Arrears</th><th>Risk</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($riskRows as $row): $t=$row['tenant']; $r=$row['risk']; ?>
            <tr class="row-risk-<?= $r['level'] ?>">
              <td class="small"><span class="risk-dot dot-<?= $r['level'] ?>"></span><?= e($t['name']) ?></td>
              <td class="text-end small"><?= e(money_short($r['arrears'])) ?></td>
              <td><?= risk_badge($r['level']) ?></td>
              <td class="text-end">
                <a class="btn btn-sm btn-outline-<?= risk_color($r['level']) ?>"
                   href="<?= e(base_url('admin/payments.php?tenant_id=' . $t['id'])) ?>">
                   <?= e($r['action_label']) ?></a>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$riskRows): ?><tr><td colspan="4" class="text-center text-muted py-3">No active tenants.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card admin-dashboard-panel mt-3">
      <div class="card-header"><i class="bi bi-pie-chart"></i> Occupancy</div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-1"><span>Occupancy rate</span><strong><?= e($occupancy) ?>%</strong></div>
        <div class="progress" style="height:18px">
          <div class="progress-bar bg-primary" style="width:<?= e($occupancy) ?>%"><?= e($occupied) ?> occupied</div>
        </div>
        <div class="text-muted small mt-2"><?= e($vacant) ?> house(es) vacant</div>
      </div>
    </div>
  </div>
</div>
</div>

<?php
$pageScripts = '<script>
new Chart(document.getElementById("revChart"), {
  type: "line",
  data: { labels: ' . json_encode($trendLabels) . ',
    datasets: [{ label: "Revenue", data: ' . json_encode($trendData) . ',
      borderColor: "#198754", backgroundColor: "rgba(25,135,84,.12)", fill: true, tension: .3 }] },
  options: { plugins: { legend: { display: false } },
    scales: { y: { beginAtZero: true, ticks: { callback: v => "' . DEFAULT_CURRENCY . ' " + v.toLocaleString() } } } }
});
</script>';
require_once __DIR__ . '/../partials/footer.php';
?>
