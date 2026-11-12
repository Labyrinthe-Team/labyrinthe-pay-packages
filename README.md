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

