<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$tenant = require_tenant();

$error = '';
$mustChange = !empty($tenant['must_change_password']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // A fresh tenant row so the guard reflects the latest flag state.
    $fresh = get_tenant((int) $tenant['id']);

    if (!$fresh || !password_verify($current, $fresh['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $error = 'New password and confirmation do not match.';
    } elseif (password_verify($new, $fresh['password'])) {
        $error = 'New password must be different from the current one.';
    } else {
        db_exec(
            'UPDATE tenants SET password = ?, must_change_password = 0 WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $fresh['id']],
            'si'
        );
        flash_set('success', 'Password updated successfully.');
        redirect(base_url('tenant/dashboard.php'));
    }
}

$pageTitle = 'Change Password';
$nav = 'tenant';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-shield-lock"></i> Change Password</div>
      <div class="card-body">
        <?php if ($mustChange): ?>
          <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle"></i>
            You are signing in with an initial password set by the administrator.
            For your security, please choose a new password to continue.
          </div>
        <?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label">Current password</label>
            <input type="password" class="form-control" name="current_password" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">New password</label>
            <input type="password" class="form-control" name="new_password" required minlength="8">
            <div class="form-text">At least 8 characters.</div>
          </div>
          <div class="mb-3">
            <label class="form-label">Confirm new password</label>
            <input type="password" class="form-control" name="confirm_password" required minlength="8">
          </div>
          <button class="btn btn-primary w-100"><i class="bi bi-check2-circle"></i> Update Password</button>
        </form>
        <?php if (!$mustChange): ?>
          <hr>
          <a class="small" href="<?= e(base_url('tenant/dashboard.php')) ?>">&larr; Back to dashboard</a>
        <?php else: ?>
          <hr>
          <a class="small" href="<?= e(base_url('tenant/logout.php')) ?>">Sign out</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
