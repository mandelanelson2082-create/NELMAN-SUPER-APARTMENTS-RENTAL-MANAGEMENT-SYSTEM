<?php
declare(strict_types=1);

/**
 * Application configuration.
 * Values can be overridden with environment variables (recommended for production).
 */

function env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        $v = $_ENV[$key] ?? null;
    }
    return ($v === null || $v === '') ? $default : $v;
}

define('APP_NAME', env('APP_NAME', 'Rental Management System'));
define('APP_ENV', env('APP_ENV', 'development'));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Nairobi'));
define('DEFAULT_CURRENCY', env('DEFAULT_CURRENCY', 'KES'));
define('APP_URL', rtrim((string) env('APP_URL', ''), '/'));
define('SMTP_HOST', env('SMTP_HOST', ''));
define('SMTP_PORT', (int) env('SMTP_PORT', '587'));
define('SMTP_USERNAME', env('SMTP_USERNAME', ''));
define('SMTP_PASSWORD', env('SMTP_PASSWORD', ''));
define('SMTP_ENCRYPTION', strtolower((string) env('SMTP_ENCRYPTION', 'tls')));
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', ''));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', APP_NAME));

// Database
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', (int) env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'rentals'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

// Pesapal API 3.0
// PESAPAL_MODE: simulate | sandbox | live
//   simulate = no real gateway calls; a local mock checkout exercises the full flow.
//   sandbox/live = real API calls using the credentials below.
define('PESAPAL_MODE', strtolower((string) env('PESAPAL_MODE', 'simulate')));
define('PESAPAL_CONSUMER_KEY', env('PESAPAL_CONSUMER_KEY', ''));
define('PESAPAL_CONSUMER_SECRET', env('PESAPAL_CONSUMER_SECRET', ''));
define('PESAPAL_SANDBOX_BASE', 'https://cybqa.pesapal.com/pesapalv3');
define('PESAPAL_LIVE_BASE', 'https://pay.pesapal.com/v3');
// Publicly reachable endpoints (required for sandbox/live).
define('PESAPAL_IPN_URL', env('PESAPAL_IPN_URL', ''));        // .../payment/callback.php
define('PESAPAL_REDIRECT_URL', env('PESAPAL_REDIRECT_URL', '')); // .../tenant/payment_status.php

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'development' ? '1' : '0');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'");
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Absolute base URL of the app, derived from the current request. */
function base_url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        // Filesystem root of the project (this file lives in <root>/config).
        $rootFs = str_replace('\\', '/', dirname(__DIR__));
        $docRoot = str_replace('\\', '/', rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'));
        if ($docRoot !== '' && str_starts_with($rootFs, $docRoot)) {
            $base = substr($rootFs, strlen($docRoot));
        } else {
            // Fallback: strip a known subdirectory or trailing script from SCRIPT_NAME.
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
            $base = preg_replace('#/(admin|tenant|payment|reports|cron|tenants|houses|invoices|database)/.*$#', '', $script);
            $base = preg_replace('#/[^/]*\.php$#', '', (string) $base);
        }
        $base = rtrim((string) $base, '/');
    }
    $path = ltrim($path, '/');
    return $base . ($path === '' ? '/' : '/' . $path);
}
