<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();
$rental = db_one(
    'SELECT h.id AS house_id, h.status AS house_status,
            COALESCE(p.name, h.property_name) AS property_name, p.location AS property_location
       FROM tenants t
       LEFT JOIN houses h ON h.id = t.house_id
       LEFT JOIN properties p ON p.id = h.property_id
      WHERE t.id = ?',
    [(int) $tenant['id']],
    'i'
);

$pageTitle = 'Rental Information';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="tenant-rental-page">
  <div class="tenant-rental-page-heading">
    <div>
      <div class="tenant-dashboard-eyebrow">Your tenancy</div>
      <h1 class="h3 mb-1">Rental Information</h1>
      <p class="text-muted mb-0">View your assigned property, unit, and rent details.</p>
    </div>
    <a class="btn btn-outline-secondary" href="<?= e(base_url('tenant/dashboard.php')) ?>"><i class="bi bi-arrow-left"></i> Dashboard</a>
  </div>

  <?php if (!$rental || empty($rental['house_id'])): ?>
    <div class="alert alert-info">
      <i class="bi bi-info-circle"></i> No rental unit is currently assigned to your account. Please contact your property manager for assistance.
    </div>
  <?php else: ?>
    <section class="card tenant-rental-details">
      <div class="card-header"><i class="bi bi-house-door"></i> Your assigned rental</div>
      <div class="row g-0">
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Property</div>
          <div class="tenant-rental-value"><?= e($rental['property_name'] ?: 'Not specified') ?></div>
        </div>
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Location</div>
          <div class="tenant-rental-value"><?= e($rental['property_location'] ?: 'Not specified') ?></div>
        </div>
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Unit / house</div>
          <div class="tenant-rental-value"><?= e($rental['house_id']) ?></div>
        </div>
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Agreed monthly rent</div>
          <div class="tenant-rental-value"><?= e(money($tenant['rent_amount'])) ?></div>
        </div>
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Unit status</div>
          <div class="tenant-rental-value"><?= status_badge($rental['house_status']) ?></div>
        </div>
        <div class="col-md-6 tenant-rental-detail">
          <div class="tenant-rental-label">Tenancy status</div>
          <div class="tenant-rental-value"><?= status_badge($tenant['status']) ?></div>
        </div>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
