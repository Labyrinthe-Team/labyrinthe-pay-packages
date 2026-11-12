# LabPay pour WooCommerce

*[English version](README.md)*

Extension WordPress qui ajoute une **passerelle de paiement Mobile Money** à WooCommerce avec
[LabPay](https://pay.labyrinthe-rdc.com), l'agrégateur Mobile Money de la RD Congo (M-Pesa, Airtel Money, Orange Money ;
CDF et USD) opéré par Labyrinthe Innovations SARL. Version **0.1.0 — aperçu**, construite sur le SDK PHP
[`labyrinthe/labpay-php`](../php) (API V2).

| | |
|---|---|
| Slug / domaine de traduction | `labpay-for-woocommerce` |
| Identifiant de passerelle | `labpay` (réglages : option `woocommerce_labpay_settings`) |
| Prérequis | WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+, devise de la boutique CDF ou USD |
| Compatibilité | HPOS (tables de commandes), validation en blocs, validation classique, page « Payer la commande » |
| Licence | GPL-2.0-or-later (SDK et dépendances embarqués : MIT) |

## Fonctionnement

1. **Validation de commande** : le client saisit son numéro Mobile Money (prérempli avec le téléphone de facturation)
   et peut choisir son opérateur ; sinon l'opérateur est détecté d'après le numéro (`POST /V2/predict-provider`,
   affiché à titre indicatif) et déduit par LabPay. Les opérateurs viennent de `GET /V2/providers` (cache de 6 h).
2. **Dépôt** : `POST /V2/deposits` avec le total et la devise de la commande, `reference = WC-<n° de commande>-<tentative>`,
   `metadata.wc_order_id`, un court `customer_message` (« Commande 42 ») et, en HTTPS, `callback_url` = le webhook de
   l'extension. Le client reçoit une demande de code PIN ; la commande reste **En attente de paiement** et le panier
   est vidé.
3. **Page d'attente** : la page de confirmation de commande interroge le statut toutes les 5 s (10 min au plus, AJAX
   avec nonce et clé de commande) ; le serveur relit le paiement avec `POST /V2/payments/{id}/sync` au plus toutes les
   8 s. Au statut final, la page se recharge (payée) ou propose de payer à nouveau (échec).
4. **Webhook** : `POST /wp-json/labpay/v1/webhook` vérifie `X-Labyrinthe-Signature` (HMAC-SHA256 de
   `"<t>.<corps brut>"`, temps constant, 300 s de tolérance, deux secrets pendant une rotation) avec le SDK, retrouve la
   commande (`metadata.wc_order_id`, sinon la référence) et applique le statut.
5. **Règles de statut** (même code pour la réponse au paiement, la synchronisation et le webhook, sous un verrou par
   commande) :
   - `completed` → `payment_complete(<id du paiement>)` (identifiant de transaction = identifiant LabPay), seulement si
     le montant et la devise sont ceux de la commande ; sinon **En attente** avec une note. Le succès tardif d'une
     ancienne tentative paie quand même la commande ; un second succès sur une commande payée ajoute une note pour le
     rembourser.
   - `failed` → **Échouée** avec le code d'échec, seulement pour la tentative en cours. Payer à nouveau crée la
     tentative `n+1`.
   - Un même statut reçu deux fois ne change rien (idempotent) ; les paiements d'autres commandes sont ignorés.
6. **Remboursements** : un remboursement WooCommerce appelle `POST /V2/refunds` sur le dépôt payé
   (`reference = WC-<commande>-R<n>-<tentative>`). Un refus (`409 REFUND_IN_PROGRESS`, `DEPOSIT_NOT_COMPLETED`…) annule
   le remboursement WooCommerce avec l'erreur ; le statut final d'un remboursement en cours arrive par webhook, en note de
   commande. Les frais ne sont pas remboursés.

Un paiement est toujours suivi avec la clé du mode dans lequel il a été créé (métadonnée `_labpay_mode`) : une commande
de test reste suivie avec la clé de test après le passage en production.

### Idempotence

`reference` est la clé d'idempotence de l'API. Un double clic, une erreur réseau ou une relance du SDK réutilise la
même référence : LabPay rend le paiement existant au lieu de débiter deux fois. Une nouvelle tentative (nouvelle
référence) n'est ouverte qu'après un **échec définitif** (ou un changement de mode). Une demande de remboursement restée
sans réponse est renvoyée avec la même référence.

