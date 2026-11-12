# SDK PHP LabPay

SDK PHP officiel de [LabPay](https://pay.labyrinthe-rdc.com) : encaisser et envoyer du Mobile Money (M-Pesa, Airtel
Money, Orange Money) en RD Congo, en CDF et en USD. Couvre l'**API V2** (la V1 n'est pas couverte).

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0)** — pas encore publié sur Packagist.

- PHP 8.1+, n'importe quel client HTTP PSR-18 (Guzzle par défaut), journal PSR-3.
- Modèles et exceptions typés, relances automatiques sûres, idempotence par `reference`, vérification de la signature
  des notifications.

## Installation

```bash
composer require labyrinthe/labpay-php
```

## Démarrage (mode test)

```php
use Labyrinthe\LabPay\Client;

$labpay = new Client('test_…');   // clé de test : sandbox, aucun argent réel, soldes séparés

$paiement = $labpay->deposits->create(
    amount: '1000',                // montant net crédité ; le client paie ce montant + les frais
    currency: 'CDF',
    phone: '0813456789',           // numéro de la sandbox : réussite
    reference: 'ORDER-42',         // unique par token : un renvoi rend le même paiement
    callbackUrl: 'https://boutique.example/webhooks/labpay',
);

echo $paiement->id;                // "DEP-01JCX…"
echo $paiement->status->value;     // "pending" jusqu'à la validation par code PIN
```

Passer en production = changer de clé (token réel). `Client::fromEnv()` lit `LABPAY_API_KEY` (et `LABPAY_BASE_URL`).

## Couverture de l'API

Dépôts (`deposits->create`), envois (`payouts->create`, `payouts->cancel`), remboursements (`refunds->create`), suivi
(`payments->get`, `list`, `iterate`, `findByReference`, `sync`), pays et opérateurs (`providers->countries`, `list`,
`availability`, `predict`), compte et soldes (`account->get`, `balances`, `balance`, `rotateWebhookSecret`,
`rotateTestKey`), notifications (`notifications->list`, `resend`) et vérification des webhooks (`Webhook::constructEvent`).
Le tableau détaillé est dans le [README anglais](README.md#api-coverage).

## Statuts

`pending` → `completed` ou `failed` (définitifs). **Ne livrez qu'au statut `completed`**, reçu par notification signée
ou relu avec `payments->get()`, jamais sur la seule réponse de création.

- Un paiement refusé dès l'initiation (HTTP 422 **avec** le paiement) est **renvoyé** avec `status: failed` et
  `failure`, pas levé en exception : il a été enregistré, avec son `id`.
- Un HTTP 202 (réponse inconnue de l'agrégateur) est un paiement `pending`, pas un échec.
- Montants : chaînes à 2 décimales (`"1035.00"`) ; passez `amount` en chaîne (`"12.50"`) ou en entier.

## Idempotence et relances

- `reference` est unique par token et par mode : un renvoi avec une référence connue rend le paiement existant. Sans
  référence, le SDK génère `sdk_<uuid>` (désactivable : `autoReference: false`) pour pouvoir relancer un dépôt, un envoi
  ou un remboursement après une erreur réseau ou un 5xx **sans risque de double paiement**.
- Relances automatiques (`maxRetries: 2`) : backoff exponentiel sur les lectures et les paiements avec référence ; 429
  du limiteur après `Retry-After` ; refus de type maintenance (503 `MAINTENANCE`, `DEPOSITS_DISABLED`…) seulement après
  un `Retry-After`. `DAILY_LIMIT_REACHED` n'est jamais relancé.
- Jamais relancés après une erreur réseau ou un 5xx : rotations, renvoi de notification, annulation d'envoi, paiement
  sans référence.
- Une `ConnectionException` sur un paiement ne veut **pas** dire « échec » : renvoyez la même référence ou utilisez
  `$labpay->payments->findByReference('ORDER-42')`.

## Erreurs

Toutes les exceptions implémentent `LabPayException` ; les erreurs de l'API étendent `ApiException`
(`getErrorCode()`, `getStatusCode()`, `getErrors()`, `getRetryAfter()`, `getMode()`). Basez votre logique sur le code,
jamais sur le message. Correspondance code → classe : voir le [README anglais](README.md#errors).

## Notifications (webhooks)

```php
use Labyrinthe\LabPay\Webhook\Webhook;

$evenement = Webhook::constructEvent(
    file_get_contents('php://input'),                 // corps BRUT
    $_SERVER['HTTP_X_LABYRINTHE_SIGNATURE'] ?? '',
    getenv('LABPAY_WEBHOOK_SECRET'),                  // whsec_… (GET /V2/account avec le token réel)
);                                                     // SignatureVerificationException → répondre 400

if ($evenement->isPaymentCompleted()) {
    // livrer $evenement->payment->reference, une seule fois (X-Labyrinthe-Delivery ou payment->id)
}
http_response_code(200);                               // en moins de 10 secondes
```

Chaque `v1` de l'en-tête est comparé en temps constant (deux pendant 24 h après une rotation) et le timestamp doit
dater de moins de 300 s. `Webhook::sign()` fabrique un en-tête pour tester votre point de réception.

## Numéros de test

Vodacom `243813456789`, Airtel `243973456789`, Orange `243893456789` (réussite) ; les échecs sont listés dans le
[README anglais](README.md#test-numbers-sandbox). Un numéro se terminant par `129` reste en attente.

## Développement

```bash
composer install
vendor/bin/phpunit          # tests unitaires, client HTTP simulé (aucun réseau)
vendor/bin/phpstan analyse  # niveau max + règles strictes
```
