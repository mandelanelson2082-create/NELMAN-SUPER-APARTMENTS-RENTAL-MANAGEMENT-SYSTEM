<?php
$here = basename($_SERVER['SCRIPT_NAME']);
$tenantLinks = [
    ['dashboard.php', 'Dashboard', 'speedometer2'],
    ['invoices.php', 'My Invoices', 'receipt'],
    ['rental_information.php', 'Rental Information', 'house'],
    ['pay.php', 'Pay Rent', 'credit-card'],
    ['history.php', 'Payment History', 'clock-history'],
    ['change_password.php', 'Change Password', 'shield-lock'],
];
?>
<aside class="offcanvas-md offcanvas-start tenant-sidebar" tabindex="-1" id="tenantSidebar" aria-label="Tenant navigation">
  <div class="tenant-sidebar-header">
    <a class="tenant-brand" href="<?= e(base_url('tenant/dashboard.php')) ?>" aria-label="My Rental dashboard">
      <span class="tenant-brand-icon"><i class="bi bi-house-check"></i></span>
      <span class="tenant-brand-copy"><strong>My Rental</strong><small>TENANT PORTAL</small></span>
    </a>
    <button type="button" class="btn-close btn-close-white d-md-none" data-bs-dismiss="offcanvas" data-bs-target="#tenantSidebar" aria-label="Close menu"></button>
  </div>
  <div class="tenant-nav-caption">MENU</div>
  <nav class="nav flex-column tenant-sidebar-nav">
    <?php foreach ($tenantLinks as [$file, $label, $icon]): ?>
      <a class="nav-link <?= $here === $file ? 'active' : '' ?>" href="<?= e(base_url('tenant/' . $file)) ?>" <?= $here === $file ? 'aria-current="page"' : '' ?> title="<?= e($label) ?>">
        <i class="bi bi-<?= e($icon) ?>"></i><span class="tenant-sidebar-label"><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="tenant-sidebar-account">
    <div class="tenant-account-name"><i class="bi bi-person-circle"></i><span class="tenant-sidebar-label"><?= e($tenant['name']) ?></span></div>
    <a class="nav-link" href="<?= e(base_url('tenant/logout.php')) ?>" title="Sign out"><i class="bi bi-box-arrow-right"></i><span class="tenant-sidebar-label">Sign out</span></a>
  </div>
</aside>
