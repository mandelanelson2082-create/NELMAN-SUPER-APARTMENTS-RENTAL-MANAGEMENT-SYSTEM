<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/invoicing.php';

$token = trim((string) ($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    flash_set('error', 'This activation link is invalid or has expired. Ask the administrator to send a new one.');
    redirect(base_url('index.php'));
}

$tokenHash = hash('sha256', $token);
$tenant = db_one(
    'SELECT id FROM tenants
      WHERE activation_token_hash = ? AND activation_token_expires_at > NOW()
        AND email_verified_at IS NULL
      LIMIT 1',
    [$tokenHash],
    's'
);
if (!$tenant) {
    flash_set('error', 'This activation link is invalid or has expired. Ask the administrator to send a new one.');
    redirect(base_url('index.php'));
}

$activated = db_exec(
    'UPDATE tenants
        SET email_verified_at = NOW(), activation_token_hash = NULL, activation_token_expires_at = NULL
      WHERE id = ? AND activation_token_hash = ? AND activation_token_expires_at > NOW()
        AND email_verified_at IS NULL',
    [(int) $tenant['id'], $tokenHash],
    'is'
);
if ($activated !== 1) {
    flash_set('error', 'This activation link is invalid or has expired. Ask the administrator to send a new one.');
    redirect(base_url('index.php'));
}

send_pending_invoice_emails((int) $tenant['id']);
flash_set('success', 'Your email is verified. You can now sign in to your tenant account.');
redirect(base_url('index.php'));
