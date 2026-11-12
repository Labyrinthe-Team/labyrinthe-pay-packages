# LabPay PHP SDK

Official PHP SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel Money,
Orange Money) in the DR Congo, in CDF and USD. Covers the **API V2** (the V1 API is not covered).

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0)** — not yet published on Packagist.

- PHP 8.1+, any PSR-18 HTTP client (Guzzle by default), PSR-3 logger.
- Typed models and exceptions, safe automatic retries, idempotency through `reference`, notification signature check.

## Install

```bash
composer require labyrinthe/labpay-php
```

## Quick start (test mode)

```php
use Labyrinthe\LabPay\Client;

$labpay = new Client('test_…');   // test key: sandbox, no real money, separate balances

$payment = $labpay->deposits->create(
    amount: '1000',                // net amount credited to you; the customer pays it + fees
    currency: 'CDF',
    phone: '0813456789',           // sandbox number: success
    reference: 'ORDER-42',         // unique per token: a resend returns the same payment
    callbackUrl: 'https://shop.example/webhooks/labpay',
);

echo $payment->id;                 // "DEP-01JCX…"
echo $payment->status->value;      // "pending" until the customer confirms with their PIN
```

Going live only means switching to the live token. `Client::fromEnv()` reads `LABPAY_API_KEY` (and `LABPAY_BASE_URL`).

## API coverage

| SDK | Endpoint |
|---|---|
| `$labpay->deposits->create(...)` | `POST /V2/deposits` |
| `$labpay->payouts->create(...)` | `POST /V2/payouts` |
| `$labpay->payouts->cancel($id)` | `POST /V2/payouts/{id}/cancel` |
| `$labpay->refunds->create(paymentId: …, amount: …)` | `POST /V2/refunds` |
| `$labpay->payments->get($id)` | `GET /V2/payments/{id}` |
| `$labpay->payments->sync($id)` | `POST /V2/payments/{id}/sync` |
| `$labpay->payments->list(status: …, operation: …, from: …)` / `iterate(...)` / `findByReference(...)` | `GET /V2/payments` |
| `$labpay->providers->countries()` | `GET /V2/countries` |
| `$labpay->providers->list(operation: …, country: …)` | `GET /V2/providers` |
| `$labpay->providers->availability($country)` | `GET /V2/availability` |
| `$labpay->providers->predict($phone, $country)` | `POST /V2/predict-provider` |
| `$labpay->account->balances()` / `balance('CDF')` | `GET /V2/balances` |
| `$labpay->account->get()` | `GET /V2/account` |
| `$labpay->account->rotateWebhookSecret()` | `POST /V2/account/webhook-secret/rotate` |
| `$labpay->account->rotateTestKey()` | `POST /V2/account/test-key/rotate` |
| `$labpay->notifications->list(...)` / `resend($id)` | `GET /V2/notifications`, `POST /V2/notifications/{id}/resend` |
| `Webhook::constructEvent(...)` | notifications sent to your `callback_url` |

## Payment statuses

`pending` → `completed` or `failed` (final). `$payment->isCompleted()`, `isFailed()`, `isFinal()`, `isQueued()` (payout
`ENQUEUED`, cancellable). **Deliver only on `completed`**, received by a signed notification or read with
`payments->get()` — never on the creation response alone.

- A payment refused at initiation (HTTP 422 **with** the payment) is **returned** with `status: failed` and `failure`
  (`$payment->failure->code`), not thrown: it was recorded, with its id.
- An HTTP 202 (unknown aggregator answer) is a `pending` payment, not a failure.
- Amounts are strings with 2 decimals (`"1035.00"`); pass `amount` as a string (`"12.50"`) or an int.

## Idempotency and retries

- `reference` is unique per token and mode: sending a payment again with a known reference returns the existing one.
  When you give none, the SDK generates `sdk_<uuid>` (disable with `autoReference: false`), so that it can retry a
  deposit, payout or refund after a network error or a 5xx **without risk of a double payment**.
- Automatic retries (`maxRetries: 2`): exponential backoff with jitter on reads and on payments that carry a
  reference; `429` of the rate limiter after `Retry-After` (≤ `maxRetryWait`); `503` maintenance-type refusals
  (`MAINTENANCE`, `DEPOSITS_DISABLED`…) only after a `Retry-After`. `DAILY_LIMIT_REACHED` is never retried.
- Never retried after a network error or a 5xx (the request may have been processed): rotations, notification
  resend, payout cancellation, payments without reference.
- A `ConnectionException` on a payment does **not** mean it failed: resend with the same reference, or use
  `$labpay->payments->findByReference('ORDER-42')`.

## Errors

Every exception implements `LabPayException`. API errors extend `ApiException` (`getErrorCode()`, `getStatusCode()`,
`getErrors()` for validation, `getRetryAfter()`, `getMode()`). Switch on the code, never on the message (in French).

| Exception | Codes |
|---|---|
| `AuthenticationException` | `UNAUTHENTICATED` (401) |
| `PermissionException` | `TOKEN_BLOCKED`, `MERCHANT_INACTIVE`, `KYC_REQUIRED`, `LIVE_TOKEN_REQUIRED` (403) |
| `NotFoundException` | `PAYMENT_NOT_FOUND`, `DEPOSIT_NOT_FOUND`, `NOTIFICATION_NOT_FOUND` (404) |
| `ConflictException` | `NOT_A_PAYOUT`, `NOT_CANCELLABLE`, `DEPOSIT_NOT_COMPLETED`, `REFUND_IN_PROGRESS`, `DEPOSIT_ALREADY_REFUNDED` (409) |
| `ValidationException` ⊂ `InvalidRequestException` | `VALIDATION_ERROR` (422) |
| `InvalidPhoneNumberException` ⊂ `InvalidRequestException` | `INVALID_PHONE_NUMBER` |
| `AmountOutOfRangeException` ⊂ `InvalidRequestException` | `AMOUNT_TOO_SMALL`, `AMOUNT_TOO_LARGE`, `AMOUNT_OUT_OF_BOUNDS` |
| `InsufficientBalanceException` ⊂ `InvalidRequestException` | `INSUFFICIENT_BALANCE` |
| `InvalidRequestException` | `INVALID_CURRENCY`, `UNSUPPORTED_COUNTRY`, `UNSUPPORTED_PROVIDER`, `INVALID_AMOUNT` |
| `RateLimitException` / `DailyLimitReachedException` | `TOO_MANY_REQUESTS` (set by the SDK) / `DAILY_LIMIT_REACHED` (429) |
| `ServiceUnavailableException` | `MAINTENANCE`, `API_DISABLED`, `DEPOSITS_DISABLED`, `WITHDRAWALS_DISABLED`, `PROVIDER_TEMPORARILY_UNAVAILABLE`, `GATEWAY_UNAVAILABLE`, `V2_DISABLED`, `TEST_MODE_DISABLED`, 5xx |
| `ConnectionException` | network error or timeout (`isTimeout()`) |
| `InvalidArgumentException` | invalid input, detected before any request |

Unknown codes are thrown as a plain `ApiException` (or the class of their HTTP status).

## Notifications (webhooks)

```php
use Labyrinthe\LabPay\Webhook\Webhook;

$event = Webhook::constructEvent(
    file_get_contents('php://input'),                 // RAW body
    $_SERVER['HTTP_X_LABYRINTHE_SIGNATURE'] ?? '',
    getenv('LABPAY_WEBHOOK_SECRET'),                  // whsec_… (GET /V2/account with the live token)
);                                                     // SignatureVerificationException → answer 400

if ($event->isPaymentCompleted()) {
    // deliver $event->payment->reference, once (deduplicate on X-Labyrinthe-Delivery or $event->payment->id)
}
http_response_code(200);                               // within 10 seconds
```

The signature is `t=<timestamp>,v1=<hex>` where `v1` = HMAC-SHA256 of `"<timestamp>.<raw body>"`. Every `v1` is
checked in constant time (for 24 h after a rotation LabPay sends two), and the timestamp must be within 300 s. Pass an
array of secrets during your own rotation. `Webhook::sign($body, $secret)` builds a header to test your endpoint.

## Configuration

```php
new Client(
    apiKey: 'test_…',
    baseUrl: 'https://api.labyrinthe-rdc.com/api',   // default; "/V2" is added
    timeout: 30.0,
    maxRetries: 2,
    maxRetryWait: 10.0,
    autoReference: true,
    httpClient: $psr18Client,                         // optional
    logger: $psr3Logger,                              // never receives the key or query values
);
```

`$labpay->lastResponse()` gives the status and headers of the last response (`->mode()` = `X-Labyrinthe-Mode`).

## Test numbers (sandbox)

| Provider | Success | Failures (collection) |
|---|---|---|
| Vodacom M-Pesa | `243813456789` | `…019` limit, `…029` unknown, `…039` not approved, `…049` insufficient balance |
| Airtel Money | `243973456789` | `…069` failure |
| Orange Money | `243893456789` | `…029` unknown, `…039` not approved, `…049` insufficient balance |

A number ending in `129` stays pending without a final status. Local format works too (`0813456789`).

## Development

```bash
composer install
vendor/bin/phpunit          # unit tests, simulated HTTP client (no network)
vendor/bin/phpstan analyse  # level max + strict rules
```

Examples: [`examples/`](examples). Specification: [`../openapi/openapi.yaml`](../openapi/openapi.yaml).
