# Contributing

Thanks for helping! This folder holds every LabPay SDK. Public code and docblocks are in English; READMEs exist in
English and French; internal comments may be in French.

## Working on a package

```bash
cd php && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse
cd laravel && composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse   # uses ../php (path repository)
cd node && npm install && npm run typecheck && npm test && npm run build && npm run check:dist
cd python && python -m venv .venv && .venv/Scripts/pip install -r requirements-dev.txt -e . \
  && .venv/Scripts/python -m pytest && .venv/Scripts/python -m mypy          # bin/ instead of Scripts/ on Linux/macOS
cd wordpress && composer install && php bin/install-wp-tests.php && vendor/bin/phpunit \
  && vendor/bin/phpcs && vendor/bin/phpstan analyse --memory-limit=1G   # needs MySQL; uses ../php (prefixed copy)
cd postman && npm ci && npm test                                         # after a spec change: npm run generate
cd java && ./mvnw verify          # JDK 17+ (format check on JDK 21+; fix with ./mvnw spotless:apply), Maven via the wrapper
cd dotnet && dotnet build -c Release && dotnet test                      # .NET 8 SDK
```

Tests never hit the network: each SDK uses a simulated HTTP client (PSR-18 mock, `fetch` mock, `httpx.MockTransport`,
JDK `HttpServer` for Java, `HttpMessageHandler` for .NET). The Java and .NET contract tests also read
`openapi/openapi.yaml` (operations, error codes, enums).
Before a change is merged: tests green, static analysis clean, CHANGELOG updated under `[Unreleased]`.

## Changing the API: engine first, then spec, then SDKs

1. The payment engine (`labyrinthe-pay-back`) changes first. Never invent an endpoint, a field or an error code in an
   SDK: read the routes (`routes/api/v2/*.php`), the V2 controllers and form requests, `PaymentPresenter` (payment
   shape), `MerchantNotifier` (notifications) and the `PaymentRejectedException::make()` codes.
2. Update `openapi/openapi.yaml` and bump `info.version` (SemVer).
3. `cd node && npm test`: the contract test fails when the spec, the SDK operations and the engine merchant routes
   differ, when a `$ref` is broken or when an error code has no typed error.
4. Update each SDK: models, enums, error mapping (`ErrorFactory` in PHP, `errors.ts`, `errors.py`,
   `internal/ErrorMapper.java`, `Internal/ErrorMapper.cs`), READMEs.
   Regenerate the Postman collection (`cd postman && npm run generate && npm test`: the test fails when an operation
   of the spec has no request); the WordPress plugin follows the PHP SDK (`cd wordpress && composer update`).
5. An API limit that makes integration harder is reported to the engine team with a proposal — it is never worked
   around in an SDK.

## Shared rules

- Amounts are strings with at most 2 decimals; never floats in requests.
- `reference` (1 to 100 of `A-Z a-z 0-9 . _ : -`) is the only idempotency mechanism of the API: keep the
  `sdk_<uuid>` generation and the retry modes identical in every SDK (`Safe` / `OnlyWhenThrottled`).
- 503 codes that refuse before processing (`MAINTENANCE`, `API_DISABLED`, `DEPOSITS_DISABLED`,
  `WITHDRAWALS_DISABLED`, `PROVIDER_TEMPORARILY_UNAVAILABLE`, `V2_DISABLED`, `TEST_MODE_DISABLED`, `API_VERSION_DISABLED`, `FEATURE_DISABLED`) are only retried
  after a `Retry-After`; `DAILY_LIMIT_REACHED` never.
- Webhooks: verify on the raw body, every `v1` in constant time, 300 s tolerance by default.
- The product name is always **LabPay**.

## Releasing

Not set up yet: no package is published. Planned tags per package (`php-vX.Y.Z`, `laravel-vX.Y.Z`, `node-vX.Y.Z`,
`python-vX.Y.Z`). On a release, move `[Unreleased]` to the new version in the CHANGELOG and update the version
constants: `php/src/Version.php`, `LabPayServiceProvider::VERSION` (and the path repository `versions` mapping in
`laravel/composer.json`), `node/package.json` + `node/src/version.ts`, `python/pyproject.toml` +
`python/src/labpay/_version.py`.
WordPress (`wordpress-vX.Y.Z`): plugin header and `LABPAY_WC_VERSION` in `labpay-for-woocommerce.php`, `Stable tag` of
`readme.txt` and `assets/js/blocks.asset.php` (checked by `php bin/build-zip.php`); a new PHP SDK version also goes
in the path repository `versions` mapping of `wordpress/composer.json`. Postman (`postman-vX.Y.Z`): `postman/package.json`.
Java (`java-vX.Y.Z`): `<version>` of the four `pom.xml` (the SDK reads it at build time for `Version.VERSION`).
.NET (`dotnet-vX.Y.Z`): `<Version>` in `dotnet/Directory.Build.props` and `LabPayClient.Version` (checked by a test).
