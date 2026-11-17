<?php
$here = basename($_SERVER['SCRIPT_NAME']);
$adminLinks = [
  ['dashboard.php', 'Dashboard', 'speedometer2'],
  ['properties.php', 'Properties', 'buildings'],
  ['houses.php', 'Houses', 'house-door'],
  ['tenants.php', 'Tenants', 'people'],
  ['invoices.php', 'Invoices', 'receipt'],
  ['payments.php', 'Payments', 'credit-card-2-front'],
  ['arrears.php', 'Arrears Risk', 'exclamation-triangle'],
  ['reports.php', 'Reports', 'graph-up'],
  ['pesapal_config.php', 'Pesapal', 'gear-wide-connected'],
  ['email_config.php', 'Email', 'envelope-gear'],
];
?>
<aside class="offcanvas-md offcanvas-start admin-sidebar" tabindex="-1" id="adminSidebar" aria-label="Admin navigation">
  <div class="admin-sidebar-header">
    <a class="navbar-brand fw-bold" href="<?= e(base_url('admin/dashboard.php')) ?>" data-admin-link>
      <span class="admin-brand-icon"><i class="bi bi-buildings"></i></span>
      <span class="admin-brand-copy"><span class="admin-brand-name"><?= e(APP_NAME) ?></span><span class="admin-brand-caption">PROPERTY MANAGEMENT</span></span>
    </a>
    <button type="button" class="btn-close btn-close-white d-md-none" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close menu"></button>
  </div>
  <div class="admin-nav-caption">Main menu</div>
  <nav class="nav nav-pills flex-column admin-sidebar-nav">
    <?php foreach ($adminLinks as [$file, $label, $icon]): ?>
      <a class="nav-link <?= $here === $file ? 'active' : '' ?>" href="<?= e(base_url('admin/' . $file)) ?>" data-admin-link data-admin-page="<?= e($file) ?>" <?= $here === $file ? 'aria-current="page"' : '' ?>>
        <i class="bi bi-<?= e($icon) ?>"></i> <?= e($label) ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="admin-sidebar-account">
    <div class="admin-sidebar-account-profile">
      <span class="admin-sidebar-account-avatar"><i class="bi bi-person"></i></span>
      <div class="admin-sidebar-account-details">
        <div class="admin-sidebar-account-label">Signed in as</div>
        <div class="admin-sidebar-account-name"><?= e($admin['name']) ?></div>
        <span class="badge admin-sidebar-role"><?= e($admin['role']) ?></span>
      </div>
    </div>
    <a class="nav-link <?= $here === 'change_password.php' ? 'active' : '' ?>" href="<?= e(base_url('admin/change_password.php')) ?>" <?= $here === 'change_password.php' ? 'aria-current="page"' : '' ?>>
      <i class="bi bi-shield-lock"></i> Change Password
    </a>
    <a class="nav-link" href="<?= e(base_url('admin/logout.php')) ?>"><i class="bi bi-box-arrow-right"></i> Logout</a>
  </div>
</aside>
