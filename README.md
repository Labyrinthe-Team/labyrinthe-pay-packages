# LabPay SDKs

Official integration packages for [LabPay](https://pay.labyrinthe-rdc.com), the Mobile Money aggregator of the DR
Congo (M-Pesa, Airtel Money, Orange Money; CDF and USD), operated by Labyrinthe Innovations SARL. Collect from a
customer, send money to a wallet, refund, and receive signed notifications — whatever your stack.

*Les README de chaque package existent aussi en français (`README.fr.md`).*

The SDKs cover the **API V2** (`https://api.labyrinthe-rdc.com/api/V2`). The legacy **V1 API is not covered**: new
integrations should use V2 (test key, idempotency, signed notifications). Documentation:
[docs.pay.labyrinthe-rdc.com](https://docs.pay.labyrinthe-rdc.com).

## Packages

| Folder | Package | Registry | Status |
|---|---|---|---|
| [`openapi/`](openapi) | OpenAPI 3.1 specification of the API V2 | — | 2.0.0 — source of truth |
| [`php/`](php) | `labyrinthe/labpay-php` | Packagist (not published yet) | 0.1.0 — preview |
| [`laravel/`](laravel) | `labyrinthe/labpay-laravel` | Packagist (not published yet) | 0.1.0 — preview |
| [`node/`](node) | `@labyrinthe/labpay` | npm (not published yet) | 0.1.0 — preview |
| [`python/`](python) | `labpay` (synchronous client) | PyPI (not published yet) | 0.1.0 — preview |
| [`wordpress/`](wordpress) | `labpay-for-woocommerce` (WooCommerce payment gateway) | wordpress.org (not published yet) | 0.1.0 — preview |
| [`postman/`](postman) | Postman collection of the API V2 (+ local / production environments) | — | 0.1.0 — preview |
| [`java/`](java) | `com.labyrinthe-rdc:labpay` + `labpay-spring-boot-starter` (Java 17+) | Maven Central (not published yet) | 0.1.0 — preview |
| [`dotnet/`](dotnet) | `Labyrinthe.LabPay` + `Labyrinthe.LabPay.AspNetCore` (netstandard2.0 / net8.0) | NuGet (not published yet) | 0.1.0 — draft, **not compiled yet** |
| — | Python asyncio client | — | planned |

## Five-line start (PHP)

```php
$labpay = new Labyrinthe\LabPay\Client('test_…');   // test key: sandbox, no real money
$payment = $labpay->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789', reference: 'ORDER-42');
echo $payment->status->value;                        // "pending" until the customer confirms with their PIN
```

## Five-line start (Node.js / TypeScript)

```ts
import { LabPay } from '@labyrinthe/labpay';

const labpay = new LabPay({ apiKey: 'test_…' });     // test key: sandbox, no real money
const payment = await labpay.deposits.create({ amount: '1000', currency: 'CDF', phone: '0813456789', reference: 'ORDER-42' });
console.log(payment.status);                         // "pending"
```

## Five-line start (Python)

```python
from labpay import LabPay

labpay = LabPay(api_key="test_…")                    # test key: sandbox, no real money
payment = labpay.deposits.create(amount="1000", currency="CDF", phone="0813456789", reference="ORDER-42")
print(payment.status)                                # pending
```

## Five-line start (Java)

```java
LabPayClient labpay = LabPayClient.builder().apiKey("test_…").build(); // test key: sandbox, no real money
Payment payment = labpay.deposits().create(
    MobileMoneyParams.builder().amount("1000").currency("CDF").phone("0813456789").reference("ORDER-42").build());
System.out.println(payment.status());                                  // pending
```

## Five-line start (.NET)

```csharp
using var labpay = new LabPayClient(new LabPayClientOptions { ApiKey = "test_…" }); // test key: sandbox
var payment = await labpay.Deposits.CreateAsync(new MobileMoneyRequest
    { Amount = 1000m, Currency = "CDF", Phone = "0813456789", Reference = "ORDER-42" });
Console.WriteLine(payment.Status);                                                  // Pending
```

## Principles shared by every SDK

- **The API contract is [`openapi/openapi.yaml`](openapi/openapi.yaml)**, derived from the payment engine code. SDK
  types, errors and webhook handling follow it; the Node contract test checks the spec against the SDK and the
  engine routes.
- **Same API in every language**: `deposits.create`, `payouts.create / cancel`, `refunds.create`,
  `payments.get / list / iterate / findByReference / sync`, `providers.countries / list / availability / predict`,
  `account.get / balances / balance / rotateWebhookSecret / rotateTestKey`, `notifications.list / resend`, webhook
  `constructEvent`, with names adapted to each language.
- **Never a double payment**: `reference` is the idempotency key of the API. The SDKs generate one (`sdk_<uuid>`) when
  you give none, so that a payment is only retried when replaying it is safe. Rotations, notification resends, payout
  cancellations and payments without reference are never retried after a network error or a 5xx.
- **A refused payment is a payment**: a 422 carrying the payment (refused at initiation) is returned with the
  `failed` status, not thrown; a 202 is a `pending` payment. A network error on a payment is never reported as a
  failure.
- **Typed errors** from the stable `code` of the API (then the HTTP status), with per-field validation errors; unknown
  codes stay readable. Maintenance-type refusals are only retried after a `Retry-After`, never in a loop.
- **Secure by default**: the key never reaches logs, dumps or exception messages; notifications are verified in
  constant time on the raw body (`X-Labyrinthe-Signature`, both secrets during a rotation), with replay protection.
- **Test mode first**: `test_…` keys use the sandbox; every example uses them.
- Minimal dependencies, SemVer, a CHANGELOG per package, MIT license.

## Repository layout

```
openapi/        OpenAPI 3.1 specification of the API V2 (common base of the SDKs)
php/            labyrinthe/labpay-php (PSR-18, Guzzle by default) — PHPUnit, PHPStan max
laravel/        labyrinthe/labpay-laravel (uses ../php through a Composer path repository) — Testbench, Larastan
node/           @labyrinthe/labpay (TypeScript, ESM + CJS via tsup, native fetch) — vitest, OpenAPI contract test
python/         labpay (httpx) — pytest, mypy --strict
wordpress/      LabPay for WooCommerce (bundles ../php prefixed with PHP-Scoper) — WordPress + WooCommerce test suite, PHPCS, PHPStan
postman/        Postman collection generated from openapi/openapi.yaml — node:test (spec coverage, Newman on a simulated API)
java/           com.labyrinthe-rdc:labpay (java.net.http, no dependency) + Spring Boot starter — JUnit 5, SpotBugs, OpenAPI contract
dotnet/         Labyrinthe.LabPay (HttpClient, System.Text.Json) + ASP.NET Core integration — xUnit, OpenAPI contract
CONTRIBUTING.md working on a package, changing the spec, releasing
```

## Support

contact@labyrinthe-rdc.com — security issues: please email rather than opening a public issue.
