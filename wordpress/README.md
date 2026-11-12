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
