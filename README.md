# Web-Based Rental Management System

A PHP + MySQL rental management platform with multi-channel online payments via
**Pesapal API 3.0** and a transparent, rule-based **arrears risk indicator**, built
to the specification in *MENTORSHIP LETTER.docx* (Project Proposal).

## Features

- **Two secure portals** — Administrator/Manager and Tenant (separate sessions & access control), reached through one role-detecting sign-in page.
- **Admin password changes** — authenticated admins can change their password from the sidebar after verifying their current password.
- **Multi-property directory** — group house units under buildings/properties, with per-property
  occupancy and a financial breakdown (units, monthly expectation, collected, arrears) on the dashboard.
- **House management** — VARCHAR house IDs (H1, A101, BLOCK-A-01…), rent amounts, VACANT/OCCUPIED status,
  optional property assignment and per-property filtering.
- **Tenant management** — registration with hashed passwords, phone/email login, and an
  **initial / must-change password** flow (provisioned tenants are forced to set a new password on first login).
- **Tenant email verification** — tenants created with an email receive a 48-hour activation link and cannot sign in until verified; tenants without email retain phone/password sign-in.
- **House allocation** — availability is verified before allocation; house flips to OCCUPIED atomically.
- **Invoices** — automatic monthly **rent** generation for every active tenant with a positive rent amount (idempotent, unique `(tenant, month, year)`) plus ad-hoc
  **custom** invoices (water, parking, maintenance, penalties…) with no billing period; statuses UNPAID/PARTIAL/PAID/CANCELLED.
- **Invoice email notices** — newly generated rent invoices are emailed to active tenants with verified email addresses and include the same invoice totals/status shown in the tenant portal.
- **Payments (Pesapal API 3.0)** — order submission, unique merchant reference, redirect to checkout,
  IPN/callback + server-side re-verification, invoice auto-update on success. No PIN/card data is ever stored.
- **Pesapal payment reconciliation** — admins can re-query Pesapal for the status of an existing order,
  including when a tenant completes the M-Pesa payment outside the initial STK prompt.
- **In-app Pesapal configuration** — set mode/keys/URLs from **Admin → Pesapal** (stored in the `settings`
  table, overriding env). Both the consumer key and secret are masked in the UI and only overwritten when re-entered.
- **Payment history** — for both admins (with tenant filtering) and tenants; admins can record cash paid against a selected rent or custom-charge invoice, with FIFO allocation of any remainder.
- **Payment receipts** — authenticated PDF receipts are available for completed payments from the payment status and history pages (uses WeasyPrint or an available headless browser).
- **Arrears monitoring & risk** — LOW / MODERATE / HIGH (green / yellow / red) with action buttons.
- **Reports** — revenue, arrears, occupancy, payments (Chart.js visualizations, printable).
- **Cron** — scheduled monthly invoice generation.
- **SIMULATE mode** — a local mock Pesapal checkout so the *entire* payment flow is testable in XAMPP
  without live credentials.


## Tech stack (per proposal §3.9)

| Layer     | Technology                                  |
|-----------|---------------------------------------------|
| Frontend  | HTML5, CSS3, Bootstrap 5, Bootstrap Icons, Chart.js |
| Backend   | PHP 8.0+ (sessions, MySQLi, cURL), prepared statements (8.1+ recommended) |
| Database  | MySQL / MariaDB (`rentals`)                 |
| Payments  | Pesapal API 3.0                             |
| Mail      | SMTP via PHPMailer                           |
| Dev env   | XAMPP (Apache + MySQL + PHP)                |

## Project structure

```
rental_system/
├── admin/        login, logout, dashboard, properties, houses, tenants, invoices,
│                 payments, arrears, reports, pesapal_config
├── tenant/       login, logout, dashboard, pay, payment_status, history, change_password
│                 activate
├── payment/      pesapal.php (client), service.php, create.php, callback.php, receipt.php, simulate_checkout.php
├── reports/      index.php (routes into admin reports)
├── cron/         generate_monthly.php
├── config/       app.php, db.php, auth.php
├── includes/     functions.php (risk engine, settings, helpers), invoicing.php, mailer.php
├── partials/     header, footer, admin_nav, tenant_nav
├── database/     schema.sql (fresh install), upgrade.sql, mailing_upgrade.sql, seed.php
├── assets/css/   app.css
└── index.php     landing / portal chooser
```


## Setup (XAMPP)

1. **Install XAMPP** and start **Apache** and **MySQL**.
2. **Copy the project** into the web root:
   `C:\xampp\htdocs\rental_system`
3. **Create the schema** — import `database/schema.sql` via phpMyAdmin, or:
   ```bash
   mysql -u root < database/schema.sql
   ```
   > Upgrading an existing (non-enterprise) install? Run `database/upgrade.sql` instead —
   > it adds `properties`, `settings`, invoice `type`/`description`, `tenants.must_change_password`,
   > and backfills a default property for existing houses. It is idempotent.
   > For an existing installation, also run `database/mailing_upgrade.sql` to add verification and invoice-email tracking. Existing tenant email addresses are treated as verified to preserve access.

4. **Seed sample data** (creates admins, 3 properties, houses, tenants, runtime settings,
   and demo invoices/payments — including a custom invoice — showing LOW / MODERATE / HIGH risk):
   ```bash
   php database/seed.php
   ```
   …or open `http://localhost/rental_system/database/seed.php` in the browser.

5. **Sign in**: `http://localhost/rental_system/`. Use an admin username or a tenant phone/email with the account password; the system routes each account to its portal.

On Windows/XAMPP, run `install-startup-task.cmd` as Administrator after opening the app once.
It registers XAMPP to start at sign-in and schedules monthly rent invoice generation for
06:00 on the first day of each month.

### Email setup

Install Composer dependencies with `composer install`, then configure mail either in-app or via
environment variables:

- **In-app (recommended):** sign in as admin and open **Email** in the sidebar. Enter the SMTP
  host, port, encryption, credentials, sender address, and app URL, then use **Send Test Email**.
  These values are stored in the `settings` table and override the environment defaults.
- **Environment:** set the `SMTP_*`, `MAIL_FROM_*`, and `APP_URL` variables from `.env.example`
  as real server environment variables (PHP does not load `.env` automatically).

Set the app URL to the public app root (including `/rental_system` if deployed in a subfolder)
so emailed activation and invoice links are absolute. Web-triggered mail falls back to the
current request URL when it is unset, but CLI/cron sends require a configured app URL.
Tenant creation still succeeds if delivery is temporarily unavailable; the account remains
pending verification and an admin can use **Resend activation** on the tenant list. Failed
invoice emails remain pending and are retried on the next invoice-generator run. The manual
**Run now** action can retry them.

### Default logins (from the seeder)

| Portal | Credentials                     |
|--------|---------------------------------|
| Admin  | `admin` / `Admin@123`           |
| Manager| `manager` / `Manager@123`       |
| Tenant | `0700000001` / `Tenant@123` (LOW risk)   |
| Tenant | `0700000002` / `Tenant@123` (MODERATE)   |
| Tenant | `0700000003` / `Tenant@123` (HIGH)       |

> Change or remove these before any real deployment.

## Configuration

All settings live in `config/app.php` and can be overridden by environment
variables (see `.env.example`). PHP does **not** read `.env` automatically — set real
environment variables (Apache `SetEnv`, php-fpm env) or edit the defaults for local dev.

### Pesapal modes

Configuration is read from the `settings` table (seeded to **simulate**), which overrides the
env/`config/app.php` defaults. Edit it in-app under **Admin → Pesapal** — no redeploy needed.

- **`simulate` (default)** — no external calls. Paying an invoice opens a local mock
  checkout where you choose *Complete / Fail / Cancel*. The same verify → update →
  invoice-recalc path runs, so the whole flow is demonstrable offline.
- **`sandbox` / `live`** — set the consumer key/secret, `ipn_url` (→ `payment/callback.php`)
  and `redirect_url` (→ `tenant/payment_status.php`) in Admin → Pesapal (or via
  `PESAPAL_CONSUMER_KEY` / `PESAPAL_CONSUMER_SECRET` / `PESAPAL_IPN_URL` / `PESAPAL_REDIRECT_URL`).
  Both endpoints must be **publicly reachable over HTTPS**. If the mode is sandbox/live but the
  key or secret is blank, the system safely falls back to simulate. On the first real checkout,
  the callback URL is registered with Pesapal and the resulting IPN notification ID is persisted
  in `settings`; subsequent checkouts reuse it. Changing the callback URL triggers a new
  registration. The integration uses Pesapal's v3 `Transactions` and `URLSetup` endpoints and
  verifies completed payment amount/currency before crediting the tenant.

> The consumer key and secret are masked (`••••••••••••`) in the UI and are only overwritten when you type a
> new value. Storing live secrets in the database is a convenience trade-off — for production, prefer
> environment variables and restrict database access.

### Tenant passwords

Newly provisioned tenants can be flagged **must-change-password** (checkbox on Admin → Tenants, default on).
Such tenants are redirected to `tenant/change_password.php` after login and cannot reach other pages until
they set a new password. The **key** icon on a tenant row resets their password to their phone number and
re-arms the must-change flag.


## Payment flow

```
Tenant Dashboard → Pay Rent → payment/create.php
  → validate tenant + invoice (belongs to tenant, UNPAID/PARTIAL)
  → generate unique merchant_reference → INSERT payment (PENDING)
  → PesapalClient::submitOrder() → store order_tracking_id
  → redirect to Pesapal (or simulate_checkout.php)
        ↓ tenant pays
  → Pesapal IPN → payment/callback.php  AND  browser return → tenant/payment_status.php
  → reconcile_payment(): re-query gateway status (never trust the browser)
  → UPDATE payment → recalc_invoice() → dashboards update
  → completed payment → authenticated PDF receipt download
```

### PDF receipts

Install WeasyPrint and ensure its `weasyprint` command is on the PATH used by
PHP. If it is installed under a different executable name or path, set the
`WEASYPRINT_BINARY` environment variable. On Windows, installed Edge/Chrome
browsers are used automatically if WeasyPrint is unavailable; set
`PDF_BROWSER_BINARY` to select a different browser executable. Receipts are
generated automatically when a payment is confirmed, stored outside the
public web root, and streamed through an authenticated endpoint. If rendering
is unavailable at confirmation time, it is logged and a later download attempt
will retry generation.

## Arrears risk rules (proposal §1.14)

Evaluated HIGH first, then MODERATE, else LOW:

- **HIGH (red)** — arrears > 1× monthly rent, **or** ≥ 2 unpaid/partial invoices, **or** ≥ 3 failed payments in 6 months.
- **MODERATE (yellow)** — arrears > 25% of rent, **or** ≥ 1 unpaid/partial invoice, **or** ≥ 1 failed payment in 6 months.
- **LOW (green)** — none of the above.

Each row shows the triggering reasons and an action button (View History / Review / Review Payments)
linking to that tenant's filtered payment history. The indicator is decision-support only — it never
acts on a tenant automatically.

## Cron (monthly invoices)

The generator creates rent invoices for every active tenant with a positive rent amount,
whether or not a house is currently assigned or marked occupied. It does not charge tenants
or initiate Pesapal payments. It is safe to run more than once for the same month: existing
tenant/month invoices are skipped. The admin invoice page’s “Run now” action is only an
optional manual backfill or retry; routine generation is scheduled.

```cron
0 6 1 * *  php /path/to/rental_system/cron/generate_monthly.php
```
Optional overrides: `... generate_monthly.php year=2026 month=9`.

CLI runs need no credentials. If you trigger the generator over HTTP instead, set the
`CRON_TOKEN` environment variable and pass it as `?token=...` or the `X-Cron-Token`
header; when `CRON_TOKEN` is unset the endpoint refuses all web requests with 403.

On Windows, run `install-startup-task.cmd` as Administrator once to create the Task Scheduler
job. It runs the generator with XAMPP's PHP on the first day of each month at 06:00.

## Security notes (proposal §4.10)

- Passwords hashed with `password_hash()`; never stored in plain text.
- **All** SQL uses prepared statements (SQL-injection safe).
- CSRF tokens on every state-changing POST.
- Role-separated sessions; protected pages require a valid session.
- Tenant/invoice ownership checks before any payment.
- Pesapal payments are marked COMPLETED only after server-side gateway verification; callbacks are idempotent.
- Admins can re-query Pesapal for payments linked to an order tracking ID; a direct Paybill payment not linked to an order still needs reconciliation support from Pesapal.
- No M-Pesa PIN / card credentials are requested or stored.
- Put credentials in environment variables, not source control. Serve production over HTTPS.

## Production checklist

- [ ] PHP 8.0+ (8.1+ recommended), MySQL/MariaDB, cURL extension enabled
- [ ] HTTPS + domain; `PESAPAL_IPN_URL` / `PESAPAL_REDIRECT_URL` publicly reachable
- [ ] `PESAPAL_MODE=live` with production credentials
- [ ] `APP_ENV=production` (disables error display)
- [ ] Database user with least privilege; strong passwords
- [ ] Cron job installed for monthly invoicing
- [ ] Default seeded accounts removed

IPN registrations are cached separately for sandbox and live. If Pesapal rejects a
cached notification ID as invalid, the next checkout re-registers the configured
IPN URL once and retries the order submission.
