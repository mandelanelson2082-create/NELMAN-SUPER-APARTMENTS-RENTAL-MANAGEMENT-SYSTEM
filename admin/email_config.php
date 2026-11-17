<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/mailer.php';
require_admin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'test') {
        $recipient = trim((string) ($_POST['test_recipient'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            flash_set('error', 'Enter a valid recipient address for the test email.');
        } else {
            try {
                $cfg = mail_config();
                $html = '<p>This is a test message from ' . e(APP_NAME) . '.</p>'
                    . '<p>SMTP host: <strong>' . e($cfg['host']) . ':' . (int) $cfg['port'] . '</strong><br>'
                    . 'Encryption: <strong>' . e($cfg['encryption']) . '</strong></p>';
                $text = "This is a test message from " . APP_NAME . ".\n\n"
                    . "SMTP host: {$cfg['host']}:{$cfg['port']}\nEncryption: {$cfg['encryption']}\n";
                send_application_email($recipient, '', 'Test email from ' . APP_NAME, $html, $text);
                flash_set('success', 'Test email sent to ' . $recipient . '.');
            } catch (MailDeliveryException $ex) {
                flash_set('error', 'Test email failed: ' . $ex->getMessage());
            }
        }
        redirect(base_url('admin/email_config.php'));
    }

    $host = trim((string) ($_POST['smtp_host'] ?? ''));
    $port = (int) ($_POST['smtp_port'] ?? 0);
    $encryption = strtolower(trim((string) ($_POST['smtp_encryption'] ?? 'tls')));
    $fromAddress = trim((string) ($_POST['mail_from_address'] ?? ''));
    $fromName = trim((string) ($_POST['mail_from_name'] ?? ''));
    $appUrl = rtrim(trim((string) ($_POST['app_url'] ?? '')), '/');

    if ($host === '') {
        $errors[] = 'SMTP host is required.';
    }
    if ($port < 1 || $port > 65535) {
        $errors[] = 'SMTP port must be between 1 and 65535.';
    }
    if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
        $errors[] = 'Encryption must be tls, ssl, or none.';
    }
    if (!filter_var($fromAddress, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'From address must be a valid email address.';
    }
    if ($appUrl !== '') {
        $parts = parse_url($appUrl);
        if (!is_array($parts)
            || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            $errors[] = 'App URL must be an absolute HTTP(S) URL without query parameters.';
        }
    }

    if ($errors) {
        foreach ($errors as $err) {
            flash_set('error', $err);
        }
        redirect(base_url('admin/email_config.php'));
    }

    set_setting('smtp_host', $host);
    set_setting('smtp_port', (string) $port);
    set_setting('smtp_encryption', $encryption);
    set_setting('smtp_username', trim((string) ($_POST['smtp_username'] ?? '')));
    set_setting('mail_from_address', $fromAddress);
    set_setting('mail_from_name', $fromName !== '' ? $fromName : APP_NAME);
    set_setting('app_url', $appUrl);

    // Password field is masked; only overwrite when a new value is typed.
    $password = (string) ($_POST['smtp_password'] ?? '');
    if ($password !== '' && !str_starts_with($password, '•')) {
        set_setting('smtp_password', $password);
    }

    flash_set('success', 'Email configuration saved.');
    redirect(base_url('admin/email_config.php'));
}

$cfg = mail_config();
$hasPassword = $cfg['password'] !== '';
$maskedPassword = $hasPassword ? str_repeat('•', 12) : '';
$appUrlSetting = (string) db_value("SELECT svalue FROM settings WHERE skey = 'app_url'", [], '');
$appUrlSource = $appUrlSetting !== '' ? 'database' : (APP_URL !== '' ? 'environment' : 'none');
$effectiveAppUrl = $cfg['app_url'] !== '' ? $cfg['app_url'] : detected_app_url();

$pageTitle = 'Email Config';
$nav = 'admin';
require_once __DIR__ . '/../partials/header.php';
?>
<h1 class="h4 mb-1"><i class="bi bi-envelope-gear"></i> Email / SMTP Configuration</h1>
<p class="text-muted small mb-3">
  Used for tenant activation links and rent invoice notices. Runtime settings are stored in
  the database and override environment defaults.
  <?php if ($cfg['host'] !== '' && $cfg['from_address'] !== ''): ?>
    <span class="badge bg-success">Configured</span>
  <?php else: ?>
    <span class="badge bg-warning text-dark">Not configured</span>
  <?php endif; ?>
</p>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">SMTP Settings</div>
      <div class="card-body">
        <?php if ($cfg['host'] === '' || $cfg['from_address'] === ''): ?>
          <div class="alert alert-warning small">
            No SMTP transport is configured, so activation and invoice emails will fail.
            Enter your mail provider settings below (for example Gmail, Brevo, or Mailgun SMTP)
            and use the test send on the right.
          </div>
        <?php endif; ?>
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <div class="col-md-8">
            <label class="form-label">SMTP Host</label>
            <input class="form-control" name="smtp_host" value="<?= e($cfg['host']) ?>" placeholder="smtp.example.com" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Port</label>
            <input class="form-control" type="number" min="1" max="65535" name="smtp_port" value="<?= (int) $cfg['port'] ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label">Encryption</label>
            <select class="form-select" name="smtp_encryption">
              <?php foreach (['tls' => 'TLS (STARTTLS, port 587)', 'ssl' => 'SSL (SMTPS, port 465)', 'none' => 'None (plain)'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= $cfg['encryption'] === $v ? 'selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label">SMTP Username</label>
            <input class="form-control" name="smtp_username" value="<?= e($cfg['username']) ?>" placeholder="Leave empty for an open relay">
            <div class="form-text">Usually the full mailbox address for hosted providers.</div>
          </div>
          <div class="col-md-12">
            <label class="form-label">SMTP Password</label>
            <input class="form-control" type="password" autocomplete="new-password" name="smtp_password" value="<?= e($maskedPassword) ?>" placeholder="<?= $hasPassword ? '(saved — type to replace)' : 'app password / API key' ?>">
            <div class="form-text">Masked. Leave as-is to keep the current password.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">From Address</label>
            <input class="form-control" type="email" name="mail_from_address" value="<?= e($cfg['from_address']) ?>" placeholder="no-reply@yourdomain.com" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">From Name</label>
            <input class="form-control" name="mail_from_name" value="<?= e($cfg['from_name']) ?>" placeholder="<?= e(APP_NAME) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">App URL (for links in email)</label>
            <input class="form-control" name="app_url" value="<?= e($cfg['app_url']) ?>" placeholder="<?= e(detected_app_url() ?: 'https://yourdomain.com/rental_system') ?>">
            <div class="form-text">
              Effective now: <code><?= e($effectiveAppUrl !== '' ? $effectiveAppUrl : '—') ?></code>
              (source: <?= e($appUrlSource) ?>; falls back to the current request for web sends).
              Required for CLI/cron emails, which have no request URL.
            </div>
          </div>
          <div class="col-12">
            <button class="btn btn-dark"><i class="bi bi-save"></i> Save Email Settings</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card mb-3">
      <div class="card-header">Send a Test Email</div>
      <div class="card-body">
        <p class="text-muted small">Uses the saved settings. Save changes first, then send.</p>
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="test">
          <div class="col-12">
            <label class="form-label">Recipient</label>
            <input class="form-control" type="email" name="test_recipient" placeholder="you@example.com" required>
          </div>
          <div class="col-12">
            <button class="btn btn-outline-dark"><i class="bi bi-send"></i> Send Test Email</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header">What Uses Email</div>
      <div class="card-body small">
        <ul class="mb-2">
          <li><strong>Tenant activation</strong> — sent when an admin creates a tenant; the link expires after 48 hours.</li>
          <li><strong>Rent invoices</strong> — sent for new invoices to active tenants with a verified email; each invoice is emailed once.</li>
        </ul>
        <div class="alert alert-info mb-0">
          Invoice notices are attempted during invoice generation (admin screen or the monthly cron job).
          Failed sends are logged and retried the next time generation runs.
        </div>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../partials/footer.php'; ?>
