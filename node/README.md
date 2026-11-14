# LabPay Node.js / TypeScript SDK

Official SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel Money,
Orange Money, MTN MoMo…) across Africa, in every country open on the platform and in its currency (CDF, USD, XAF, XOF…). Covers the **API V2** (the V1 API is not covered).

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
- Retries (`maxRetries: 2`): backoff with jitter on reads and payments with reference; `429` of the rate limiter after
  `Retry-After` (≤ `maxRetryWaitMs`); maintenance-type `503` only after a `Retry-After`; never `DAILY_LIMIT_REACHED`.
  Rotations, resends, cancellations and payments without reference are never retried after a network error or a 5xx.
- A `ConnectionError` (`CONNECTION_ERROR` / `TIMEOUT`) on a payment does **not** mean it failed: resend the same
  reference or call `payments.findByReference()`.

## Errors

All errors extend `LabPayError` (`code`, `status`). API errors extend `ApiError` (`errors` per field, `headers`,
`retryAfter`, `mode`): `AuthenticationError` (401), `PermissionError` (403: `TOKEN_BLOCKED`, `MERCHANT_INACTIVE`,
`KYC_REQUIRED`, `LIVE_TOKEN_REQUIRED`), `NotFoundError` (404), `ConflictError` (409), `InvalidRequestError` (422) with
`ValidationError`, `InvalidPhoneNumberError`, `AmountOutOfRangeError`, `InsufficientBalanceError`, `RateLimitError` /
`DailyLimitReachedError` (429), `ServiceUnavailableError` (5xx). SDK errors: `ConnectionError`, `AbortedError`,
`InvalidArgumentError`, `UnexpectedResponseError`, `SignatureVerificationError`, `InvalidWebhookPayloadError`.
Switch on `code`, never on `message` (in French).

## Notifications (webhooks)

```ts
import { WEBHOOK_HEADERS, Webhooks } from '@labyrinthe/labpay';

// Next.js App Router: app/api/webhooks/labpay/route.ts
export async function POST(request: Request) {
  const rawBody = await request.text();                       // RAW body (Express: express.raw())
  const event = await Webhooks.constructEvent(rawBody, request.headers.get(WEBHOOK_HEADERS.signature), process.env.LABPAY_WEBHOOK_SECRET!);
  if (event.event === 'payment.completed') {
    // deliver event.payment.reference once (deduplicate on X-Labyrinthe-Delivery or payment.id)
  }
  return new Response(null, { status: 200 });                 // within 10 seconds
}
```

`SignatureVerificationError` / `InvalidWebhookPayloadError` → answer 400. Every `v1` of `X-Labyrinthe-Signature`
(HMAC-SHA256 of `"<t>.<raw body>"`) is compared in constant time, the timestamp must be within 300 s, and an array of
secrets is accepted during a rotation. `Webhooks.sign(body, secret)` builds a header for your tests.

## Configuration

```ts
new LabPay({
  apiKey: 'test_…',                                  // or LABPAY_API_KEY
  baseUrl: 'https://api.labyrinthe-rdc.com/api',     // or LABPAY_BASE_URL; "/V2" is added
  timeoutMs: 30_000,
  maxRetries: 2,
  maxRetryWaitMs: 10_000,
  autoReference: true,
  fetch: customFetch,                                // optional
});
```

`labpay.lastResponse` gives the status and headers of the last response (`mode` = `X-Labyrinthe-Mode`).

## Development

```bash
npm install
npm test             # vitest, simulated fetch, OpenAPI contract
npm run typecheck    # strict TypeScript (sources, tests, examples)
npm run build        # tsup: dist/index.js (ESM), dist/index.cjs (CJS), .d.ts
npm run check:dist   # imports the built package (ESM and CJS)
```

Examples: [`examples/`](examples). Test numbers: see the [PHP README](../php/README.md#test-numbers-sandbox).
