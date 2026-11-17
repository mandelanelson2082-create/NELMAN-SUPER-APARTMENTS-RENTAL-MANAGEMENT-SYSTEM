<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/pesapal.php';

/**
 * Shared payment lifecycle logic used by both the IPN callback and the
 * browser-return verification page. Never trusts the browser: it re-queries
 * Pesapal (or the simulated store) and only then updates local records.
 */

/** Find a pending/existing payment row by merchant reference or tracking id. */
function find_payment(?string $merchantRef, ?string $trackingId): ?array
{
    if ($trackingId) {
        $p = db_one('SELECT * FROM payments WHERE order_tracking_id = ? LIMIT 1', [$trackingId], 's');
        if ($p) {
            return $merchantRef === null || hash_equals((string) $p['merchant_reference'], $merchantRef)
                ? $p
                : null;
        }
        if ($merchantRef !== null) {
            return null;
        }
    }
    if ($merchantRef) {
        $p = db_one('SELECT * FROM payments WHERE merchant_reference = ? LIMIT 1', [$merchantRef], 's');
        if ($p) {
            return $p;
        }
    }
    return null;
}

/**
 * Reconcile a payment against the gateway and persist the verified outcome.
 * Returns the updated payment row (or null if not found).
 */
function reconcile_payment(array $payment): ?array
{
    $tracking = $payment['order_tracking_id'];
    if (!$tracking) {
        return $payment;
    }

    $client = new PesapalClient();
    $st = $client->transactionStatus((string) $tracking);
    $newStatus = $st['status'] ?? 'PENDING';

    if ($newStatus === 'COMPLETED') {
        if (!is_numeric($st['amount'] ?? null)
            || round((float) $st['amount'], 2) !== round((float) $payment['amount'], 2)) {
            throw new RuntimeException('Pesapal payment amount does not match the stored order.');
        }
        if (strtoupper((string) ($st['currency'] ?? '')) !== strtoupper((string) $payment['currency'])) {
            throw new RuntimeException('Pesapal payment currency does not match the stored order.');
        }
    }

    $wasCompleted = $payment['status'] === 'COMPLETED';

    // A COMPLETED record that the gateway does not confirm means the local
    // row was tampered with. Undo its invoice allocations instead of trusting it.
    if ($wasCompleted && $newStatus !== 'COMPLETED') {
        error_log(sprintf(
            'Payment %d was COMPLETED locally but Pesapal reports %s; reversing allocations.',
            (int) $payment['id'],
            (string) $newStatus
        ));
        db()->begin_transaction();
        try {
            $touchedInvoices = db_all(
                'SELECT DISTINCT invoice_id FROM payment_allocations WHERE payment_id = ?',
                [$payment['id']],
                'i'
            );
            if ($payment['invoice_id']) {
                $touchedInvoices[] = ['invoice_id' => (int) $payment['invoice_id']];
            }
            db_exec('DELETE FROM payment_allocations WHERE payment_id = ?', [$payment['id']], 'i');
            db_exec(
                'UPDATE payments SET status = ?, payment_method = COALESCE(?, payment_method),
                    confirmation_code = ?, raw_response = ? WHERE id = ?',
                [
                    $newStatus,
                    $st['payment_method'] ?? null,
                    $st['confirmation_code'] ?? null,
                    json_encode($st['raw'] ?? [], JSON_UNESCAPED_SLASHES),
                    $payment['id'],
                ],
                'ssssi'
            );
            foreach ($touchedInvoices as $row) {
                recalc_invoice((int) $row['invoice_id']);
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollback();
            throw $ex;
        }
        return db_one('SELECT * FROM payments WHERE id = ?', [$payment['id']], 'i');
    }

    if (!($wasCompleted && $newStatus === 'COMPLETED')) {
        $raw = json_encode($st['raw'] ?? [], JSON_UNESCAPED_SLASHES);
        db_exec(
            'UPDATE payments SET status = ?, payment_method = COALESCE(?, payment_method),
                confirmation_code = COALESCE(?, confirmation_code),
                payment_account = COALESCE(?, payment_account),
                transaction_date = COALESCE(transaction_date, NOW()), raw_response = ?
             WHERE id = ?',
            [
                $newStatus,
                $st['payment_method'] ?? null,
                $st['confirmation_code'] ?? null,
                $st['payment_account'] ?? null,
                $raw,
                $payment['id'],
            ],
            'sssssi'
        );
    }

    if ($newStatus === 'COMPLETED') {
        if ($payment['invoice_id']) {
            recalc_invoice((int) $payment['invoice_id']);
        } else {
            apply_tenant_credit((int) $payment['tenant_id']);
        }
        ensure_payment_receipt_pdf((int) $payment['id']);
    }

    return db_one('SELECT * FROM payments WHERE id = ?', [$payment['id']], 'i');
}

/** Record an admin-entered cash payment and allocate it in the ledger atomically. */
function record_cash_payment(int $tenantId, int $invoiceId, string $category, string $amount): int
{
    $category = strtoupper(trim($category));
    if ($tenantId <= 0 || $invoiceId <= 0 || !in_array($category, ['RENT', 'CUSTOM'], true)) {
        throw new RuntimeException('Select a tenant, payment category, and outstanding invoice.');
    }
    if (!is_numeric($amount) || (float) $amount <= 0 || (float) $amount > 9999999999.99) {
        throw new RuntimeException('Enter a valid cash payment amount.');
    }
    $amount = round((float) $amount, 2);

    db()->begin_transaction();
    try {
        $invoice = db_one(
            "SELECT id, tenant_id, type, amount, amount_paid
               FROM invoices
              WHERE id = ? AND tenant_id = ? AND type = ? AND status IN ('UNPAID','PARTIAL')
              FOR UPDATE",
            [$invoiceId, $tenantId, $category],
            'iis'
        );
        if (!$invoice) {
            throw new RuntimeException('The selected invoice is not an outstanding invoice for that tenant and category.');
        }

        $tenant = get_tenant($tenantId);
        if (!$tenant) {
            throw new RuntimeException('The selected tenant could not be found.');
        }

        $merchantReference = generate_merchant_reference();
        $rawResponse = json_encode([
            'source' => 'admin_cash_payment',
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'category' => $category,
            'recorded_by_admin_id' => (int) (current_admin()['id'] ?? 0),
        ], JSON_UNESCAPED_SLASHES);
        if ($rawResponse === false) {
            throw new RuntimeException('Could not prepare the cash payment audit record.');
        }

        $paymentId = db_insert(
            'INSERT INTO payments
                (tenant_id, invoice_id, amount, currency, merchant_reference, payment_method,
                 payment_account, status, transaction_date, raw_response)
             VALUES (?,NULL,?,?,?,?,?,?,NOW(),?)',
            [
                $tenantId,
                $amount,
                DEFAULT_CURRENCY,
                $merchantReference,
                'CASH - ' . $category,
                $tenant['phone'],
                'COMPLETED',
                $rawResponse,
            ],
            'idssssss'
        );

        allocate_tenant_credit($tenantId, $invoiceId, $paymentId);
        db()->commit();
    } catch (Throwable $ex) {
        db()->rollback();
        throw $ex;
    }

    ensure_payment_receipt_pdf($paymentId);
    return $paymentId;
}

/**
 * Generate a private PDF receipt for a completed payment.
 * Uses WeasyPrint when available, with a headless browser fallback.
 */
function generate_payment_receipt_pdf(int $paymentId): string
{
    $payment = db_one('SELECT * FROM payments WHERE id = ? LIMIT 1', [$paymentId], 'i');
    if (!$payment || $payment['status'] !== 'COMPLETED') {
        throw new RuntimeException('A receipt is available only for a completed payment.');
    }

    $receiptDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'rental_system_receipts';
    if (!is_dir($receiptDirectory)
        && !mkdir($receiptDirectory, 0700, true)
        && !is_dir($receiptDirectory)) {
        throw new RuntimeException('Could not create the private receipt storage directory.');
    }
    $receiptPath = $receiptDirectory . DIRECTORY_SEPARATOR . 'receipt_v9_' . $paymentId . '.pdf';
    if (is_valid_receipt_pdf($receiptPath)) {
        return $receiptPath;
    }
    if (is_file($receiptPath)) {
        if (!unlink($receiptPath)) {
            throw new RuntimeException('Could not replace the invalid stored receipt.');
        }
    }

    $tenant = db_one(
        'SELECT t.*, h.id AS receipt_house_id, h.property_name AS receipt_property_name
           FROM tenants t
           LEFT JOIN houses h ON h.id = t.house_id
          WHERE t.id = ? LIMIT 1',
        [(int) $payment['tenant_id']],
        'i'
    );
    if (!$tenant) {
        throw new RuntimeException('The tenant record for this payment could not be found.');
    }

    $allocations = db_all(
        'SELECT a.amount, i.invoice_number, i.type, i.description, i.month, i.year
           FROM payment_allocations a
           JOIN invoices i ON i.id = a.invoice_id
          WHERE a.payment_id = ?
          ORDER BY i.due_date, i.created_at, i.id',
        [$paymentId],
        'i'
    );
    $allocatedAmount = 0.0;
    $rows = '';
    foreach ($allocations as $allocation) {
        $amount = (float) $allocation['amount'];
        $allocatedAmount += $amount;
        $description = trim((string) ($allocation['description'] ?? ''));
        if ($allocation['type'] === 'RENT' && $allocation['month'] && $allocation['year']) {
            $description = 'Rent Charges - ' . date(
                'F Y',
                mktime(0, 0, 0, (int) $allocation['month'], 1, (int) $allocation['year'])
            );
        }
        if ($description === '') {
            $description = $allocation['type'] === 'RENT' ? 'Rent Charges' : 'Invoice payment';
        }
        if (!empty($tenant['receipt_house_id'])) {
            $description .= ' (House ' . $tenant['receipt_house_id'] . ')';
        }
        $rows .= '<tr><td>' . e($allocation['invoice_number'])
            . '</td><td>' . e($description) . '</td><td class="amount">'
            . e($payment['currency'] . ' ' . number_format($amount, 2)) . '</td></tr>';
    }

    if ($payment['invoice_id'] && !$allocations) {
        $invoice = get_invoice((int) $payment['invoice_id']);
        $description = trim((string) ($invoice['description'] ?? ''));
        if (($invoice['type'] ?? '') === 'RENT' && !empty($invoice['month']) && !empty($invoice['year'])) {
            $description = 'Rent Charges - ' . date(
                'F Y',
                mktime(0, 0, 0, (int) $invoice['month'], 1, (int) $invoice['year'])
            );
        }
        if ($description === '') {
            $description = ($invoice['type'] ?? '') === 'RENT' ? 'Rent Charges' : 'Invoice payment';
        }
        if (!empty($tenant['receipt_house_id'])) {
            $description .= ' (House ' . $tenant['receipt_house_id'] . ')';
        }
        $rows .= '<tr><td>' . e($invoice['invoice_number'] ?? 'Direct invoice payment')
            . '</td><td>' . e($description) . '</td><td class="amount">'
            . e($payment['currency'] . ' ' . number_format((float) $payment['amount'], 2))
            . '</td></tr>';
        $allocatedAmount = (float) $payment['amount'];
    }

    $creditAmount = max(0.0, round((float) $payment['amount'] - $allocatedAmount, 2));
    if ($creditAmount > 0) {
        $rows .= '<tr><td>CREDIT</td><td>Advance Payment / Retained Overpayment Credit</td><td class="amount">'
            . e($payment['currency'] . ' ' . number_format($creditAmount, 2)) . '</td></tr>';
    }

    $transactionDate = $payment['transaction_date'] ?: $payment['created_at'];
    $transactionTimestamp = strtotime((string) $transactionDate);
    if ($transactionTimestamp === false) {
        throw new RuntimeException('The payment transaction date is invalid.');
    }
    $receiptNumber = 'REC-' . date('Y', $transactionTimestamp) . '-'
        . str_pad((string) $paymentId, 5, '0', STR_PAD_LEFT);
    $propertyName = trim((string) ($tenant['receipt_property_name'] ?? ''));
    $residence = '';
    if (!empty($tenant['receipt_house_id'])) {
        $residence = 'Apartment ' . $tenant['receipt_house_id'];
        if ($propertyName !== '') {
            $residence .= ', ' . $propertyName;
        }
    }
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>
        @page { size: 80mm __PAGE_HEIGHT_MM__mm; margin: 3mm; }
        * { box-sizing: border-box; }
        html, body { width: 74mm; max-width: 74mm; margin: 0; padding: 0; }
        body { font-family: Arial, sans-serif; color: #293447; font-size: 8px; line-height: 1.4; }
        table { width: 100%; border-collapse: collapse; border-spacing: 0; table-layout: fixed; }
        .receipt { width: 100%; max-width: 100%; border: 0; border-radius: 0; padding: 0; overflow-wrap: anywhere; word-break: break-word; }
        header { border-bottom: 1px solid #39465d; padding-bottom: 8px; margin-bottom: 9px; text-align: center; }
        header > table, header > table > tbody, header > table > tbody > tr { display: block; width: 100%; }
        header > table > tbody > tr > td { display: block; width: 100%; padding: 0; }
        h1 { color: #3478bd; font-size: 15px; line-height: 1.3; margin: 6px 0; }
        h2 { color: #4a5568; font-size: 8px; letter-spacing: .4px; margin: 0 0 5px; text-transform: uppercase; }
        .brand { font-size: 13px; line-height: 1.35; font-weight: bold; }
        .muted { color: #7a8aa0; overflow-wrap: anywhere; }
        .receipt-meta { margin-top: 7px; text-align: left; line-height: 1.5; }
        .receipt-meta h1 { text-align: center; }
        .receipt-meta table { width: 100%; margin: 0; border-collapse: collapse; table-layout: fixed; }
        .receipt-meta td { padding: 2px 0; overflow-wrap: anywhere; }
        .meta-label { font-weight: bold; color: #4a5568; }
        .meta-value { font-family: monospace; word-break: break-all; }
        .status { display: inline-block; border-radius: 9px; padding: 1px 6px; background: #c6f6d5; color: #22543d; font-size: 7px; font-weight: bold; }
        .details { display: block; width: 100%; margin-bottom: 10px; }
        .details tbody, .details tr, .details td { display: block; width: 100%; }
        .details td { padding: 0; line-height: 1.5; overflow-wrap: anywhere; }
        .details h2 { border-bottom: 1px solid #e1e6eb; padding-bottom: 3px; margin-top: 8px; font-size: 8px; }
        table.items, table.summary { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .items thead { display: none; }
        .items tbody, .items tr, .items td { display: block; width: 100%; }
        .items td { border-bottom: 0; padding: 2px 3px; text-align: left; overflow-wrap: anywhere; word-break: break-word; }
        .items tr { padding: 4px 0; border-bottom: 1px solid #dfe5ec; break-inside: avoid; }
        .items td:nth-child(1) { font-weight: bold; }
        .items td:nth-child(1)::before { content: "Invoice: "; color: #4a5568; font-weight: normal; }
        .items td:nth-child(2)::before { content: "Description: "; color: #4a5568; font-weight: bold; }
        .items .amount, .summary .amount { text-align: right; white-space: normal; overflow-wrap: anywhere; }
        .items td:nth-child(3)::before { content: "Allocated: "; color: #4a5568; font-weight: normal; }
        .bottom-grid { display: block; width: 100%; border-collapse: collapse; margin-top: 10px; }
        .bottom-grid > tbody, .bottom-grid > tbody > tr, .bottom-grid > tbody > tr > td { display: block; width: 100%; padding: 0; }
        .policy { margin: 0; padding: 7px; background: #f3f6f9; border-left: 2px solid #4299e1; line-height: 1.45; overflow-wrap: anywhere; break-inside: avoid; }
        .policy strong { display: block; margin-bottom: 3px; }
        .summary { margin: 8px 0 0; }
        .summary td { width: 50%; border: 0; padding: 4px 2px; overflow-wrap: anywhere; }
        .summary td:first-child { text-align: left; color: #65758b; }
        .summary .grand-total td { border-top: 2px solid #d2dbe5; border-bottom: 2px solid #d2dbe5; font-weight: bold; font-size: 10px; color: #293447; }
        .summary .grand-total .amount { color: #3478bd; font-size: 10px; }
        footer { border-top: 1px solid #dfe5ec; margin-top: 12px; padding-top: 7px; text-align: center; color: #7a8aa0; line-height: 1.5; font-size: 7px; overflow-wrap: anywhere; }
        footer .powered { margin-top: 3px; font-size: 6px; }
    </style></head><body><div class="receipt">
        <header><table style="width:100%"><tr><td>
            <div class="brand">PRIME RENTAL<br>ENTERPRISES</div>
            <div class="muted">123 Business Avenue, Nairobi, Kenya</div>
            <div class="muted">billing@primerentals.co.ke | +254 712 345678</div>
        </td><td class="receipt-meta">
            <h1>PAYMENT RECEIPT</h1>
            <table>
                <tr><td class="meta-label">Receipt No:</td><td class="meta-value">' . e($receiptNumber) . '</td></tr>
                <tr><td class="meta-label">Date:</td><td class="meta-value">' . e(date('Y-m-d H:i', $transactionTimestamp)) . '</td></tr>
                <tr><td class="meta-label">Status:</td><td><span class="status">COMPLETED</span></td></tr>
            </table>
        </td></tr></table></header>
        <table class="details"><tr><td>
            <h2>Received from</h2>
            <strong>' . e($tenant['name']) . '</strong><br>
            ' . ($residence !== '' ? e($residence) . '<br>' : '') . '
            Phone: ' . e($tenant['phone']) . '<br>
            Email: ' . e($tenant['email'] ?? '—') . '
        </td><td>
            <h2>Payment gateway details</h2>
            Channel: Pesapal API 3.0<br>
            Method: ' . e($payment['payment_method'] ?? 'M-PESA') . '<br>
            Txn Reference: ' . e($payment['confirmation_code'] ?? '—') . '<br>
            Tracking ID: ' . e($payment['order_tracking_id'] ?? '—') . '
        </td></tr></table>
        <table class="items"><thead><tr><th>Item / No.</th><th>Description</th><th class="amount">Amount Allocation</th></tr></thead>
        <tbody>' . $rows . '</tbody></table>
        <table class="bottom-grid"><tr>
            <td><div class="policy"><strong>System Allocation Policy Note:</strong><br>
                Payments clear the oldest invoices first. Any excess is held as rent credit and applied to future invoices.
            </div></td>
            <td><table class="summary">
                <tr><td>Invoice Settled:</td><td class="amount"><strong>' . e($payment['currency'] . ' ' . number_format($allocatedAmount, 2)) . '</strong></td></tr>
                <tr><td>Credit Generated:</td><td class="amount"><strong>' . e($payment['currency'] . ' ' . number_format($creditAmount, 2)) . '</strong></td></tr>
                <tr class="grand-total"><td>Total Paid:</td><td class="amount">' . e($payment['currency'] . ' ' . number_format((float) $payment['amount'], 2)) . '</td></tr>
            </table></td>
        </tr></table>
        <footer>
            <div>Thank you for your prompt payment. Your account stands balanced.</div>
            <div>This is a system-generated electronic receipt voucher. No physical signature is required.</div>
            <div class="powered">Powered by Pesapal v3 Engine &bull; Confidential Property Transaction Ledger Document</div>
        </footer>
    </div></body></html>';

    // Chromium needs a concrete custom page height, so size the roll from its wrapped text.
    $receiptText = preg_replace('~<style\b[^>]*>.*?</style>~is', '', $html);
    $receiptText = preg_replace(
        '~<(?:br\b[^>]*|/(?:div|tr|td|h[1-6]|section|header|footer|p)\b[^>]*)>~i',
        "\n",
        $receiptText
    );
    $receiptText = html_entity_decode(strip_tags($receiptText), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $estimatedLines = 0;
    foreach (preg_split('/\R+/', $receiptText) as $line) {
        $estimatedLines += max(1, (int) ceil(strlen(trim($line)) / 60));
    }
    $pageHeightMm = min(600, max(110, (int) ceil(20 + $estimatedLines * 3.2)));
    $html = str_replace('__PAGE_HEIGHT_MM__', (string) $pageHeightMm, $html);

    if (!function_exists('exec')) {
        throw new RuntimeException('PDF generation is unavailable because PHP execution is disabled.');
    }

    $htmlTempPath = tempnam($receiptDirectory, 'receipt_html_');
    if ($htmlTempPath === false) {
        throw new RuntimeException('Could not create a temporary receipt file.');
    }
    $htmlPath = $htmlTempPath . '.html';
    if (!rename($htmlTempPath, $htmlPath)) {
        unlink($htmlTempPath);
        throw new RuntimeException('Could not prepare the temporary HTML receipt file.');
    }

    $pdfPath = tempnam($receiptDirectory, 'receipt_pdf_');
    if ($pdfPath === false) {
        unlink($htmlPath);
        throw new RuntimeException('Could not create a temporary PDF file.');
    }

    $keepPdf = false;
    try {
        if (file_put_contents($htmlPath, $html, LOCK_EX) === false) {
            throw new RuntimeException('Could not write the temporary receipt document.');
        }

        $weasyprint = (string) env('WEASYPRINT_BINARY', 'weasyprint');
        $command = escapeshellarg($weasyprint) . ' '
            . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
        $rendererOutput = [];
        exec($command, $rendererOutput, $exitCode);
        $rendered = $exitCode === 0 && is_valid_receipt_pdf($pdfPath);
        $rendererErrors = [trim(implode("\n", $rendererOutput))];

        if (!$rendered) {
            $browserBinaries = receipt_browser_binaries();
            if (!$browserBinaries) {
                $rendererErrors[] = 'No supported headless browser was found.';
            }

            foreach ($browserBinaries as $browserBinary) {
                if (is_file($pdfPath) && !unlink($pdfPath)) {
                    throw new RuntimeException('Could not prepare the PDF output file for the browser renderer.');
                }

                $profilePath = $receiptDirectory . DIRECTORY_SEPARATOR . 'browser_profile_' . bin2hex(random_bytes(8));
                if (!mkdir($profilePath, 0700)) {
                    throw new RuntimeException('Could not create a temporary browser profile for PDF generation.');
                }

                $arguments = [
                    '--headless',
                    '--disable-gpu',
                    '--no-first-run',
                    '--no-default-browser-check',
                    '--disable-extensions',
                    '--no-pdf-header-footer',
                    '--user-data-dir=' . $profilePath,
                    '--print-to-pdf=' . $pdfPath,
                    $htmlPath,
                ];
                if (PHP_OS_FAMILY !== 'Windows') {
                    $arguments[] = '--no-sandbox';
                }
                $command = escapeshellarg($browserBinary) . ' '
                    . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
                $browserOutput = [];
                try {
                    exec($command, $browserOutput, $browserExitCode);
                    $rendered = $browserExitCode === 0 && is_valid_receipt_pdf($pdfPath);
                    $rendererErrors[] = trim(implode("\n", $browserOutput));
                } finally {
                    remove_receipt_profile($profilePath);
                }

                if ($rendered) {
                    break;
                }
            }
        }

        if (!$rendered) {
            $detail = trim(implode("\n", array_filter($rendererErrors)));
            throw new RuntimeException(
                'Could not generate the PDF receipt. Install WeasyPrint or a supported headless browser.'
                . ($detail !== '' ? ' Renderer output: ' . substr($detail, 0, 1000) : '')
            );
        }

        if (!rename($pdfPath, $receiptPath)) {
            throw new RuntimeException('Could not store the generated PDF receipt.');
        }
        $keepPdf = true;
        return $receiptPath;
    } finally {
        if (is_file($htmlPath)) {
            unlink($htmlPath);
        }
        if (!$keepPdf && is_file($pdfPath)) {
            unlink($pdfPath);
        }
    }
}

/** Check that a renderer produced a non-empty PDF rather than an error page or empty file. */
function is_valid_receipt_pdf(string $path): bool
{
    if (!is_file($path) || filesize($path) < 5) {
        return false;
    }

    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return false;
    }
    $header = fread($handle, 5);
    fclose($handle);

    return $header === '%PDF-';
}

/** Return configured and common browser executables for the current platform. */
function receipt_browser_binaries(): array
{
    $configured = env('PDF_BROWSER_BINARY');
    $binaries = $configured !== null ? [$configured] : [];

    if (PHP_OS_FAMILY === 'Windows') {
        $programFiles = getenv('ProgramFiles') ?: 'C:\\Program Files';
        $programFilesX86 = getenv('ProgramFiles(x86)') ?: 'C:\\Program Files (x86)';
        $binaries = array_merge($binaries, [
            $programFilesX86 . '\\Microsoft\\Edge\\Application\\msedge.exe',
            $programFiles . '\\Microsoft\\Edge\\Application\\msedge.exe',
            $programFiles . '\\Google\\Chrome\\Application\\chrome.exe',
            $programFilesX86 . '\\Google\\Chrome\\Application\\chrome.exe',
        ]);
        return array_values(array_filter(array_unique($binaries), 'is_file'));
    }

    return array_values(array_unique(array_merge($binaries, [
        'chromium',
        'chromium-browser',
        'google-chrome',
        'google-chrome-stable',
    ])));
}

/** Remove only the uniquely named temporary browser profile created for a receipt render. */
function remove_receipt_profile(string $path): void
{
    if (!is_dir($path) || basename($path) === '' || !str_starts_with(basename($path), 'browser_profile_')) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

/** Generate the receipt without allowing renderer failures to undo payment confirmation. */
function ensure_payment_receipt_pdf(int $paymentId): void
{
    try {
        generate_payment_receipt_pdf($paymentId);
    } catch (Throwable $ex) {
        error_log('Automatic PDF receipt generation failed for payment ' . $paymentId . ': ' . $ex->getMessage());
    }
}
