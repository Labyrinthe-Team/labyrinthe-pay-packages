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
