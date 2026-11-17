<?php
declare(strict_types=1);

/**
 * Sample-data seeder. Run once AFTER schema.sql is loaded.
 *
 *   CLI:     php database/seed.php
 *   Browser: http://localhost/rental_system/database/seed.php
 *
 * Passwords are hashed with password_hash() at runtime so they are always
 * valid for the local PHP build (do not hardcode hashes in SQL).
 *
 * Default logins:
 *   Admin  -> username: admin   password: Admin@123
 *   Tenant -> phone: 0700000001 password: Tenant@123  (and 0700000002 / 0700000003)
 */

require_once __DIR__ . '/../config/app.php';

$lines = [];
function say(string $m): void { global $lines; $lines[] = $m; }

function out(): void {
    global $lines;
    if (PHP_SAPI === 'cli') {
        echo implode("\n", $lines) . "\n";
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $lines) . "\n";
    }
}

try {
    $existing = db_value('SELECT COUNT(*) FROM admins');
    if ((int) $existing > 0) {
        say('Database already contains data. Skipping seed (drop tables to reseed).');
        out();
        return;
    }

    // ---- admin ----
    db_exec(
        'INSERT INTO admins (name, username, password, role) VALUES (?,?,?,?)',
        ['System Administrator', 'admin', password_hash('Admin@123', PASSWORD_DEFAULT), 'ADMIN'],
        'ssss'
    );
    db_exec(
        'INSERT INTO admins (name, username, password, role) VALUES (?,?,?,?)',
        ['Property Manager', 'manager', password_hash('Manager@123', PASSWORD_DEFAULT), 'MANAGER'],
        'ssss'
    );
    say('Admins created: admin/Admin@123, manager/Manager@123');

    // ---- properties (buildings) ----
    $props = [
        ['Sunrise Apartments', 'Nairobi CBD'],
        ['Greenview Court',    'Westlands'],
        ['Riverside Flats',    'Kisumu'],
    ];
    $propIds = [];
    foreach ($props as [$pname, $ploc]) {
        $propIds[$pname] = db_insert(
            'INSERT INTO properties (name, location) VALUES (?,?)', [$pname, $ploc], 'ss'
        );
    }
    say('Properties created: ' . count($props));

    // ---- houses (assigned to properties) ----
    $sunrise = $propIds['Sunrise Apartments'];
    $green   = $propIds['Greenview Court'];
    $river   = $propIds['Riverside Flats'];
    $houses = [
        // id, property_id, rent, status
        ['H1', $sunrise, 15000, 'OCCUPIED'], ['H2', $sunrise, 15000, 'VACANT'],
        ['BLOCK-A-01', $sunrise, 25000, 'VACANT'],
        ['A101', $green, 20000, 'OCCUPIED'], ['A102', $green, 20000, 'VACANT'],
        ['B201', $river, 18000, 'OCCUPIED'], ['B202', $river, 18000, 'VACANT'],
    ];
    foreach ($houses as [$id, $pid, $rent, $status]) {
        db_exec('INSERT INTO houses (id, property_id, rent_amount, status) VALUES (?,?,?,?)',
            [$id, $pid, $rent, $status], 'sids');
    }
    say('Houses created: ' . count($houses));

    // ---- tenants (with hashed passwords) ----
    $pw = password_hash('Tenant@123', PASSWORD_DEFAULT);
    $tenants = [
        // name, phone, email, house_id, rent
        ['John Kamau',  '0700000001', 'john@example.com',  'H1',   15000],
        ['Mary Wanjiku','0700000002', 'mary@example.com',  'A101', 20000],
        ['Peter Otieno','0700000003', 'peter@example.com', 'B201', 18000],
    ];
    $tenantIds = [];
    foreach ($tenants as [$name, $phone, $email, $house, $rent]) {
        $tenantIds[$phone] = db_insert(
            'INSERT INTO tenants (name, phone, email, password, house_id, rent_amount, status, must_change_password, email_verified_at)
             VALUES (?,?,?,?,?,?,?,0,NOW())',
            [$name, $phone, $email, $pw, $house, $rent, 'ACTIVE'],
            'sssssds'
        );
    }
    say('Tenants created: John (0700000001), Mary (0700000002), Peter (0700000003) - password Tenant@123');

    // ---- runtime settings (Pesapal in SIMULATE mode out of the box) ----
    $settings = [
        'pesapal_mode'            => 'simulate',
        'pesapal_consumer_key'    => '',
        'pesapal_consumer_secret' => '',
        'pesapal_ipn_url'         => '',
        'pesapal_redirect_url'    => '',
    ];
    foreach ($settings as $k => $v) {
        db_exec('INSERT INTO settings (skey, svalue) VALUES (?,?) ON DUPLICATE KEY UPDATE svalue=VALUES(svalue)',
            [$k, $v], 'ss');
    }
    say('Settings seeded: Pesapal mode = simulate (configure real keys in Admin > Pesapal).');

    $john  = $tenantIds['0700000001'];
    $mary  = $tenantIds['0700000002'];
    $peter = $tenantIds['0700000003'];

    $thisYear = (int) date('Y');
    $thisMonth = (int) date('n');

    /** Create a RENT invoice and return its id. */
    $mkInvoice = function (int $tid, int $month, int $year, float $amount, string $status, float $paid = 0) {
        $num = sprintf('INV-%d-%02d-%02d', $tid, $year, $month);
        $due = sprintf('%04d-%02d-10', $year, $month);
        $desc = 'Rent for ' . date('F Y', mktime(0, 0, 0, $month, 1, $year));
        return db_insert(
            "INSERT INTO invoices (tenant_id, invoice_number, type, description, month, year, amount, amount_paid, due_date, status)
             VALUES (?,?,'RENT',?,?,?,?,?,?,?)",
            [$tid, $num, $desc, $month, $year, $amount, $paid, $due, $status],
            'issiiddss'
        );
    };

    /** Create a CUSTOM (non-rent) invoice and return its id. month/year are NULL. */
    $mkCustomInvoice = function (int $tid, string $desc, float $amount, string $status, float $paid, ?string $due = null) {
        $num = 'CUS-' . $tid . '-' . strtoupper(bin2hex(random_bytes(3)));
        return db_insert(
            "INSERT INTO invoices (tenant_id, invoice_number, type, description, month, year, amount, amount_paid, due_date, status)
             VALUES (?,?,'CUSTOM',?,NULL,NULL,?,?,?,?)",
            [$tid, $num, $desc, $amount, $paid, $due, $status],
            'issddss'
        );
    };


    /** Record a completed payment against an invoice. */
    $mkPayment = function (int $tid, int $iid, float $amount, string $status, string $method, int $daysAgo) {
        $ref = 'RMS-SEED-' . strtoupper(bin2hex(random_bytes(4)));
        $when = date('Y-m-d H:i:s', strtotime("-{$daysAgo} days"));
        db_exec(
            'INSERT INTO payments (tenant_id, invoice_id, amount, currency, merchant_reference, order_tracking_id,
                payment_method, confirmation_code, status, transaction_date)
             VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$tid, $iid, $amount, 'KES', $ref, 'SEED-' . strtoupper(bin2hex(random_bytes(3))),
             $method, 'CONF' . random_int(100000, 999999), $status, $when],
            'iidsssssss'
        );
    };

    // ---- John: LOW risk (paid up) ----
    $invJ1 = $mkInvoice($john, $thisMonth, $thisYear, 15000, 'PAID', 15000);
    $mkPayment($john, $invJ1, 15000, 'COMPLETED', 'M-PESA', 5);
    // A settled custom (non-rent) charge — demonstrates CUSTOM invoices without affecting risk.
    $invJ2 = $mkCustomInvoice($john, 'Water connection fee', 2500, 'PAID', 2500, date('Y-m-10'));
    $mkPayment($john, $invJ2, 2500, 'COMPLETED', 'M-PESA', 30);

    // ---- Mary: MODERATE risk (one partial invoice) ----
    $invM1 = $mkInvoice($mary, $thisMonth, $thisYear, 20000, 'PARTIAL', 18000);
    $mkPayment($mary, $invM1, 18000, 'COMPLETED', 'CARD', 8);
    $mkPayment($mary, $invM1, 500, 'FAILED', 'M-PESA', 3);

    // ---- Peter: HIGH risk (two unpaid invoices + failed attempts) ----
    $invP1 = $mkInvoice($peter, $thisMonth, $thisYear, 18000, 'UNPAID', 0);
    $prevMonth = $thisMonth === 1 ? 12 : $thisMonth - 1;
    $prevYear  = $thisMonth === 1 ? $thisYear - 1 : $thisYear;
    $invP2 = $mkInvoice($peter, $prevMonth, $prevYear, 18000, 'UNPAID', 0);
    $mkPayment($peter, $invP1, 18000, 'FAILED', 'M-PESA', 2);
    $mkPayment($peter, $invP1, 18000, 'FAILED', 'M-PESA', 12);
    $mkPayment($peter, $invP2, 18000, 'FAILED', 'M-PESA', 20);

    db_exec(
        "UPDATE invoices SET email_sent_at = NOW() WHERE tenant_id IN (?,?,?) AND type='RENT'",
        [$john, $mary, $peter],
        'iii'
    );

    say('Invoices & payments created to demonstrate LOW / MODERATE / HIGH arrears risk.');
    say('');
    say('Seed complete. You can now log in:');
    say('  Sign in at index.php using admin / Admin@123 or tenant phone 0700000001 / Tenant@123');
} catch (Throwable $ex) {
    say('Seed FAILED: ' . $ex->getMessage());
    say('Make sure schema.sql has been imported first and DB credentials in config/app.php are correct.');
}

out();
