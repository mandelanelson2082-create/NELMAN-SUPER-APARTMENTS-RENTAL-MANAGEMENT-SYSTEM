<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

$rows = [];
foreach (active_tenants() as $t) {
    $r = tenant_risk((int) $t['id']);
    $rows[] = ['tenant' => $t, 'risk' => $r];
}
$order = ['HIGH' => 0, 'MODERATE' => 1, 'LOW' => 2];
usort($rows, function ($a, $b) use ($order) {
    return ($order[$a['risk']['level']] <=> $order[$b['risk']['level']])
        ?: ($b['risk']['arrears'] <=> $a['risk']['arrears']);
});

$counts = ['HIGH' => 0, 'MODERATE' => 0, 'LOW' => 0];
foreach ($rows as $r) { $counts[$r['risk']['level']]++; }

$pageTitle = 'Arrears Risk';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<h1 class="h4 mb-1"><i class="bi bi-exclamation-triangle"></i> Arrears Risk Monitoring</h1>
<p class="text-muted small mb-3">
  Transparent rule-based indicator (not a machine-learning model). It supports administrative review
  and never takes automatic action on a tenant.
</p>

<div class="row g-3 mb-4">
  <div class="col-md-4"><div class="card stat-card p-3 border-start border-4 border-danger"><div class="stat-label"><span class="risk-dot dot-HIGH"></span>High Risk</div><div class="stat-value text-danger"><?= $counts['HIGH'] ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3 border-start border-4 border-warning"><div class="stat-label"><span class="risk-dot dot-MODERATE"></span>Moderate Risk</div><div class="stat-value text-warning"><?= $counts['MODERATE'] ?></div></div></div>
  <div class="col-md-4"><div class="card stat-card p-3 border-start border-4 border-success"><div class="stat-label"><span class="risk-dot dot-LOW"></span>Low Risk</div><div class="stat-value text-success"><?= $counts['LOW'] ?></div></div></div>
</div>

<div class="card">
  <div class="card-header">ARREARS RISK MONITORING</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Tenant</th><th>House</th><th class="text-end">Monthly Rent</th><th class="text-end">Arrears</th><th>Unpaid/Partial</th><th>Failed (6m)</th><th>Risk</th><th class="text-end">Action</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $row): $t=$row['tenant']; $r=$row['risk']; ?>
        <tr class="row-risk-<?= $r['level'] ?>">
          <td><strong><?= e($t['name']) ?></strong><br>
            <span class="text-muted small"><?= implode('; ', $r['reasons']) ?: 'No risk triggers' ?></span></td>
          <td><code><?= e($t['house_id'] ?? '—') ?></code></td>
          <td class="text-end"><?= e(money($r['rent'])) ?></td>
          <td class="text-end fw-bold <?= $r['arrears']>0?'text-danger':'' ?>"><?= e(money($r['arrears'])) ?></td>
          <td class="text-center"><?= (int)$r['outstanding_invoices'] ?></td>
          <td class="text-center"><?= (int)$r['failed'] ?></td>
          <td><?= risk_badge($r['level']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-<?= risk_color($r['level']) ?>" href="<?= e(base_url('admin/payments.php?tenant_id='.$t['id'])) ?>">
              <i class="bi bi-search"></i> <?= e($r['action_label']) ?></a>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if(!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">No active tenants.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
