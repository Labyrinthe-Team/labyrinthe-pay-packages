# LabPay .NET SDK

Official .NET SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel Money,
Orange Money) in the DR Congo, in CDF and USD. Covers the **API V2** (the V1 API is not covered).

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0), not compiled in this first draft.** The code, tests and examples were written without a
> .NET SDK available and have **not been built nor run yet**. Run `dotnet build` and `dotnet test` (below) before any
> use, and report what breaks. Not published on NuGet.

- `Labyrinthe.LabPay`: `netstandard2.0` and `net8.0`, `HttpClient`, `System.Text.Json` (no reflection: trimming / AOT
  friendly on `net8.0`), async API with `CancellationToken`.
- `Labyrinthe.LabPay.AspNetCore` (`net8.0`): `services.AddLabPay(configuration)` (typed client of
  `IHttpClientFactory`, `Microsoft.Extensions.Logging`) and `app.MapLabPayWebhooks()`, a minimal API endpoint that
  verifies signed notifications.

## Install

```bash
dotnet add package Labyrinthe.LabPay              # once published
dotnet add package Labyrinthe.LabPay.AspNetCore   # ASP.NET Core integration
```

Until then, reference the projects of `src/` (`<ProjectReference>`), or `dotnet pack` them locally.

## Quick start (test mode)

```csharp
using Labyrinthe.LabPay;

using var labpay = new LabPayClient(new LabPayClientOptions { ApiKey = "test_…" }); // default: LABPAY_API_KEY

var payment = await labpay.Deposits.CreateAsync(new MobileMoneyRequest
{
    Amount = 1000m,                 // net amount credited to you; the customer pays it + fees (decimal: exact)
    Currency = "CDF",
    Phone = "0813456789",           // sandbox number: success
    Reference = "ORDER-42",         // unique per token: a resend returns the same payment
    CallbackUrl = "https://shop.example/webhooks/labpay",
});
Console.WriteLine($"{payment.Id} {payment.Status}"); // DEP-01JCX… Pending
```

`LabPayClient` is thread-safe: create one and reuse it (or let `AddLabPay` manage it).

## API coverage

| SDK | Endpoint |
|---|---|
| `Deposits.CreateAsync(request)` | `POST /V2/deposits` |
| `Payouts.CreateAsync(request)` / `Payouts.CancelAsync(id)` | `POST /V2/payouts`, `POST /V2/payouts/{id}/cancel` |
| `Refunds.CreateAsync(new RefundRequest { PaymentId, Amount? })` | `POST /V2/refunds` |
| `Payments.GetAsync(id)` / `Payments.SyncAsync(id)` | `GET /V2/payments/{id}`, `POST /V2/payments/{id}/sync` |
| `Payments.ListAsync(filters)` / `IterateAsync(filters)` (`await foreach`) / `FindByReferenceAsync(ref)` | `GET /V2/payments` |
| `Providers.CountriesAsync()` / `Providers.ListAsync(operation, country)` | `GET /V2/countries`, `GET /V2/providers` |
| `Providers.AvailabilityAsync(country)` / `Providers.PredictAsync(phone, country)` | `GET /V2/availability`, `POST /V2/predict-provider` |
| `Account.GetAsync()` / `Account.BalancesAsync()` / `Account.BalanceAsync("CDF")` | `GET /V2/account`, `GET /V2/balances` |
| `Account.RotateWebhookSecretAsync()` / `Account.RotateTestKeyAsync()` | `POST /V2/account/webhook-secret/rotate`, `POST /V2/account/test-key/rotate` |
| `Notifications.ListAsync(filters)` / `Notifications.ResendAsync(id)` | `GET /V2/notifications`, `POST /V2/notifications/{id}/resend` |
| `Webhook.ConstructEvent(rawBody, header, secret)` | notifications sent to your `callback_url` |

Models are immutable records (`Payment`, `Balance`, `Account`, `Country`, `Provider`, `Notification`, `Page<T>`…);
statuses are enums with an `Unknown` value for values added later (`ToApiString()` gives the API value); the raw
payment stays in `payment.Raw`. Response amounts are **strings** (`"1500.00"`) — `payment.AmountValue` /
`balance.Value` give an exact `decimal`; request amounts are `decimal` (at most 2 decimals), never `double`.

## Statuses, idempotency, retries

- `Pending` → `Completed` or `Failed` (final): `IsCompleted`, `IsFailed`, `IsFinal`, `IsQueued` (payout `ENQUEUED`,
  cancellable). **Deliver only on `Completed`.**
- A payment refused at initiation (422 with the payment) is **returned** with the `Failed` status and `Failure`, not
  thrown. A 202 is a `Pending` payment.
- Without `Reference`, the SDK generates `sdk_<uuid>` (`AutoReference = false` to disable) so that a deposit, payout or
  refund is retried after a network error or a 5xx **without risk of a double payment**.
- Retries (`MaxRetries = 2`): backoff with jitter on reads and payments with reference; `429` of the rate limiter after
  `Retry-After` (≤ `MaxRetryWait`); maintenance-type `503` only after a `Retry-After`; never `DAILY_LIMIT_REACHED`.
  Rotations, resends, cancellations and payments without reference are never retried after a network error or a 5xx.
- A `ConnectionException` (`CONNECTION_ERROR` / `TIMEOUT`) on a payment does **not** mean it failed: resend the same
  reference or call `Payments.FindByReferenceAsync()`. Cancelling your `CancellationToken` throws an
  `OperationCanceledException` and is never retried.

