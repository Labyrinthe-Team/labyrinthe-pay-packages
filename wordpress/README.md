# LabPay for WooCommerce

*[Version française](README.fr.md)*

WordPress plugin that adds a **Mobile Money payment gateway** to WooCommerce with [LabPay](https://pay.labyrinthe-rdc.com),
the Mobile Money aggregator of the DR Congo (M-Pesa, Airtel Money, Orange Money; CDF and USD) operated by Labyrinthe
Innovations SARL. Version **0.1.0 — preview**, built on the PHP SDK [`labyrinthe/labpay-php`](../php) (API V2).

| | |
|---|---|
| Slug / text domain | `labpay-for-woocommerce` |
| Gateway ID | `labpay` (settings: option `woocommerce_labpay_settings`) |
| Requirements | WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+, shop currency CDF or USD |
| Compatibility | HPOS (order tables), block checkout, classic checkout, "Pay for order" page |
| License | GPL-2.0-or-later (bundled SDK and dependencies: MIT) |

## How it works

1. **Checkout**: the customer types their Mobile Money number (prefilled with the billing phone) and may pick their
   operator; the operator is otherwise detected from the number (`POST /V2/predict-provider`, shown as a hint) and
   inferred by LabPay. Operators come from `GET /V2/providers` (cached 6 h).
2. **Deposit**: `POST /V2/deposits` with the order total and currency, `reference = WC-<order ID>-<attempt>`,
   `metadata.wc_order_id`, a short `customer_message` ("Order 42") and, on HTTPS sites, `callback_url` = the plugin
   webhook. The customer gets a PIN prompt; the order stays **Pending payment** and the cart is emptied.
3. **Waiting page**: the order confirmation page polls the status every 5 s (10 min max, AJAX with nonce and order
   key); the server re-reads the payment with `POST /V2/payments/{id}/sync` at most every 8 s. On the final status the
   page reloads (paid) or offers to pay again (failed).
4. **Webhook**: `POST /wp-json/labpay/v1/webhook` verifies `X-Labyrinthe-Signature` (HMAC-SHA256 of
   `"<t>.<raw body>"`, constant-time, 300 s tolerance, both secrets during a rotation) with the SDK, finds the order
   (`metadata.wc_order_id`, else the reference) and applies the status.
5. **Status rules** (same code for the checkout answer, the sync and the webhook, under a per-order lock):
   - `completed` → `payment_complete(<payment id>)` (transaction ID = LabPay ID), only when the amount and currency
     match the order; otherwise **On hold** with a note. A late success of an older attempt still pays the order; a
     second success on a paid order adds a note asking to refund it.
   - `failed` → **Failed** with the failure code, only for the current attempt. Paying again creates attempt `n+1`.
   - The same status received twice changes nothing (idempotent); payments of other orders are ignored.
6. **Refunds**: a WooCommerce refund calls `POST /V2/refunds` on the paid deposit (`reference = WC-<order>-R<n>-<attempt>`).
   A refusal (`409 REFUND_IN_PROGRESS`, `DEPOSIT_NOT_COMPLETED`…) cancels the WooCommerce refund with the error; the
   final status of a pending refund arrives by webhook as an order note. Fees are not refunded.

Payments are always followed with the key of the mode they were created in (`_labpay_mode` order meta): a test order
keeps using the test key after you switch to live.

### Idempotency

`reference` is the idempotency key of the API. A double click, a network error or a retry by the SDK reuses the same
reference, so LabPay returns the existing payment instead of charging twice. A new attempt (new reference) is only
opened after a **final failure** (or a mode change). An unanswered refund request is retried with the same reference.

## Settings

*WooCommerce > Settings > Payments > LabPay (Mobile Money)*:

| Setting | |
|---|---|
| Enable, Title, Description | as for any gateway |
| Test mode | uses the test key (`test_…`, sandbox); on by default |
| API token (live), Test key, Signing secret | stored **encrypted** (libsodium secretbox, key derived from the WordPress salts); a test key is refused in the live field |
| Reference prefix | `WC` by default; change it if several shops share one token |
| Debug log | logs every request (errors are always logged) |

The page warns when the key of the active mode is missing, when the shop currency is neither CDF nor USD, when the
signing secret is missing, when a secret became unreadable (salts changed) and when the site is not on HTTPS (no
notifications: the waiting page and the sync still update the orders).

## Security

- Nonces on every AJAX action, order key required for the status, prediction rate-limited per IP (30 / 10 min).
- Webhook authenticated only by the signature (public REST route), body limited to 64 KB; invalid → `400`, missing
  secret or locked order → `503` (LabPay retries), unknown order → `200` (acknowledged, ignored).
- Capabilities and nonces of the WooCommerce settings and refund screens; output escaped, input sanitized.
- Logs (*WooCommerce > Status > Logs*, source `labpay`): payment IDs, codes and statuses only; never a key, a secret,
  a signature or a full phone number.
- The SDK and its dependencies are prefixed (`LabPay\WooCommerce\Vendor\…`, PHP-Scoper) to avoid conflicts with other
  plugins; HTTP goes through `wp_remote_request()` (proxy and certificates of WordPress apply).

## Hooks

| Filter | |
|---|---|
| `labpay_wc_base_url` (or constant `LABPAY_WC_BASE_URL`) | API root, e.g. `http://localhost:8000/api` (`/V2` is added) |
| `labpay_wc_callback_url` | notification address sent with payments (`null`: none) |
| `labpay_wc_reference` | reference of an attempt (keep `<prefix>-<order ID>-` at the start) |
| `labpay_wc_currencies` | accepted currencies (`CDF`, `USD`) |
| `labpay_wc_http_client` | PSR-18 client used by the SDK |

## Development

```bash
composer install                    # dev tools, then vendor-prefixed/ (PHP-Scoper, tools/php-scoper)
php bin/install-wp-tests.php        # WordPress + WooCommerce in .wp-tests/, MySQL database labpay_wp_tests
#   --from=<other>/.wp-tests        # copy an existing WordPress/WooCommerce instead of downloading
vendor/bin/phpunit                  # 39 tests, WordPress test suite + WooCommerce (HPOS); LABPAY_TEST_HPOS=no for posts storage
vendor/bin/phpcs                    # WordPress Coding Standards
vendor/bin/phpstan analyse --memory-limit=1G   # level 8, WordPress and WooCommerce stubs
php bin/i18n.php                    # .pot, fr_FR .po/.mo/.l10n.php/.json (WP-CLI, tools/wp-cli)
php bin/build-zip.php               # dist/labpay-for-woocommerce.zip
```

Tests never hit the network: `pre_http_request` serves the LabPay answers (`tests/Support/HttpMock.php`).

Layout: `labpay-for-woocommerce.php` (bootstrap), `includes/` (`class-gateway.php`, `class-order-payments.php` —
deposit, status rules, refunds, lock —, `class-webhook-controller.php`, `class-ajax.php`, `class-waiting-page.php`,
`blocks/class-payment-method.php`, `class-settings.php`, `class-crypto.php`, `class-client-factory.php`,
`http/class-wp-http-client.php`), `assets/` (classic and block checkout scripts, waiting page), `languages/`,
`bin/` (prefixing, test install, i18n, zip), `tests/`.

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Bundled code under `vendor-prefixed/` keeps its own (MIT) license.
