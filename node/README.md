# LabPay Node.js / TypeScript SDK

Official SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel Money,
Orange Money) in the DR Congo, in CDF and USD. Covers the **API V2** (the V1 API is not covered).

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0)** — not yet published on npm.

- Node.js 18+, native `fetch`, no runtime dependency; ESM and CommonJS builds with type declarations.
- The core uses only Fetch and Web Crypto: it also runs on Bun, Deno and edge runtimes (server-side only — never ship
  your key to a browser).

## Install

```bash
npm install @labyrinthe/labpay
```

## Quick start (test mode)

```ts
import { LabPay } from '@labyrinthe/labpay';

const labpay = new LabPay({ apiKey: 'test_…' }); // test key: sandbox, no real money (default: LABPAY_API_KEY)

const payment = await labpay.deposits.create({
  amount: '1000',            // net amount credited to you; the customer pays it + fees
  currency: 'CDF',
  phone: '0813456789',       // sandbox number: success
  reference: 'ORDER-42',     // unique per token: a resend returns the same payment
  callbackUrl: 'https://shop.example/webhooks/labpay',
});
console.log(payment.id, payment.status); // "DEP-01JCX…" "pending"
```

## API coverage

| SDK | Endpoint |
|---|---|
| `deposits.create(params)` | `POST /V2/deposits` |
| `payouts.create(params)` / `payouts.cancel(id)` | `POST /V2/payouts`, `POST /V2/payouts/{id}/cancel` |
| `refunds.create({ paymentId, amount? })` | `POST /V2/refunds` |
| `payments.get(id)` / `payments.sync(id)` | `GET /V2/payments/{id}`, `POST /V2/payments/{id}/sync` |
| `payments.list(filters)` / `payments.iterate(filters)` / `payments.findByReference(ref)` | `GET /V2/payments` |
| `providers.countries()` / `providers.list({ operation, country })` | `GET /V2/countries`, `GET /V2/providers` |
| `providers.availability({ country })` / `providers.predict({ phone, country })` | `GET /V2/availability`, `POST /V2/predict-provider` |
| `account.get()` / `account.balances()` / `account.balance('CDF')` | `GET /V2/account`, `GET /V2/balances` |
| `account.rotateWebhookSecret()` / `account.rotateTestKey()` | `POST /V2/account/webhook-secret/rotate`, `POST /V2/account/test-key/rotate` |
| `notifications.list(filters)` / `notifications.resend(id)` | `GET /V2/notifications`, `POST /V2/notifications/{id}/resend` |
| `Webhooks.constructEvent(rawBody, header, secret)` | notifications sent to your `callback_url` |

Responses are mapped to camelCase objects (`totalAmount`, `gatewayStatus`…); the raw payment stays in `payment.raw`.
Every method accepts `{ signal }` (AbortSignal) as last argument.

## Statuses, idempotency, retries

- `pending` → `completed` or `failed` (final): `isCompleted(p)`, `isFailed(p)`, `isFinal(p)`, `isQueued(p)` (payout
  `ENQUEUED`, cancellable). **Deliver only on `completed`.**
- A payment refused at initiation (422 with the payment) is **returned** with `status: 'failed'` and `failure`, not
  thrown. A 202 is a `pending` payment.
- Without `reference`, the SDK generates `sdk_<uuid>` (`autoReference: false` to disable) so that a deposit, payout or
  refund is retried after a network error or a 5xx **without risk of a double payment**.
