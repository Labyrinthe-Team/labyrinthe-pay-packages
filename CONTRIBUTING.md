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

Every package has a CI workflow (`.github/workflows/<package>.yml`) and is released by pushing a SemVer tag
prefixed with its folder:

| Tag in this repository | Effect |
|---|---|
| `php-v1.2.3` | `php/` is pushed to the mirror `labyrinthe-pay-package-php` with the tag `v1.2.3` → Packagist `labyrinthe/labpay-php` 1.2.3 (`split.yml`) |
| `laravel-v1.2.3` | `laravel/` is pushed to the mirror `labyrinthe-pay-package-laravel` with the tag `v1.2.3` → Packagist `labyrinthe/labpay-laravel` 1.2.3 (`split.yml`) |
| `node-v1.2.3` | `node/` is published to npm as `@labyrinthe/labpay` 1.2.3 (`node-release.yml`; a prerelease such as `node-v1.3.0-beta.1` gets the `next` dist-tag) |
| `python-v1.2.3` | `python/` is published to PyPI as `labpay` 1.2.3 (`python-release.yml`, Trusted Publishing; prereleases use PEP 440: `python-v1.3.0rc1`) |
| `dotnet-v1.2.3` | `dotnet/` is published to NuGet as `Labyrinthe.LabPay` and `Labyrinthe.LabPay.AspNetCore` 1.2.3, with symbol packages (`dotnet-release.yml`) |
| `java-v1.2.3` | `java/` is published to Maven Central as `com.labyrinthe-rdc:labpay` and `com.labyrinthe-rdc:labpay-spring-boot-starter` 1.2.3, signed, with sources and Javadoc (`java-release.yml`) |
| `wordpress-v1.2.3` | `wordpress/` is built into `labpay-for-woocommerce.zip` (GitHub release), then deployed to the wordpress.org SVN when the repository variable `WORDPRESS_ORG_DEPLOY` is `true` (`wordpress-release.yml`) |

Postman and OpenAPI are not published to a registry: the collection and the spec are served from this repository.

Before tagging:

1. Move `[Unreleased]` to the new version in the package CHANGELOG and update the version constants:
   `php/src/Version.php`; `LabPayServiceProvider::VERSION`; `node/package.json` + `node/src/version.ts`;
   `python/pyproject.toml` + `python/src/labpay/_version.py`; `<Version>` in `dotnet/Directory.Build.props` and
   `LabPayClient.Version` (checked by a test); the `<version>` of the four `java/**/pom.xml`
   (`Version.VERSION` is read from the pom). WordPress: plugin header and `LABPAY_WC_VERSION` in
   `labpay-for-woocommerce.php`, `Stable tag` of `readme.txt` and `assets/js/blocks.asset.php` (checked by
   `php bin/build-zip.php`). Postman: `postman/package.json`. The release workflows refuse a tag that differs
   from the package version.
2. When the PHP SDK version changes, update the path repository `versions` mapping in `laravel/composer.json` and
   `wordpress/composer.json` (and the `labyrinthe/labpay-php` constraint when needed). Release `php-v…` before
   `laravel-v…`: the published Laravel package requires the PHP SDK from Packagist.
3. Push to `master`, wait for green CI, then `git tag php-v1.2.3 && git push origin php-v1.2.3`.

One-time setup (GitHub: Settings > Secrets and variables / Environments of this repository):

- `split.yml`: secret `MONOREPO_SPLIT_TOKEN`, a fine-grained token with *Contents: read and write* on
  `labyrinthe-pay-package-php` and `labyrinthe-pay-package-laravel`. Each mirror is submitted once on Packagist, with the GitHub
  hook enabled.
- `node-release.yml`: environment `npm` with the secret `NPM_TOKEN` (granular token, publish rights on the
  `@labyrinthe` scope). Provenance requires this repository to stay public.
- `python-release.yml`: no secret, PyPI Trusted Publishing — add a *pending publisher* for project `labpay`
  (owner `Labyrinthe-Team`, repository `labyrinthe-pay-packages`, workflow `python-release.yml`, environment
  `pypi`) and create the `pypi` environment.
- `dotnet-release.yml`: environment `nuget`, with either a NuGet Trusted Publishing policy (repository
  `Labyrinthe-Team/labyrinthe-pay-packages`, workflow `dotnet-release.yml`, environment `nuget`) and the
  repository variable `NUGET_USER`, or a push-only `NUGET_API_KEY` secret.
- `java-release.yml`: environment `maven-central` with `MAVEN_CENTRAL_USERNAME` / `MAVEN_CENTRAL_PASSWORD`
  (Central Portal *user token*), `GPG_PRIVATE_KEY` (armored, public key on keys.openpgp.org) and
  `GPG_PASSPHRASE`. The `com.labyrinthe-rdc` namespace must be verified on central.sonatype.com.
- `wordpress-release.yml`: once the plugin is accepted on wordpress.org (slug `labpay-for-woocommerce`),
  environment `wordpress-org` with `SVN_USERNAME` / `SVN_PASSWORD` (the SVN password of the wordpress.org
  profile), banners and icons in `wordpress/.wordpress-org/`, and the repository variable
  `WORDPRESS_ORG_DEPLOY=true`.
