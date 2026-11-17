<?php
declare(strict_types=1);

/**
 * Scheduled monthly invoice generator (proposal 3.14 / 1.10).
 *
 * Run from cron on the 1st of each month, e.g.:
 *   0 6 1 * *  php /path/to/rental_system/cron/generate_monthly.php
 *
 * Optional query/argv overrides: ?year=2026&month=9
 * Idempotent: existing tenant/period invoices are skipped (unique key guard).
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/invoicing.php';

// Web requests must present the shared CRON_TOKEN; otherwise this endpoint
// would let anyone create invoices and send tenant emails.
if (PHP_SAPI !== 'cli') {
    $expected = (string) env('CRON_TOKEN', '');
    $provided = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        exit("Forbidden.\n");
    }
}

// Resolve target period from argv (CLI) or query string (web-triggered).
$year = (int) date('Y');
$month = (int) date('n');

if (PHP_SAPI === 'cli') {
    foreach (array_slice($argv, 1) as $arg) {
        if (str_starts_with($arg, 'year='))  { $year  = (int) substr($arg, 5); }
        if (str_starts_with($arg, 'month=')) { $month = (int) substr($arg, 6); }
    }
} else {
    if (isset($_GET['year']))  { $year  = (int) $_GET['year']; }
    if (isset($_GET['month'])) { $month = (int) $_GET['month']; }
}

if ($month < 1 || $month > 12) { $month = (int) date('n'); }

$result = generate_invoices_for_period($year, $month);
$msg = sprintf(
    '[%s] Invoice generation %04d-%02d: created=%d skipped=%d emails_sent=%d email_failures=%d',
    date('Y-m-d H:i:s'), $year, $month, $result['created'], $result['skipped'],
    $result['emails_sent'], $result['email_failures']
);

error_log($msg);
if (PHP_SAPI === 'cli') {
    echo $msg . PHP_EOL;
} else {
    header('Content-Type: text/plain');
    echo $msg . PHP_EOL;
}
