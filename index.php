<?php
declare(strict_types=1);
require_once __DIR__ . '/config/app.php';

if ($admin = current_admin()) {
    redirect(base_url('admin/dashboard.php'));
}
if ($tenant = current_tenant()) {
    redirect(!empty($tenant['must_change_password'])
        ? base_url('tenant/change_password.php')
        : base_url('tenant/dashboard.php'));
}

$error = '';
$identifier = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';

    if (login_admin($identifier, $password)) {
        redirect(base_url('admin/dashboard.php'));
    }

    $tenant = login_tenant($identifier, $password);
    if ($tenant) {
        redirect(!empty($tenant['must_change_password'])
            ? base_url('tenant/change_password.php')
            : base_url('tenant/dashboard.php'));
    }

    $error = 'Invalid username, phone/email, or password.';
}

$pageTitle = 'Sign In';
$nav = '';
$authPage = true;
require_once __DIR__ . '/partials/header.php';
?>
<div class="auth-wrapper">
  <div class="login-hero">
    <i class="bi bi-buildings"></i>
    <h1 class="h4 mt-2"><?= e(APP_NAME) ?></h1>
    <p class="text-muted small">Sign in to continue to your portal.</p>
  </div>
  <div class="card auth-card">
    <div class="card-body p-4">
      <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label" for="identifier">Username, phone number, or email</label>
          <input id="identifier" class="form-control" name="identifier" required autofocus value="<?= e($identifier) ?>">
        </div>
        <div class="mb-3">
          <label class="form-label" for="password">Password</label>
          <input id="password" type="password" class="form-control" name="password" required>
        </div>
        <button class="btn btn-primary w-100"><i class="bi bi-box-arrow-in-right"></i> Sign in</button>
      </form>
      <?php if (pesapal_is_simulate()): ?>
        <hr>
        <p class="text-muted small mb-0">Payments are in SIMULATE mode. Tenants can test checkout without live credentials.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/partials/footer.php'; ?>
