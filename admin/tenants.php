<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/invoicing.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

$action = $_GET['action'] ?? 'list';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '') ?: null;
        $password = $_POST['password'] ?? '';
        $houseId = trim($_POST['house_id'] ?? '');
        $rent = (float) ($_POST['rent_amount'] ?? 0);
        $mustChange = isset($_POST['must_change_password']) ? 1 : 0;

        if ($name === '' || $phone === '' || strlen($password) < 6) {
            flash_set('error', 'Name, phone and a password (min 6 chars) are required.');
            redirect(base_url('admin/tenants.php?action=add'));
        }
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Enter a valid email address or leave it blank.');
            redirect(base_url('admin/tenants.php?action=add'));
        }
        if (db_value('SELECT COUNT(*) FROM tenants WHERE phone=?', [$phone], 's')) {
            flash_set('error', 'A tenant with that phone already exists.');
            redirect(base_url('admin/tenants.php?action=add'));
        }
        if ($email !== null && db_value('SELECT COUNT(*) FROM tenants WHERE email=?', [$email], 's')) {
            flash_set('error', 'A tenant with that email already exists.');
            redirect(base_url('admin/tenants.php?action=add'));
        }

        $activationToken = $email === null ? null : bin2hex(random_bytes(32));
        $activationHash = $activationToken === null ? null : hash('sha256', $activationToken);
        $activationExpires = $activationToken === null ? null : date('Y-m-d H:i:s', time() + 48 * 60 * 60);

        // House allocation with availability check (proposal 3.13).
        if ($houseId !== '') {
            $house = get_house($houseId);
            if (!$house || $house['status'] !== 'VACANT') {
                flash_set('error', 'Selected house is not available.');
                redirect(base_url('admin/tenants.php?action=add'));
            }
            if ($rent <= 0) { $rent = (float) $house['rent_amount']; }
            db()->begin_transaction();
            try {
                $tid = db_insert(
                    'INSERT INTO tenants (name,phone,email,password,house_id,rent_amount,status,must_change_password,activation_token_hash,activation_token_expires_at)
                     VALUES (?,?,?,?,?,?,?, ?,?,?)',
                    [$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT), $houseId, $rent, 'ACTIVE', $mustChange, $activationHash, $activationExpires],
                    'sssssdsi' . 'ss'
                );
                $updated = db_exec("UPDATE houses SET status='OCCUPIED' WHERE id=? AND status='VACANT'", [$houseId], 's');
                if ($updated === 0) {
                    throw new RuntimeException('House became unavailable.');
                }
                db()->commit();
                $invoiceCreated = generate_invoice_for_tenant($tid, (int) date('Y'), (int) date('n'));
                flash_set('success', "Tenant $name created and allocated to $houseId." . ($invoiceCreated ? ' Current month rent invoice created.' : ''));
                if ($activationToken !== null) {
                    try {
                        send_tenant_activation_email(['name' => $name, 'email' => $email], $activationToken);
                        flash_set('success', 'An account activation link was emailed to the tenant. It expires in 48 hours.');
                    } catch (MailDeliveryException $ex) {
                        error_log('Tenant activation email failed for tenant ' . $tid . ': ' . $ex->getMessage());
                        flash_set('warning', 'Tenant account was created but the activation email could not be sent. Check email configuration and use Resend activation.');
                    }
                } else {
                    flash_set('warning', 'No email was provided. This tenant can sign in without email verification.');
                }
            } catch (Throwable $ex) {
                db()->rollback();
                flash_set('error', 'Allocation failed: ' . $ex->getMessage());
            }
            redirect(base_url('admin/tenants.php'));
        }

        // Tenant without a house.
        $tid = db_insert(
            'INSERT INTO tenants (name,phone,email,password,house_id,rent_amount,status,must_change_password,activation_token_hash,activation_token_expires_at)
             VALUES (?,?,?,?,?,?,?, ?,?,?)',
            [$name, $phone, $email, password_hash($password, PASSWORD_DEFAULT), null, $rent, 'ACTIVE', $mustChange, $activationHash, $activationExpires],
            'sssssdsi' . 'ss'
        );
        flash_set('success', "Tenant $name created (no house assigned).");
        if ($activationToken !== null) {
            try {
                send_tenant_activation_email(['name' => $name, 'email' => $email], $activationToken);
                flash_set('success', 'An account activation link was emailed to the tenant. It expires in 48 hours.');
            } catch (MailDeliveryException $ex) {
                error_log('Tenant activation email failed for tenant ' . $tid . ': ' . $ex->getMessage());
                flash_set('warning', 'Tenant account was created but the activation email could not be sent. Check email configuration and use Resend activation.');
            }
        } else {
            flash_set('warning', 'No email was provided. This tenant can sign in without email verification.');
        }
        redirect(base_url('admin/tenants.php'));
    }

    if ($op === 'resend_activation') {
        $tid = (int) ($_POST['id'] ?? 0);
        $tenant = $tid > 0 ? get_tenant($tid) : null;
        if (!$tenant || empty($tenant['email'])) {
            flash_set('error', 'A tenant with an email address is required to send an activation link.');
        } elseif (!empty($tenant['email_verified_at'])) {
            flash_set('error', 'This tenant email has already been verified.');
        } else {
            $activationToken = bin2hex(random_bytes(32));
            db_exec(
                'UPDATE tenants SET activation_token_hash=?, activation_token_expires_at=? WHERE id=?',
                [hash('sha256', $activationToken), date('Y-m-d H:i:s', time() + 48 * 60 * 60), $tid],
                'ssi'
            );
            try {
                send_tenant_activation_email($tenant, $activationToken);
                flash_set('success', 'A new activation link was sent. Previous links are no longer valid.');
            } catch (MailDeliveryException $ex) {
                error_log('Tenant activation email retry failed for tenant ' . $tid . ': ' . $ex->getMessage());
                flash_set('error', 'Could not send activation email. Check SMTP configuration and try again.');
            }
        }
        redirect(base_url('admin/tenants.php'));
    }

    if ($op === 'reset_password') {
        $tid = (int) ($_POST['id'] ?? 0);
        $t = get_tenant($tid);
        if ($t) {
            // Temporary password = the tenant's phone; they must change it on next login.
            db_exec(
                'UPDATE tenants SET password = ?, must_change_password = 1 WHERE id = ?',
                [password_hash($t['phone'], PASSWORD_DEFAULT), $tid],
                'si'
            );
            flash_set('success', "Password reset for {$t['name']}. Temporary password is their phone number; they must change it at next login.");
        } else {
            flash_set('error', 'Tenant not found.');
        }
        redirect(base_url('admin/tenants.php'));
    }

    if ($op === 'status') {
        $tid = (int) ($_POST['id'] ?? 0);
        $new = ($_POST['status'] ?? '') === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $t = get_tenant($tid);
        if ($t) {
            db_exec('UPDATE tenants SET status=? WHERE id=?', [$new, $tid], 'si');
            if ($t['house_id']) {
                if ($new === 'INACTIVE') {
                    // Free the house only when no other active tenant still holds it.
                    db_exec(
                        "UPDATE houses SET status='VACANT'
                          WHERE id=? AND NOT EXISTS (
                              SELECT 1 FROM tenants WHERE house_id=? AND status='ACTIVE')",
                        [$t['house_id'], $t['house_id']], 'ss'
                    );
                } else {
                    db_exec("UPDATE houses SET status='OCCUPIED' WHERE id=?", [$t['house_id']], 's');
                }
            }
            flash_set('success', "Tenant status set to $new.");
        }
        redirect(base_url('admin/tenants.php'));
    }
}

$tenants = db_all('SELECT t.*, h.status house_status FROM tenants t LEFT JOIN houses h ON h.id=t.house_id ORDER BY t.name');
$vacant = vacant_houses();

$pageTitle = 'Tenants';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-people"></i> Tenant Management</h1>
  <?php if ($action === 'list'): ?>
    <a class="btn btn-sm btn-dark" href="<?= e(base_url('admin/tenants.php?action=add')) ?>"><i class="bi bi-plus-lg"></i> Add Tenant</a>
  <?php else: ?>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/tenants.php')) ?>"><i class="bi bi-x-lg"></i> Cancel</a>
  <?php endif; ?>
</div>

<?php if ($action === 'add'): ?>
  <div class="card mb-3">
    <div class="card-header">Register Tenant &amp; Allocate House</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <?= csrf_field() ?><input type="hidden" name="op" value="create">
        <div class="col-md-4"><label class="form-label">Full Name</label><input class="form-control" name="name" required></div>
        <div class="col-md-4"><label class="form-label">Phone (login ID)</label><input class="form-control" name="phone" required></div>
        <div class="col-md-4"><label class="form-label">Email (optional; verification link sent)</label><input type="email" class="form-control" name="email"></div>
        <div class="col-md-4"><label class="form-label">Password</label><input type="password" autocomplete="new-password" class="form-control" name="password" required minlength="6"><div class="form-text">Stored as a secure hash.</div></div>
        <div class="col-md-4">
          <label class="form-label">Allocate Vacant House</label>
          <select class="form-select" name="house_id">
            <option value="">— No house —</option>
            <?php foreach ($vacant as $v): ?>
              <option value="<?= e($v['id']) ?>"><?= e($v['id']) ?> (<?= e(money($v['rent_amount'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4"><label class="form-label">Rent Amount</label><input type="number" step="0.01" min="0" class="form-control" name="rent_amount" placeholder="Auto from house if blank"></div>
        <div class="col-12">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="must_change_password" id="mustChange" value="1" checked>
            <label class="form-check-label" for="mustChange">Require tenant to change this initial password at first login</label>
          </div>
        </div>
        <div class="col-12"><button class="btn btn-dark"><i class="bi bi-save"></i> Create Tenant</button></div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header">All Tenants (<?= count($tenants) ?>)</div>
  <div class="table-responsive">
    <table class="table table-hover mb-0 align-middle">
      <thead><tr><th>Name</th><th>Phone</th><th>Email</th><th>House</th><th class="text-end">Rent</th><th>Status</th><th>Password</th><th class="text-end">Actions</th></tr></thead>
      <tbody>
      <?php foreach ($tenants as $t): ?>
        <tr>
          <td><strong><?= e($t['name']) ?></strong></td>
          <td class="small"><?= e($t['phone']) ?></td>
          <td class="small">
            <?php if (!empty($t['email'])): ?>
              <?= e($t['email']) ?><br>
              <?php if (!empty($t['email_verified_at'])): ?>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Verified</span>
              <?php else: ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Verification pending</span>
              <?php endif; ?>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><?= $t['house_id'] ? '<code>'.e($t['house_id']).'</code>' : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><?= e(money($t['rent_amount'])) ?></td>
          <td><?= status_badge($t['status']) ?></td>
          <td><?php if (!empty($t['must_change_password'])): ?>
                <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle"><i class="bi bi-key"></i> Change required</span>
              <?php else: ?>
                <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle"><i class="bi bi-check2"></i> Set</span>
              <?php endif; ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="<?= e(base_url('admin/payments.php?tenant_id=' . $t['id'])) ?>" title="Payment history"><i class="bi bi-clock-history"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(base_url('admin/invoices.php?tenant_id=' . $t['id'])) ?>" title="Invoices"><i class="bi bi-receipt"></i></a>
            <?php if (!empty($t['email']) && empty($t['email_verified_at'])): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?><input type="hidden" name="op" value="resend_activation"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-info" title="Resend activation link"><i class="bi bi-envelope"></i></button>
              </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Reset password for <?= e($t['name']) ?>? The temporary password becomes their phone number and they must change it at next login.')">
              <?= csrf_field() ?><input type="hidden" name="op" value="reset_password"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="btn btn-sm btn-outline-warning" title="Reset password"><i class="bi bi-key"></i></button>
            </form>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="op" value="status"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="status" value="<?= e($t['status']) ?>">
              <button class="btn btn-sm btn-outline-<?= $t['status']==='ACTIVE'?'warning':'success' ?>" title="Toggle status"><i class="bi bi-<?= $t['status']==='ACTIVE'?'person-dash':'person-check' ?>"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$tenants): ?><tr><td colspan="8" class="text-center text-muted py-4">No tenants registered.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
