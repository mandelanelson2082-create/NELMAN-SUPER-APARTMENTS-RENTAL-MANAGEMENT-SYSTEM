<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';

$admin = require_admin();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $fresh = db_one('SELECT id, password FROM admins WHERE id = ?', [(int) $admin['id']], 'i');

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
            'UPDATE admins SET password = ? WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $fresh['id']],
            'si'
        );
        if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
            session_regenerate_id(true);
        }
        flash_set('success', 'Password updated successfully.');
        redirect(base_url('admin/dashboard.php'));
    }
}

$pageTitle = 'Change Password';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="row justify-content-center">
  <div class="col-md-7 col-lg-5">
    <div class="card">
      <div class="card-header"><i class="bi bi-shield-lock"></i> Change Admin Password</div>
      <div class="card-body">
        <p class="text-muted small">If you suspect your password has been compromised, change it now. Use a new password you do not use elsewhere.</p>
        <?php if ($error): ?><div class="alert alert-danger py-2"><?= e($error) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
          <?= csrf_field() ?>
          <div class="mb-3">
            <label class="form-label" for="current-password">Current password</label>
            <input id="current-password" type="password" class="form-control" name="current_password" required autofocus autocomplete="current-password">
          </div>
          <div class="mb-3">
            <label class="form-label" for="new-password">New password</label>
            <input id="new-password" type="password" class="form-control" name="new_password" required minlength="8" autocomplete="new-password">
            <div class="form-text">Use at least 8 characters.</div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="confirm-password">Confirm new password</label>
            <input id="confirm-password" type="password" class="form-control" name="confirm_password" required minlength="8" autocomplete="new-password">
          </div>
          <button class="btn btn-primary w-100"><i class="bi bi-check2-circle"></i> Update Password</button>
        </form>
        <hr>
        <a class="small" href="<?= e(base_url('admin/dashboard.php')) ?>">&larr; Back to dashboard</a>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
