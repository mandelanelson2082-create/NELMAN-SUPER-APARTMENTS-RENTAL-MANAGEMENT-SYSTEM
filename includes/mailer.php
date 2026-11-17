<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPMailer\PHPMailer\PHPMailer;

class MailDeliveryException extends RuntimeException
{
}

/**
 * Effective mail configuration: DB settings override env/constants.
 * Returns host, port, username, password, encryption, from_address, from_name, app_url.
 */
function mail_config(): array
{
    return [
        'host' => trim((string) setting('smtp_host', SMTP_HOST)),
        'port' => (int) setting('smtp_port', (string) SMTP_PORT),
        'username' => (string) setting('smtp_username', SMTP_USERNAME),
        'password' => (string) setting('smtp_password', SMTP_PASSWORD),
        'encryption' => strtolower((string) setting('smtp_encryption', SMTP_ENCRYPTION)),
        'from_address' => trim((string) setting('mail_from_address', MAIL_FROM_ADDRESS)),
        'from_name' => (string) setting('mail_from_name', MAIL_FROM_NAME),
        'app_url' => rtrim(trim((string) setting('app_url', APP_URL)), '/'),
    ];
}

/** Absolute app root derived from the current request, or '' outside HTTP. */
function detected_app_url(): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || PHP_SAPI === 'cli') {
        return '';
    }
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    return ($https ? 'https' : 'http') . '://' . $host . base_url('');
}

function application_mailer(): PHPMailer
{
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        throw new MailDeliveryException('Email dependency is missing. Run composer install.');
    }
    require_once $autoload;

    $cfg = mail_config();
    if ($cfg['host'] === '' || $cfg['from_address'] === '') {
        throw new MailDeliveryException('SMTP host and sender address must be configured in Admin → Email.');
    }
    if (!filter_var($cfg['from_address'], FILTER_VALIDATE_EMAIL)) {
        throw new MailDeliveryException('The sender address is not a valid email address.');
    }
    if (!in_array($cfg['encryption'], ['tls', 'ssl', 'none'], true)) {
        throw new MailDeliveryException('Encryption must be tls, ssl, or none.');
    }
    if ($cfg['port'] < 1 || $cfg['port'] > 65535) {
        throw new MailDeliveryException('SMTP port must be between 1 and 65535.');
    }

    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = $cfg['host'];
    $mailer->Port = $cfg['port'];
    $mailer->SMTPAuth = $cfg['username'] !== '';
    $mailer->Username = $cfg['username'];
    $mailer->Password = $cfg['password'];
    $mailer->Timeout = 15;
    $mailer->CharSet = PHPMailer::CHARSET_UTF8;
    $mailer->SMTPSecure = match ($cfg['encryption']) {
        'tls' => PHPMailer::ENCRYPTION_STARTTLS,
        'ssl' => PHPMailer::ENCRYPTION_SMTPS,
        default => '',
    };
    $mailer->SMTPAutoTLS = $cfg['encryption'] === 'tls';
    $mailer->setFrom($cfg['from_address'], $cfg['from_name']);

    return $mailer;
}

function absolute_app_url(string $path): string
{
    $base = mail_config()['app_url'];
    if ($base === '') {
        $base = detected_app_url();
    }
    if ($base === '') {
        throw new MailDeliveryException('Set the App URL in Admin → Email so emailed links are absolute.');
    }

    $parts = parse_url($base);
    if (!is_array($parts)
        || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host'])
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])) {
        throw new MailDeliveryException('The app URL must be an absolute HTTP or HTTPS address without credentials or query parameters.');
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function send_application_email(string $recipient, string $recipientName, string $subject, string $html, string $text): void
{
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new MailDeliveryException('Recipient email address is invalid.');
    }

    $mailer = application_mailer();
    $mailer->addAddress($recipient, $recipientName);
    $mailer->Subject = $subject;
    $mailer->isHTML(true);
    $mailer->Body = $html;
    $mailer->AltBody = $text;

    try {
        $mailer->send();
    } catch (PHPMailerException $ex) {
        throw new MailDeliveryException('SMTP delivery failed: ' . $ex->getMessage(), 0, $ex);
    }
}

function send_tenant_activation_email(array $tenant, string $token): void
{
    $email = (string) ($tenant['email'] ?? '');
    if ($email === '') {
        throw new MailDeliveryException('Tenant has no email address for activation.');
    }

    $url = absolute_app_url('tenant/activate.php?token=' . rawurlencode($token));
    $safeName = e($tenant['name'] ?? 'Tenant');
    $safeUrl = e($url);
    $html = '<p>Hello ' . $safeName . ',</p>'
        . '<p>Your tenant account has been created. Verify your email address to enable sign-in.</p>'
        . '<p><a href="' . $safeUrl . '">Activate your account</a></p>'
        . '<p>This link expires in 48 hours. If you did not expect this message, you can ignore it.</p>';
    $text = "Hello {$tenant['name']},\n\n"
        . "Your tenant account has been created. Verify your email address to enable sign-in:\n{$url}\n\n"
        . "This link expires in 48 hours. If you did not expect this message, you can ignore it.";

    send_application_email($email, (string) ($tenant['name'] ?? ''), 'Verify your tenant account', $html, $text);
}

function send_rent_invoice_email(array $tenant, array $invoice): void
{
    $email = (string) ($tenant['email'] ?? '');
    if ($email === '') {
        throw new MailDeliveryException('Tenant has no email address for invoice delivery.');
    }

    $invoicePage = absolute_app_url('tenant/invoices.php');
    $balance = max(0, round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2));
    $period = invoice_period_label($invoice);
    $rows = [
        ['Invoice', (string) $invoice['invoice_number']],
        ['Period', $period],
        ['Amount', money($invoice['amount'])],
        ['Paid', money($invoice['amount_paid'])],
        ['Balance', money($balance)],
        ['Due date', (string) ($invoice['due_date'] ?? '—')],
        ['Status', (string) $invoice['status']],
    ];

    $htmlRows = '';
    $textRows = [];
    foreach ($rows as [$label, $value]) {
        $htmlRows .= '<tr><th style="padding:8px 12px;text-align:left;border-bottom:1px solid #e2e9f0">'
            . e($label) . '</th><td style="padding:8px 12px;border-bottom:1px solid #e2e9f0">'
            . e($value) . '</td></tr>';
        $textRows[] = $label . ': ' . $value;
    }

    $html = '<p>Hello ' . e($tenant['name'] ?? 'Tenant') . ',</p>'
        . '<p>Your rent invoice is ready. You can review your invoices and payment history in the tenant portal.</p>'
        . '<table style="width:100%;border-collapse:collapse">' . $htmlRows . '</table>'
        . '<p style="margin-top:20px"><a href="' . e($invoicePage) . '">View my invoices</a></p>';
    $text = "Hello {$tenant['name']},\n\nYour rent invoice is ready:\n"
        . implode("\n", $textRows)
        . "\n\nView your invoices: {$invoicePage}";

    send_application_email(
        $email,
        (string) ($tenant['name'] ?? ''),
        'Rent invoice ' . (string) $invoice['invoice_number'],
        $html,
        $text
    );
}
