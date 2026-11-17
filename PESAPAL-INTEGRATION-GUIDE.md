# Guide for adding Pesapal payments to another system

This guide describes the Pesapal API 3.0 payment flow in this rental system and provides a prompt you can give another AI to adapt the integration to a different application. It is an implementation brief, not a drop-in code package: the other system's framework, data model, authentication, and payment rules must be inspected first.

## What the integration does

The application creates a local pending payment before contacting Pesapal, submits a hosted checkout order, and redirects the customer to Pesapal. After checkout, Pesapal sends an IPN and the customer's browser returns to the application. Neither notification is treated as proof of payment: the server queries Pesapal for the transaction's current status, checks the amount and currency, and only then updates local payment records.

The reference implementation is in:

- `payment/pesapal.php` — API client: authentication, submit order, status query, and IPN registration.
- `payment/create.php` — validates a tenant payment request, creates a pending record, submits the order, stores the tracking ID, and redirects.
- `payment/callback.php` — public IPN endpoint; locates the local payment and reconciles it with Pesapal.
- `tenant/payment_status.php` — authenticated browser-return page; checks ownership and also reconciles with Pesapal.
- `payment/service.php` — shared payment lookup and reconciliation logic.
- `admin/pesapal_config.php` — administrator settings UI.
- `config/app.php` — environment defaults and sandbox/live API base URLs.
- `database/schema.sql` — payment table and indexes.

## Payment lifecycle

1. **Validate the customer request.** Authenticate the customer, validate the amount and currency, and verify that the requested item/invoice belongs to that customer and can be paid. Apply CSRF protection to the payment-start form.
2. **Create a local payment record.** Generate a unique merchant reference and insert a `PENDING` payment before making the API call. Persist the expected amount and currency so they can be checked against Pesapal later.
3. **Register or reuse the IPN.** Register the public IPN URL using Pesapal's `RegisterIPN` endpoint. Persist its returned notification ID, associated with the environment and URL, and reuse it while those values remain unchanged.
4. **Submit the order.** Request an OAuth bearer token, then submit the order. Save Pesapal's `order_tracking_id` and raw response for support/audit needs. Redirect the customer to Pesapal's returned `redirect_url`.
5. **Receive the IPN and browser return.** The IPN is a public server-to-server endpoint and must not require a customer session. The browser return is a user-facing endpoint and should authenticate the customer and ensure the payment belongs to them.
6. **Re-query Pesapal from the server.** Use the saved tracking ID with `GetTransactionStatus`. Normalize the gateway status to the target system's payment states. Do not mark a payment successful because of query parameters, browser navigation, or an IPN's claimed status/amount.
7. **Validate and apply the result.** For a completed transaction, verify the returned amount and currency match the stored order. Update the payment and business records atomically where practical. Make reconciliation idempotent so duplicate IPNs and repeated browser returns cannot apply a payment twice.
8. **Acknowledge the IPN.** Respond using the acknowledgement format and HTTP behavior required by the current Pesapal API documentation. Log failures with enough context to troubleshoot, without logging credentials or exposing sensitive data.

## Pesapal API 3.0 details in this reference

The reference client uses these base URLs:

- Sandbox: `https://cybqa.pesapal.com/pesapalv3`
- Live: `https://pay.pesapal.com/v3`

It uses JSON requests and these paths:

| Purpose | Method and path |
|---|---|
| Request OAuth token | `POST /api/Auth/RequestToken` |
| Register an IPN URL | `POST /api/URLSetup/RegisterIPN` |
| Submit an order | `POST /api/Transactions/SubmitOrderRequest` |
| Query transaction status | `GET /api/Transactions/GetTransactionStatus?orderTrackingId=...` |

The reference order payload maps the system's merchant reference to Pesapal's `id` and includes `currency`, `amount`, `description`, `callback_url`, `notification_id`, and `billing_address` (`email_address`, `phone_number`, `first_name`, `last_name`). The order response is expected to include `order_tracking_id` and `redirect_url`.

The IPN registration request uses `url` and `ipn_notification_type` (`GET` in this implementation); the response's `ipn_id` is the notification ID supplied when submitting orders. The reference caches this ID by registered URL and API environment, and retries registration once if order submission reports an invalid IPN ID.

**Verify these paths, field names, status meanings, error codes, and acknowledgement requirements against the current official Pesapal API documentation before implementing.** Do not assume a copied API example still matches the current contract.

## Data the target system needs to persist

Adapt the target system's existing payment model if possible. At minimum, it needs durable fields for:

- Internal payment/customer/business-record IDs
- Expected amount and currency
- A unique merchant reference generated by the application
- Pesapal order tracking ID
- Local normalized status (for example `PENDING`, `COMPLETED`, `FAILED`, `CANCELLED`)
- Payment method and confirmation code, if returned
- Transaction timestamp
- Gateway response or safe diagnostic metadata, subject to the system's data-retention policy

Add a unique constraint for the merchant reference and an index for the tracking ID. Persist IPN registration metadata (environment, URL, notification ID) in the system's existing settings/configuration storage. Keep the consumer key and secret server-side; never include them in browser code or source control.

## Configuration and deployment

Use explicit modes such as `sandbox` and `live`; a local mock/simulate mode is useful during development but must never represent a real payment. The reference system reads credentials and endpoint URLs from environment/configuration, with optional database-backed runtime settings. If using a settings UI, mask secrets and do not overwrite a saved secret when the field is left unchanged.

For real payments:

- Use the correct sandbox or production credentials and matching API environment.
- Serve the application over HTTPS.
- Make the IPN and customer-return URLs public and reachable by Pesapal.
- Configure the exact deployed IPN and return URLs, including any application subpath.
- Ensure the server has outbound HTTPS access and the required HTTP client extension/library.
- Keep secrets out of logs, error pages, browser responses, and checked-in files.
- Use a least-privilege database account and production-safe error handling.

## Security and correctness requirements

- Never trust the browser return or IPN payload as the final payment result; query Pesapal server-to-server.
- Match the gateway's tracking ID (and merchant reference when available) to a payment created by this application. Reject mismatched identifiers.
- Check the verified amount and currency against the locally stored order before crediting or fulfilling anything.
- Enforce tenant/customer ownership on user-facing payment pages and validate the payable record before creating an order.
- Make state updates and business fulfillment idempotent. A repeated notification must not create duplicate credits, orders, receipts, or entitlements.
- Handle timeouts and API errors explicitly. A timeout after order submission may leave an order created remotely; preserve the local pending record and support safe reconciliation instead of blindly creating duplicate orders.
- Do not collect or store the customer's M-Pesa PIN or card credentials.
- Avoid returning stack traces, secrets, or raw provider responses to end users.

## What is specific to this rental application

The reference app associates payments with tenants and invoices, and supports advance rent as tenant credit that is allocated to invoices. Those are business rules, not Pesapal requirements. In another system, replace them with that system's own fulfillment/ledger operation (for example, activating an order only after verified completion). Do not copy invoice allocation, tenant roles, database tables, routes, or PHP helpers unless they fit the destination system.

The local simulate checkout is also optional. If implemented, it should be development-only, clearly separated from sandbox/live mode, and exercise the same persistence and reconciliation behavior without allowing simulated payments in production.

## Suggested implementation and verification

Ask the implementing AI to first inspect the destination system and identify its existing authentication, database/payment model, configuration, routing, logging, and test conventions. Then have it implement the integration in the smallest set of existing files/patterns, including:

1. Server-side Pesapal client with safe JSON/HTTP error handling.
2. Payment-start route and pending payment persistence.
3. Public IPN endpoint and authenticated customer-return route.
4. Shared, idempotent server-side reconciliation.
5. Environment-specific configuration and secret handling.
6. Focused tests using a mocked Pesapal client/HTTP transport.
7. Directly related setup documentation.

Before calling it complete, verify:

- Invalid amount, currency, customer ownership, or payable-record state is rejected.
- A valid request creates a pending payment and redirects to the provider URL.
- IPN and browser return both reconcile by querying the provider, not trusting supplied status.
- Completed status with a mismatched amount or currency does not fulfill the purchase.
- Repeated IPNs/returns do not apply the payment more than once.
- Failed, cancelled, pending, timeout, and malformed-provider responses are handled explicitly.
- Sandbox and live configuration cannot accidentally be mixed.
- The IPN and return URLs are reachable over HTTPS in the deployed environment.

## Copy/paste prompt for another AI

> Implement Pesapal API 3.0 payments in this existing application. First inspect the repository to understand its language/framework, routes, authentication and authorization, database/payment schema, configuration and secret-management approach, logging, and test conventions. Do not assume this app is the same as the reference rental system or copy its tenant/invoice/credit business rules.
>
> Use the current official Pesapal API documentation to verify the API 3.0 endpoints, request/response field names, status mappings, IPN acknowledgement format, and error behavior before coding. The intended lifecycle is: validate an authenticated customer's payment request and ownership; create a local `PENDING` payment with a unique merchant reference, expected amount, and currency; register/reuse the public IPN and persist its notification ID per URL/environment; submit the order with server-side credentials; store the returned tracking ID; redirect to Pesapal; on IPN and browser return, find the local payment and query Pesapal's transaction status server-to-server; verify completed amount/currency; then update and fulfill the target system's business operation exactly once.
>
> Keep credentials server-side and out of source control/logs/responses. Do not treat browser parameters or IPN claims as proof of payment. Make reconciliation idempotent, handle network/API failures and uncertain submission outcomes safely, and never store a PIN or card credentials. Preserve existing project patterns; add or reuse a development mock only if it can be kept out of production. Make surgical changes, update directly related setup documentation, add focused tests with a mocked provider, and run the narrowest relevant tests/lint/build checks. Report files changed, configuration and deployment steps, test results, and any values the operator must supply (credentials and public HTTPS URLs).
