<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_admin();

$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $mode = in_array($_POST['mode'] ?? '', ['simulate', 'sandbox', 'live'], true) ? $_POST['mode'] : 'simulate';
    set_setting('pesapal_mode', $mode);
    set_setting('pesapal_ipn_url', trim($_POST['ipn_url'] ?? ''));
    set_setting('pesapal_redirect_url', trim($_POST['redirect_url'] ?? ''));

    // Only overwrite credentials when a new value was supplied (fields are masked).
    $key = trim($_POST['consumer_key'] ?? '');
    if ($key !== '' && !str_starts_with($key, '•')) {
        set_setting('pesapal_consumer_key', $key);
    }
    $secret = $_POST['consumer_secret'] ?? '';
    if ($secret !== '' && !str_starts_with($secret, '•')) {
        set_setting('pesapal_consumer_secret', trim($secret));
    }
    flash_set('success', 'Pesapal configuration saved.');
    redirect(base_url('admin/pesapal_config.php'));
}

$cfg = pesapal_config();
$hasKey = $cfg['key'] !== '';
$hasSecret = $cfg['secret'] !== '';
$maskedKey = $hasKey ? str_repeat('•', 12) : '';
$maskedSecret = $hasSecret ? str_repeat('•', 12) : '';

$pageTitle = 'Pesapal Config';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<h1 class="h4 mb-1"><i class="bi bi-gear-wide-connected"></i> Pesapal API 3.0 Configuration</h1>
<p class="text-muted small mb-3">
  Runtime gateway settings stored in the database (they override environment defaults).
  Current effective mode:
  <?= $cfg['simulate'] ? '<span class="badge bg-secondary">SIMULATE</span>' : '<span class="badge bg-info">'.e(ucfirst($cfg['mode'])).'</span>' ?>
</p>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Gateway Credentials &amp; Endpoints</div>
      <div class="card-body">
        <?php if (!$hasKey || !$hasSecret): ?>
          <div class="alert alert-warning small">
            No consumer key/secret configured — the system runs in <strong>SIMULATE</strong> mode
            (local mock checkout). Enter sandbox or live credentials to process real payments.
          </div>
        <?php endif; ?>
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-md-6">
            <label class="form-label">Environment / Mode</label>
            <select class="form-select" name="mode">
              <?php foreach (['simulate'=>'Simulate (local mock)','sandbox'=>'Sandbox / Demo','live'=>'Live Production'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= $cfg['mode']===$v?'selected':'' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label">Consumer Key</label>
            <input class="form-control" name="consumer_key" value="<?= e($maskedKey) ?>" placeholder="<?= $hasKey?'(saved — type to replace)':'consumer key' ?>">
            <div class="form-text">Masked. Leave as-is to keep the current key.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Consumer Secret</label>
            <input class="form-control" name="consumer_secret" value="<?= e($maskedSecret) ?>" placeholder="<?= $hasSecret?'(saved — type to replace)':'consumer secret' ?>">
            <div class="form-text">Masked. Leave as-is to keep the current secret.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">IPN / Callback URL</label>
            <input class="form-control" name="ipn_url" value="<?= e($cfg['ipn_url']) ?>" placeholder="https://yourdomain/rental_system/payment/callback.php">
          </div>
          <div class="col-md-12">
            <label class="form-label">Redirect URL (tenant returns here)</label>
            <input class="form-control" name="redirect_url" value="<?= e($cfg['redirect_url']) ?>" placeholder="https://yourdomain/rental_system/tenant/payment_status.php">
          </div>
          <div class="col-12">
            <button class="btn btn-dark"><i class="bi bi-save"></i> Save API Settings</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Reference</div>
      <div class="card-body small">
        <p class="mb-2"><strong>API base URLs</strong></p>
        <div class="mb-2">Sandbox: <code><?= e(PESAPAL_SANDBOX_BASE) ?></code></div>
        <div class="mb-3">Live: <code><?= e(PESAPAL_LIVE_BASE) ?></code></div>
        <p class="mb-2"><strong>Endpoints used</strong></p>
        <ul class="mb-3">
          <li><code>/api/Auth/RequestToken</code></li>
          <li><code>/api/Transactions/SubmitOrderRequest</code></li>
          <li><code>/api/Transactions/GetTransactionStatus</code></li>
          <li><code>/api/URLSetup/RegisterIPN</code></li>
        </ul>
        <div class="alert alert-info mb-0">
          For sandbox/live, the IPN and redirect URLs must be <strong>publicly reachable over HTTPS</strong>.
          Credentials are stored server-side and never exposed to tenants. On the first checkout,
          the configured IPN URL is registered with Pesapal and its notification ID is saved for
          later payments. Changing the IPN URL automatically creates a new registration.
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
