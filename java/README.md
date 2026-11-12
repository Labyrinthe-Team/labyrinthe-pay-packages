# LabPay Java SDK

Official Java SDK for [LabPay](https://pay.labyrinthe-rdc.com): collect and send Mobile Money (M-Pesa, Airtel Money,
Orange Money) in the DR Congo, in CDF and USD. Covers the **API V2** (the V1 API is not covered).

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0)** — not yet published on Maven Central.

- `com.labyrinthe-rdc:labpay`: Java 17+, `java.net.http`, **no runtime dependency** (internal JSON reader, so no
  Jackson version is imposed on your application).
- `com.labyrinthe-rdc:labpay-spring-boot-starter`: Spring Boot 3 auto-configuration (`labpay.*` properties) and a
  signed notification endpoint publishing Spring application events.

## Install

```xml
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labpay</artifactId>
  <version>0.1.0</version>
</dependency>
<!-- or, in a Spring Boot 3 application -->
<dependency>
  <groupId>com.labyrinthe-rdc</groupId>
  <artifactId>labpay-spring-boot-starter</artifactId>
  <version>0.1.0</version>
</dependency>
```

Gradle: `implementation("com.labyrinthe-rdc:labpay:0.1.0")`. Until the first release, install it locally with
`./mvnw install` from this folder.

## Quick start (test mode)

```java
LabPayClient labpay = LabPayClient.builder().apiKey("test_…").build(); // test key: sandbox (default: LABPAY_API_KEY)

Payment payment = labpay.deposits().create(
    MobileMoneyParams.builder()
        .amount("1000")                // net amount credited to you; the customer pays it + fees
        .currency("CDF")
        .phone("0813456789")           // sandbox number: success
        .reference("ORDER-42")         // unique per token: a resend returns the same payment
        .callbackUrl("https://shop.example/webhooks/labpay")
        .build());
System.out.println(payment.id() + " " + payment.status()); // DEP-01JCX… pending
```

`LabPayClient` is thread-safe: create one per application and share it.

## API coverage

| SDK | Endpoint |
|---|---|
| `deposits().create(params)` | `POST /V2/deposits` |
| `payouts().create(params)` / `payouts().cancel(id)` | `POST /V2/payouts`, `POST /V2/payouts/{id}/cancel` |
| `refunds().create(RefundParams)` / `refunds().create(paymentId)` | `POST /V2/refunds` |
| `payments().get(id)` / `payments().sync(id)` | `GET /V2/payments/{id}`, `POST /V2/payments/{id}/sync` |
| `payments().list(filters)` / `iterate(filters)` / `stream(filters)` / `findByReference(ref)` | `GET /V2/payments` |
| `providers().countries()` / `providers().list(operation, country)` | `GET /V2/countries`, `GET /V2/providers` |
| `providers().availability(country)` / `providers().predict(phone, country)` | `GET /V2/availability`, `POST /V2/predict-provider` |
| `account().get()` / `account().balances()` / `account().balance("CDF")` | `GET /V2/account`, `GET /V2/balances` |
| `account().rotateWebhookSecret()` / `account().rotateTestKey()` | `POST /V2/account/webhook-secret/rotate`, `POST /V2/account/test-key/rotate` |
| `notifications().list(filters)` / `notifications().resend(id)` | `GET /V2/notifications`, `POST /V2/notifications/{id}/resend` |
| `Webhooks.constructEvent(rawBody, header, secret)` | notifications sent to your `callback_url` |

Models are immutable records (`Payment`, `Balance`, `Account`, `Country`, `Provider`, `Notification`, `Page<T>`…);
statuses are enums with an `UNKNOWN` value for values added later; the raw payment stays in `payment.raw()`.
Amounts are **strings** (`"1500.00"`) — `payment.amountValue()` / `balance.value()` give an exact `BigDecimal`; in
requests, pass a `String`, a `BigDecimal` or a `long`, never a `double`.

## Statuses, idempotency, retries

- `PENDING` → `COMPLETED` or `FAILED` (final): `isCompleted()`, `isFailed()`, `isFinal()`, `isQueued()` (payout
  `ENQUEUED`, cancellable). **Deliver only on `COMPLETED`.**
- A payment refused at initiation (422 with the payment) is **returned** with the `FAILED` status and `failure()`, not
  thrown. A 202 is a `PENDING` payment.
- Without `reference`, the SDK generates `sdk_<uuid>` (`autoReference(false)` to disable) so that a deposit, payout or
  refund is retried after a network error or a 5xx **without risk of a double payment**.
- Retries (`maxRetries(2)`): backoff with jitter on reads and payments with reference; `429` of the rate limiter after
  `Retry-After` (≤ `maxRetryWait`); maintenance-type `503` only after a `Retry-After`; never `DAILY_LIMIT_REACHED`.
  Rotations, resends, cancellations and payments without reference are never retried after a network error or a 5xx.
- A `ConnectionException` (`CONNECTION_ERROR` / `TIMEOUT`) on a payment does **not** mean it failed: resend the same
  reference or call `payments().findByReference()`.

## Errors

All exceptions extend `LabPayException` (`code()`, `statusCode()`, unchecked). API errors extend `ApiException`
(`errors()` per field, `headers()`, `retryAfter()`, `mode()`): `AuthenticationException` (401), `PermissionException`
