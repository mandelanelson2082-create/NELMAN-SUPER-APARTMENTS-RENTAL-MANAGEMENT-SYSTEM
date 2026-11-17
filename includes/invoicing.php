<?php
declare(strict_types=1);
require_once __DIR__ . '/mailer.php';

/**
 * Monthly invoice generation (proposal 3.14).
 * Shared by admin/invoices.php and cron/generate_monthly.php.
 */

/** Create one rent invoice for an active tenant with a positive rent amount, if it does not already exist. */
function generate_invoice_for_tenant(int $tenantId, int $year, int $month): bool
{
    $tenant = db_one(
        "SELECT t.*, COALESCE(NULLIF(t.rent_amount, 0), h.rent_amount, 0) AS invoice_rent
           FROM tenants t LEFT JOIN houses h ON h.id = t.house_id
          WHERE t.id = ? AND t.status = 'ACTIVE' LIMIT 1",
        [$tenantId], 'i'
    );
    if (!$tenant || (float) $tenant['invoice_rent'] <= 0) {
        return false;
    }

    $exists = (int) db_value(
        'SELECT COUNT(*) FROM invoices WHERE tenant_id=? AND year=? AND month=?',
        [$tenantId, $year, $month], 'iii'
    );
    if ($exists) {
        return false;
    }

    $amount = (float) $tenant['invoice_rent'];
    $number = sprintf('INV-%04d-%02d-%05d', $year, $month, $tenantId);
    $due = sprintf('%04d-%02d-10', $year, $month);
    $desc = 'Rent for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year));

    try {
        db_insert(
            'INSERT INTO invoices (tenant_id, invoice_number, type, description, month, year, amount, amount_paid, due_date, status)
             VALUES (?,?,?,?,?,?,?,0,?,?)',
            [$tenantId, $number, 'RENT', $desc, $month, $year, $amount, $due, 'UNPAID'],
            'isssiidss'
        );
        apply_tenant_credit($tenantId);
        return true;
    } catch (mysqli_sql_exception $ex) {
        if ($ex->getCode() === 1062) {
            return false;
        }
        throw $ex;
    }
}

/** Generate monthly rent invoices for all active tenants with a positive rent amount. */
function generate_invoices_for_period(int $year, int $month): array
{
    $created = 0;
    $skipped = 0;
    $tenants = db_all(
        "SELECT t.id FROM tenants t WHERE t.status = 'ACTIVE' ORDER BY t.name"
    );

    foreach ($tenants as $tenant) {
        if (generate_invoice_for_tenant((int) $tenant['id'], $year, $month)) {
            $created++;
        } else {
            $skipped++;
        }
    }

    $emailStats = send_pending_invoice_emails();
    return [
        'created' => $created,
        'skipped' => $skipped,
        'emails_sent' => $emailStats['sent'],
        'email_failures' => $emailStats['failed'],
    ];
}

/** Send unsent rent invoice notices to active tenants with verified email addresses. */
function send_pending_invoice_emails(?int $tenantId = null): array
{
    $sql = "SELECT i.*, t.id AS tenant_id, t.name AS tenant_name, t.email
              FROM invoices i
              JOIN tenants t ON t.id = i.tenant_id
             WHERE i.type = 'RENT' AND i.status <> 'CANCELLED'
               AND i.email_sent_at IS NULL AND t.status = 'ACTIVE'
               AND t.email IS NOT NULL AND t.email <> ''
               AND t.email_verified_at IS NOT NULL";
    $params = [];
    $types = '';
    if ($tenantId !== null) {
        $sql .= ' AND t.id = ?';
        $params[] = $tenantId;
        $types = 'i';
    }
    $invoices = db_all($sql . ' ORDER BY i.year, i.month, i.id', $params, $types);

    $sent = 0;
    $failed = 0;
    foreach ($invoices as $invoice) {
        $tenant = ['id' => (int) $invoice['tenant_id'], 'name' => $invoice['tenant_name'], 'email' => $invoice['email']];
        try {
            send_rent_invoice_email($tenant, $invoice);
            db_exec('UPDATE invoices SET email_sent_at = NOW() WHERE id = ? AND email_sent_at IS NULL', [(int) $invoice['id']], 'i');
            $sent++;
        } catch (MailDeliveryException $ex) {
            error_log('Invoice email failed for invoice ' . $invoice['invoice_number'] . ': ' . $ex->getMessage());
            $failed++;
        }
    }

    return ['sent' => $sent, 'failed' => $failed];
}
