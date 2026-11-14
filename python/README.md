# LabPay Python SDK

Official Python SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel
Money, Orange Money, MTN MoMo…) across Africa, in every country open on the platform and in its currency (CDF, USD, XAF, XOF…). Covers the **API V2** (the V1 API is not covered).

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0)** — not yet published on PyPI. Synchronous client only for now (an asyncio client is
> planned).

- Python 3.9+, [httpx](https://www.python-httpx.org/), fully typed (`py.typed`, mypy `--strict`).

## Install

```bash
pip install labpay
```

## Quick start (test mode)

```python
from labpay import LabPay

with LabPay(api_key="test_…") as labpay:        # test key: sandbox, no real money (default: LABPAY_API_KEY)
    payment = labpay.deposits.create(
        amount="1000",                           # net amount credited to you; the customer pays it + fees
        currency="CDF",
        phone="0813456789",                      # sandbox number: success
        reference="ORDER-42",                    # unique per token: a resend returns the same payment
        callback_url="https://shop.example/webhooks/labpay",
    )
    print(payment.id, payment.status)            # DEP-01JCX… pending
```

## API coverage

| SDK | Endpoint |
|---|---|
| `deposits.create(...)` | `POST /V2/deposits` |
| `payouts.create(...)` / `payouts.cancel(id)` | `POST /V2/payouts`, `POST /V2/payouts/{id}/cancel` |
| `refunds.create(payment_id=…, amount=…)` | `POST /V2/refunds` |
| `payments.get(id)` / `payments.sync(id)` | `GET /V2/payments/{id}`, `POST /V2/payments/{id}/sync` |
| `payments.list(...)` / `payments.iterate(...)` / `payments.find_by_reference(ref)` | `GET /V2/payments` |
| `providers.countries()` / `providers.list(operation=…, country=…)` | `GET /V2/countries`, `GET /V2/providers` |
| `providers.availability(country=…)` / `providers.predict(phone, country=…)` | `GET /V2/availability`, `POST /V2/predict-provider` |
| `account.get()` / `account.balances()` / `account.balance("CDF")` | `GET /V2/account`, `GET /V2/balances` |
| `account.rotate_webhook_secret()` / `account.rotate_test_key()` | `POST /V2/account/webhook-secret/rotate`, `POST /V2/account/test-key/rotate` |
| `notifications.list(...)` / `notifications.resend(id)` | `GET /V2/notifications`, `POST /V2/notifications/{id}/resend` |
| `webhooks.construct_event(raw_body, header, secret)` (`from labpay import webhooks`) | notifications sent to your `callback_url` |

## Statuses, idempotency, retries

- `pending` → `completed` or `failed` (final): `payment.is_completed`, `is_failed`, `is_final`, `is_queued`.
  **Deliver only on `completed`.**
- A payment refused at initiation (422 with the payment) is **returned** with `PaymentStatus.FAILED` and `failure`,
  not raised. A 202 is a `pending` payment.
- Amounts are strings (`"1035.00"`); pass `amount` as `str`, `int` or `Decimal` (floats are refused).
- Without `reference`, the SDK generates `sdk_<uuid>` (`auto_reference=False` to disable) so that a deposit, payout or
  refund is retried after a network error or a 5xx **without risk of a double payment**.
- Retries (`max_retries=2`): backoff with jitter on reads and payments with reference; `429` of the rate limiter after
  `Retry-After`; maintenance-type `503` only after a `Retry-After`; never `DAILY_LIMIT_REACHED`. Rotations, resends,
  cancellations and payments without reference are never retried after a network error or a 5xx.
- An `APIConnectionError` / `APITimeoutError` on a payment does **not** mean it failed: resend the same reference.

## Errors

`LabPayError` (`code`, `status`, `message`) → `ApiError` (`errors`, `headers`, `retry_after`, `mode`) →
`AuthenticationError`, `PermissionDeniedError`, `NotFoundError`, `ConflictError`, `InvalidRequestError`
(`ValidationError`, `InvalidPhoneNumberError`, `AmountOutOfRangeError`, `InsufficientBalanceError`), `RateLimitError`
(`DailyLimitReachedError`), `ServiceUnavailableError`. SDK errors: `APIConnectionError`, `APITimeoutError`,
`InvalidArgumentError`, `UnexpectedResponseError`, `SignatureVerificationError`, `InvalidWebhookPayloadError`.

## Notifications (webhooks)

```python
from labpay import InvalidWebhookPayloadError, SignatureVerificationError, webhooks

@app.post("/webhooks/labpay")                                   # Flask
def labpay_webhook():
    try:
        event = webhooks.construct_event(request.get_data(), request.headers.get(webhooks.SIGNATURE_HEADER), SECRET)
    except (SignatureVerificationError, InvalidWebhookPayloadError):
        abort(400)
    if event.is_payment_completed:
        ...  # deliver event.payment.reference once (X-Labyrinthe-Delivery or payment.id)
    return "", 200                                              # within 10 seconds
```

Every `v1` of `X-Labyrinthe-Signature` (HMAC-SHA256 of `"<t>.<raw body>"`) is compared in constant time, the
timestamp must be within 300 s, and a list of secrets is accepted during a rotation. `webhooks.sign()` builds a header
for your tests.

## Development

```bash
python -m venv .venv && .venv/Scripts/pip install -r requirements-dev.txt   # (bin/ on Linux/macOS)
.venv/Scripts/python -m pytest
.venv/Scripts/python -m mypy
```

Examples: [`examples/`](examples). Test numbers: see the [PHP README](../php/README.md#test-numbers-sandbox).
