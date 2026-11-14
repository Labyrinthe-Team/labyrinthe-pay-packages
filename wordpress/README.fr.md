# LabPay pour WooCommerce

*[English version](README.md)*

Extension WordPress qui ajoute une **passerelle de paiement Mobile Money** à WooCommerce avec
[LabPay](https://pay.labyrinthe-rdc.com), l'agrégateur Mobile Money pour l'Afrique (M-Pesa, Airtel Money, Orange Money,
MTN MoMo…) opéré par Labyrinthe Innovations SARL. Version **0.1.0 — aperçu**, construite sur le SDK PHP
[`labyrinthe/labpay-php`](../php) (API V2).

| | |
|---|---|
| Slug / domaine de traduction | `labpay-for-woocommerce` |
| Identifiant de passerelle | `labpay` (réglages : option `woocommerce_labpay_settings`) |
| Prérequis | WordPress 6.4+, WooCommerce 8.0+, PHP 8.1+, devise de la boutique CDF ou USD par défaut, autres devises des pays ouverts via le filtre `labpay_wc_currencies` |
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

## Réglages

*WooCommerce > Réglages > Paiements > LabPay (Mobile Money)* :

| Réglage | |
|---|---|
| Activer, Titre, Description | comme pour toute passerelle |
| Mode test | utilise la clé de test (`test_…`, bac à sable) ; activé par défaut |
| Token API (production), Clé de test, Secret de signature | enregistrés **chiffrés** (secretbox libsodium, clé dérivée des salts WordPress) ; une clé de test est refusée dans le champ de production |
| Préfixe des références | `WC` par défaut ; à changer si plusieurs boutiques partagent un token |
| Journal détaillé | journalise chaque requête (les erreurs le sont toujours) |

La page avertit quand la clé du mode actif manque, quand la devise n'est pas une devise acceptée, quand le secret de signature
manque, quand un secret est devenu illisible (salts changés) et quand le site n'est pas en HTTPS (pas de notifications :
la page d'attente et la synchronisation mettent quand même les commandes à jour).

## Sécurité

- Nonce sur chaque action AJAX, clé de commande exigée pour le statut, prédiction limitée par IP (30 / 10 min).
- Webhook authentifié uniquement par la signature (route REST publique), corps limité à 64 Ko ; invalide → `400`,
  secret absent ou commande verrouillée → `503` (LabPay renvoie), commande inconnue → `200` (acquittée, ignorée).
- Capacités et nonces des écrans de réglages et de remboursement de WooCommerce ; sorties échappées, entrées nettoyées.
- Journaux (*WooCommerce > État > Journaux*, source `labpay`) : identifiants de paiement, codes et statuts seulement ;
  jamais de clé, de secret, de signature ni de numéro complet.
- Le SDK et ses dépendances sont préfixés (`LabPay\WooCommerce\Vendor\…`, PHP-Scoper) pour éviter tout conflit avec
  d'autres extensions ; le HTTP passe par `wp_remote_request()` (proxy et certificats de WordPress).

## Filtres

| Filtre | |
|---|---|
| `labpay_wc_base_url` (ou constante `LABPAY_WC_BASE_URL`) | racine de l'API, par ex. `http://localhost:8000/api` (`/V2` est ajouté) |
| `labpay_wc_callback_url` | adresse de notification envoyée avec les paiements (`null` : aucune) |
| `labpay_wc_reference` | référence d'une tentative (garder `<préfixe>-<n° de commande>-` au début) |
| `labpay_wc_currencies` | devises acceptées (par défaut `CDF`, `USD` ; ajoutez la devise de tout pays ouvert sur LabPay, ex. `XOF`) |
| `labpay_wc_http_client` | client PSR-18 utilisé par le SDK |

## Développement

```bash
composer install                    # outils, puis vendor-prefixed/ (PHP-Scoper, tools/php-scoper)
php bin/install-wp-tests.php        # WordPress + WooCommerce dans .wp-tests/, base MySQL labpay_wp_tests
#   --from=<autre>/.wp-tests        # copie un WordPress/WooCommerce existant au lieu de le télécharger
vendor/bin/phpunit                  # 39 tests, suite WordPress + WooCommerce (HPOS) ; LABPAY_TEST_HPOS=no pour le stockage historique
vendor/bin/phpcs                    # WordPress Coding Standards
vendor/bin/phpstan analyse --memory-limit=1G   # niveau 8, stubs WordPress et WooCommerce
php bin/i18n.php                    # .pot, fr_FR .po/.mo/.l10n.php/.json (WP-CLI, tools/wp-cli)
php bin/build-zip.php               # dist/labpay-for-woocommerce.zip
```

Les tests n'appellent jamais le réseau : `pre_http_request` sert les réponses de LabPay (`tests/Support/HttpMock.php`).

## Licence

GPL-2.0-or-later — voir [LICENSE](LICENSE). Le code embarqué dans `vendor-prefixed/` garde sa licence (MIT).
