<?php
declare(strict_types=1);

/** Shared helpers: escaping, formatting, CSRF, flash, and the arrears / risk engine. */

function e(string|int|float|bool|null $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    if (!headers_sent()) {
        header('Location: ' . $url);
    }
    exit;
}

function money(float|int|string|null $n): string
{
    return DEFAULT_CURRENCY . ' ' . number_format((float) $n, 2);
}

function money_short(float|int|string|null $n): string
{
    return DEFAULT_CURRENCY . ' ' . number_format((float) $n, 0);
}

/* ------------------------------- CSRF ------------------------------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrf_token(), (string) $sent)) {
        http_response_code(403);
        exit('Invalid or expired CSRF token.');
    }
}

/* ------------------------------ Flash ------------------------------- */

function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_render(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['type'] === 'error' ? 'danger' : e($f['type']);
        $html .= '<div class="alert alert-' . $cls . ' alert-dismissible fade show" role="alert">'
            . e($f['msg'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/* --------------------------- Data helpers --------------------------- */

function get_tenant(int $id): ?array
{
    return db_one('SELECT * FROM tenants WHERE id = ?', [$id], 'i');
}

function get_house(string $id): ?array
{
    return db_one('SELECT * FROM houses WHERE id = ?', [$id], 's');
}

function get_invoice(int $id): ?array
{
    return db_one('SELECT * FROM invoices WHERE id = ?', [$id], 'i');
}

/* --------------------- Settings (key/value store) -------------------- */

/** Read a runtime setting, falling back to a default. Cached per request. */
function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT skey, svalue FROM settings') as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    }
    $v = $cache[$key] ?? null;
    return ($v === null || $v === '') ? $default : $v;
}

/** Persist a runtime setting (upsert). */
function set_setting(string $key, ?string $value): void
{
    db_exec(
        'INSERT INTO settings (skey, svalue) VALUES (?,?)
         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)',
        [$key, $value],
        'ss'
    );
}

/**
 * Resolve effective Pesapal configuration: DB settings override env/constants.
 * Returns ['mode','key','secret','ipn_url','redirect_url','simulate','base'].
 */
function pesapal_config(): array
{
    $mode = strtolower((string) setting('pesapal_mode', PESAPAL_MODE));
    $key = (string) setting('pesapal_consumer_key', PESAPAL_CONSUMER_KEY);
    $secret = (string) setting('pesapal_consumer_secret', PESAPAL_CONSUMER_SECRET);
    $simulate = ($mode === 'simulate') || $key === '' || $secret === '';
    return [
        'mode' => $mode,
        'key' => $key,
        'secret' => $secret,
        'ipn_url' => (string) setting('pesapal_ipn_url', PESAPAL_IPN_URL),
        'redirect_url' => (string) setting('pesapal_redirect_url', PESAPAL_REDIRECT_URL),
        'simulate' => $simulate,
        'base' => ($mode === 'live') ? PESAPAL_LIVE_BASE : PESAPAL_SANDBOX_BASE,
    ];
}

function pesapal_mode(): string
{
    return pesapal_config()['mode'];
}

function pesapal_is_simulate(): bool
{
    return pesapal_config()['simulate'];
}

/** Human label for an invoice period; handles CUSTOM (null month/year). */
function invoice_period_label(array $inv): string
{
    if (($inv['type'] ?? 'RENT') === 'CUSTOM' || empty($inv['month'])) {
        return $inv['description'] ?? 'Custom charge';
    }
    $monthName = date('F', mktime(0, 0, 0, (int) $inv['month'], 1));
    return $monthName . ' ' . $inv['year'];
}

function vacant_houses(): array
{
    return db_all("SELECT * FROM houses WHERE status = 'VACANT' ORDER BY id");
}

function active_tenants(): array
{
    return db_all("SELECT * FROM tenants WHERE status = 'ACTIVE' ORDER BY name");
}

function all_properties(): array
{
    return db_all('SELECT * FROM properties ORDER BY name');
}

function get_property(int $id): ?array
{
    return db_one('SELECT * FROM properties WHERE id = ?', [$id], 'i');
}

/** Unique, URL-safe merchant reference for a rental transaction. */
function generate_merchant_reference(): string
{
    return 'RMS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5)));
}

/* --------------------- Arrears & risk engine ------------------------ */

/** Outstanding rent for a tenant = sum(amount - amount_paid) over UNPAID/PARTIAL invoices. */
function tenant_stats(int $tenant_id): array
{
    $arrears = 0.0;
    $unpaid = 0;
    $partial = 0;

    $rows = db_all(
        "SELECT status, amount, amount_paid FROM invoices
         WHERE tenant_id = ? AND status IN ('UNPAID','PARTIAL')",
        [$tenant_id],
        'i'
    );
    foreach ($rows as $r) {
        $arrears += max(0.0, (float) $r['amount'] - (float) $r['amount_paid']);
        if ($r['status'] === 'UNPAID') {
            $unpaid++;
        } else {
            $partial++;
        }
    }

    $failed = (int) db_value(
        "SELECT COUNT(*) FROM payments
         WHERE tenant_id = ? AND status = 'FAILED'
           AND created_at >= (NOW() - INTERVAL 6 MONTH)",
        [$tenant_id],
        'i'
    );

    return [
        'arrears' => round($arrears, 2),
        'unpaid' => $unpaid,
        'partial' => $partial,
        'outstanding_invoices' => $unpaid + $partial,
        'failed' => $failed,
        'credit' => tenant_credit($tenant_id),
    ];
}

/**
 * Transparent, rule-based arrears risk indicator (proposal 1.14 / 3.15).
 * HIGH is evaluated first, then MODERATE, otherwise LOW.
 */
function tenant_risk(int $tenant_id): array
{
    $tenant = get_tenant($tenant_id);
    $rent = (float) ($tenant['rent_amount'] ?? 0);
    $s = tenant_stats($tenant_id);

    $high = false;
    $mod = false;
    $reasons = [];

    if ($rent > 0 && $s['arrears'] > $rent) {
        $high = true;
        $reasons[] = 'Outstanding arrears exceed one monthly rent';
    }
    if ($s['outstanding_invoices'] >= 2) {
        $high = true;
        $reasons[] = 'Two or more unpaid/partially paid invoices';
    }
    if ($s['failed'] >= 3) {
        $high = true;
        $reasons[] = 'Three or more failed payments in the last 6 months';
    }

    if (!$high) {
        if ($rent > 0 && $s['arrears'] > 0.25 * $rent) {
            $mod = true;
            $reasons[] = 'Outstanding arrears exceed 25% of monthly rent';
        }
        if ($s['outstanding_invoices'] >= 1) {
            $mod = true;
            $reasons[] = 'At least one unpaid/partially paid invoice';
        }
        if ($s['failed'] >= 1) {
            $mod = true;
            $reasons[] = 'At least one failed payment in the last 6 months';
        }
    }

    $level = $high ? 'HIGH' : ($mod ? 'MODERATE' : 'LOW');

    return array_merge($s, [
        'level' => $level,
        'rent' => $rent,
        'reasons' => $reasons,
        'action_label' => $level === 'HIGH' ? 'Review Payments' : ($level === 'MODERATE' ? 'Review' : 'View History'),
    ]);
}

function risk_color(string $level): string
{
    return match ($level) {
        'HIGH' => 'danger',
        'MODERATE' => 'warning',
        default => 'success',
    };
}

function risk_badge(string $level): string
{
    return '<span class="badge bg-' . risk_color($level) . '">' . e($level) . '</span>';
}

function status_badge(string $status): string
{
    $map = [
        'PAID' => 'success', 'COMPLETED' => 'success', 'OCCUPIED' => 'primary', 'ACTIVE' => 'success',
        'PARTIAL' => 'warning', 'PENDING' => 'secondary',
        'UNPAID' => 'danger', 'FAILED' => 'danger', 'VACANT' => 'success',
        'CANCELLED' => 'dark', 'INACTIVE' => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $cls . '">' . e($status) . '</span>';
}

/** Amount of completed advance payment that has not yet been assigned to an invoice. */
function tenant_credit(int $tenantId): float
{
    $credit = (float) db_value(
        "SELECT
            (SELECT COALESCE(SUM(amount),0) FROM payments WHERE tenant_id = ? AND invoice_id IS NULL AND status = 'COMPLETED') -
            (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a
              JOIN payments p ON p.id = a.payment_id WHERE p.tenant_id = ?)",
        [$tenantId, $tenantId], 'ii'
    );
    return round(max(0.0, $credit), 2);
}

/** Apply completed advance payments to a tenant's outstanding invoices, oldest first. */
function apply_tenant_credit(int $tenantId): void
{
    db()->begin_transaction();
    try {
        allocate_tenant_credit($tenantId);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        throw $ex;
    }
}

/**
 * Allocate completed advance payments within the caller's transaction.
 * A preferred payment can settle a selected invoice first, then continue FIFO.
 */
function allocate_tenant_credit(
    int $tenantId,
    ?int $preferredInvoiceId = null,
    ?int $onlyPaymentId = null
): void {
    $paymentSql = "SELECT p.id, p.amount - COALESCE(SUM(a.amount),0) available
                     FROM payments p LEFT JOIN payment_allocations a ON a.payment_id = p.id
                    WHERE p.tenant_id = ? AND p.invoice_id IS NULL AND p.status = 'COMPLETED'";
    $paymentParams = [$tenantId];
    $paymentTypes = 'i';
    if ($onlyPaymentId !== null) {
        $paymentSql .= ' AND p.id = ?';
        $paymentParams[] = $onlyPaymentId;
        $paymentTypes .= 'i';
    }
    $paymentSql .= ' GROUP BY p.id, p.amount HAVING available > 0 ORDER BY p.transaction_date, p.id FOR UPDATE';

    foreach (db_all($paymentSql, $paymentParams, $paymentTypes) as $payment) {
        $paymentId = (int) $payment['id'];
        $available = round((float) $payment['available'], 2);
        $excludedInvoiceId = null;

        if ($preferredInvoiceId !== null && $paymentId === $onlyPaymentId) {
            $preferredInvoice = db_one(
                "SELECT id, amount, amount_paid FROM invoices
                  WHERE id = ? AND tenant_id = ? AND status IN ('UNPAID','PARTIAL')
                  FOR UPDATE",
                [$preferredInvoiceId, $tenantId],
                'ii'
            );
            if ($preferredInvoice) {
                $due = round((float) $preferredInvoice['amount'] - (float) $preferredInvoice['amount_paid'], 2);
                if ($due > 0 && $available > 0) {
                    $allocated = min($available, $due);
                    db_insert(
                        'INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,?)',
                        [$paymentId, (int) $preferredInvoice['id'], $allocated],
                        'iid'
                    );
                    recalc_invoice((int) $preferredInvoice['id']);
                    $available = round($available - $allocated, 2);
                    $excludedInvoiceId = (int) $preferredInvoice['id'];
                }
            }
        }

        if ($available <= 0) {
            continue;
        }

        $invoiceSql = "SELECT id, amount, amount_paid FROM invoices
                        WHERE tenant_id = ? AND status IN ('UNPAID','PARTIAL')";
        $invoiceParams = [$tenantId];
        $invoiceTypes = 'i';
        if ($excludedInvoiceId !== null) {
            $invoiceSql .= ' AND id <> ?';
            $invoiceParams[] = $excludedInvoiceId;
            $invoiceTypes .= 'i';
        }
        $invoiceSql .= ' ORDER BY COALESCE(due_date, created_at), created_at, id FOR UPDATE';

        foreach (db_all($invoiceSql, $invoiceParams, $invoiceTypes) as $invoice) {
            if ($available <= 0) {
                break;
            }
            $due = round((float) $invoice['amount'] - (float) $invoice['amount_paid'], 2);
            if ($due <= 0) {
                continue;
            }
            $allocated = min($available, $due);
            db_insert(
                'INSERT INTO payment_allocations (payment_id, invoice_id, amount) VALUES (?,?,?)',
                [$paymentId, (int) $invoice['id'], $allocated],
                'iid'
            );
            recalc_invoice((int) $invoice['id']);
            $available = round($available - $allocated, 2);
        }
    }
}

/** Recompute an invoice's status from legacy direct payments and advance allocations. */
function recalc_invoice(int $invoice_id): void
{
    $invoice = get_invoice($invoice_id);
    if (!$invoice) {
        return;
    }
    $paid = (float) db_value(
        "SELECT
            (SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id = ? AND status = 'COMPLETED') +
            (SELECT COALESCE(SUM(a.amount),0) FROM payment_allocations a WHERE a.invoice_id = ?)",
        [$invoice_id, $invoice_id], 'ii'
    );
    $amount = (float) $invoice['amount'];
    $paid = min($paid, $amount);
    if ($paid <= 0.0) {
        $status = 'UNPAID';
    } elseif ($paid + 0.001 >= $amount) {
        $status = 'PAID';
        $paid = $amount;
    } else {
        $status = 'PARTIAL';
    }
    db_exec(
        'UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?',
        [$paid, $status, $invoice_id], 'dsi'
    );
}
