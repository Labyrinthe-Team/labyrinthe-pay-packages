# Changelog

All notable changes to the `labpay-for-woocommerce` WordPress plugin are documented here. This project follows
[Semantic Versioning](https://semver.org/) and the format of [Keep a Changelog](https://keepachangelog.com/).
The wordpress.org changelog lives in `readme.txt`.

## [Unreleased]

### Changed
- wordpress.org review: translations now come from translate.wordpress.org. The zip no longer ships the compiled
  `.po` / `.mo` / `.json` / `.l10n.php` files (only the `.pot` template) and the plugin no longer calls
  `load_plugin_textdomain()` (WordPress loads the translations itself). The French translation stays in `languages/` and
  is imported on translate.wordpress.org.
- The "WooCommerce is required" notice only appears on the dashboard and the Plugins screen.

## [0.1.0] - 2026-11-12

First preview, built on `labyrinthe/labpay-php` 0.1.0 (API V2, specification 2.0.0). Not published.

### Added
- WooCommerce payment gateway `labpay` (Mobile Money: M-Pesa, Airtel Money, Orange Money; CDF and USD): phone number
  and optional operator at checkout, operator list (`GET /V2/providers`, cached) and detection
  (`POST /V2/predict-provider`), deposit `POST /V2/deposits` with `reference = WC-<order>-<attempt>` (idempotent, a new
  attempt only after a final failure) and `metadata.wc_order_id`.
- Waiting page on the order confirmation page: status polling (AJAX, nonce + order key) with server-side sync
  (`POST /V2/payments/{id}/sync`, at most every 8 s).
- Signed notifications: REST route `POST /wp-json/labpay/v1/webhook`, `X-Labyrinthe-Signature` verified by the SDK,
  idempotent status rules under a per-order lock, amount and currency checked before an order is paid.
- WooCommerce refunds → `POST /V2/refunds` (full or partial), final status of pending refunds as order notes.
- Settings: test mode, live token, test key and signing secret stored encrypted, reference prefix, debug log; warnings
  for missing keys, unsupported currency, missing secret and non-HTTPS sites.
- WooCommerce logs (source `labpay`) without keys, secrets or full phone numbers.
- Block checkout payment method, HPOS compatibility, French translation (`fr_FR`, also used for other French locales).
- Tooling: prefixed dependencies (PHP-Scoper), WordPress test suite with WooCommerce (39 tests), PHPCS (WordPress
  Coding Standards), PHPStan level 8, WP-CLI i18n, reproducible zip build.
