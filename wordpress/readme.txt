=== LabPay for WooCommerce ===
Contributors: drlab1
Tags: woocommerce, payment gateway, mobile money, m-pesa, congo
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Mobile Money payments for WooCommerce in the DR Congo with LabPay: M-Pesa, Airtel Money and Orange Money, in CDF or USD.

== Description ==

**Français** — LabPay pour WooCommerce ajoute le paiement Mobile Money à votre boutique avec [LabPay](https://pay.labyrinthe-rdc.com), l'agrégateur Mobile Money de la RD Congo opéré par Labyrinthe Innovations SARL. Le client saisit son numéro, reçoit une demande de confirmation sur son téléphone et valide avec son code PIN : la commande passe en cours dès que le paiement est confirmé.

* **M-Pesa, Airtel Money, Orange Money** en CDF ou en USD (la devise de votre boutique) ; l'opérateur est détecté d'après le numéro, le client peut aussi le choisir.
* **Page d'attente** : après la commande, la page de confirmation suit le paiement et se met à jour toute seule (le client n'a rien à recharger).
* **Notification signée** (webhook) : la commande est mise à jour même si le client ferme la page. Chaque notification est vérifiée (signature HMAC-SHA256) ; une notification reçue deux fois ne change rien.
* **Jamais de double paiement** : chaque tentative a une référence unique (« WC-<commande>-<tentative> ») ; une nouvelle tentative n'est ouverte qu'après un échec.
* **Montant vérifié** : une commande n'est payée que si le montant et la devise reçus sont ceux de la commande ; sinon elle est mise en attente avec une note.
* **Remboursements** depuis WooCommerce (total ou partiel), vers le portefeuille qui a payé.
* **Mode test** avec une clé `test_…` : aucun argent réel ne bouge.
* Secrets chiffrés en base, journaux WooCommerce sans clé ni numéro complet, compatible HPOS et validation de commande en blocs, interface en français et en anglais.

**English** — LabPay for WooCommerce adds Mobile Money payments to your shop with [LabPay](https://pay.labyrinthe-rdc.com), the Mobile Money aggregator of the DR Congo operated by Labyrinthe Innovations SARL. The customer types their number, gets a confirmation request on their phone and approves it with their PIN: the order moves to processing as soon as the payment is confirmed.

* **M-Pesa, Airtel Money, Orange Money** in CDF or USD (your shop currency); the operator is detected from the number, or chosen by the customer.
* **Waiting page**: after checkout, the order confirmation page follows the payment and updates itself.
* **Signed notification** (webhook): the order is updated even if the customer closes the page. Every notification is verified (HMAC-SHA256 signature); a duplicate changes nothing.
* **Never a double payment**: each attempt has a unique reference; a new attempt is only opened after a failure.
* **Amount checked**: an order is only paid when the amount and currency received match it; otherwise it is put on hold with a note.
* **Refunds** from WooCommerce (full or partial), to the wallet that paid.
* **Test mode** with a `test_…` key: no real money moves.
* Encrypted secrets, WooCommerce logs without keys or full phone numbers, HPOS and block checkout compatible, French and English interface.

= Requirements =

* WordPress 6.4 or newer, WooCommerce 8.0 or newer, **PHP 8.1 or newer**.
* A LabPay merchant account: API token, test key and signing secret (merchant area > Developers).
* Shop currency: CDF or USD.
* HTTPS, for the notifications (without it, statuses are updated by the waiting page only).

== External services ==

This plugin connects to the **LabPay API** (`https://api.labyrinthe-rdc.com/api/V2`), operated by Labyrinthe Innovations SARL, to collect Mobile Money payments. It is required for the plugin to work.

* **When**: when a customer places an order with this payment method, on the order confirmation page while the payment is pending (status), at checkout to list the operators and detect the operator of the typed number, and when you refund an order. Other public pages never call the API.
* **What is sent**: your API token or test key, the order amount and currency, the customer's Mobile Money number, the chosen operator (if any), a reference made of the order ID, the order ID and your site's host name (metadata), a short text shown to the customer ("Order 42") and, on HTTPS sites, the notification address of your site.
* **What is received**: the payment status, by the answers of the API and by signed notifications sent to `/wp-json/labpay/v1/webhook`.

Terms and privacy: https://pay.labyrinthe-rdc.com

== Installation ==

1. Install WooCommerce, then upload `labpay-for-woocommerce.zip` in *Plugins > Add New > Upload*, and activate it.
2. Open *WooCommerce > Settings > Payments > LabPay (Mobile Money)*.
3. Paste your test key (`test_…`), keep **Test mode** checked, and enable the payment method.
4. Paste your signing secret (`whsec_…`): LabPay then notifies your site of every final status (HTTPS sites).
5. Place a test order. When everything works, paste your live API token and uncheck **Test mode**.

== Frequently Asked Questions ==

= Who pays the fees? / Qui paie les frais ? =

The customer pays the order amount plus the LabPay fees shown on their phone; your LabPay balance is credited with the order amount. Refunds send back the order amount (fees are not refunded).

= The order stays "Pending payment" =

The customer has not confirmed the request on their phone yet (or it expired). The confirmation page checks the status every few seconds; the notification updates the order even when the page is closed. A failed payment can be paid again from the "Pay" link of the order: a new attempt (new reference) is created.

= My shop uses another currency =

LabPay accepts CDF and USD only: the payment method is hidden for other currencies.

= Where are the logs? =

*WooCommerce > Status > Logs*, source `labpay`. Errors are always logged; enable *Debug log* for every request. Keys, secrets and signatures are never logged; phone numbers are masked.

== Changelog ==

= 0.1.0 =
* First preview: Mobile Money payment gateway (deposit, waiting page, signed notifications, refunds, test mode), block checkout, HPOS, French translation.
