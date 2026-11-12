# LabPay pour Laravel

Intégration Laravel de [LabPay](https://pay.labyrinthe-rdc.com) (Mobile Money en RD Congo), au-dessus de
[`labyrinthe/labpay-php`](../php) : client singleton, façade, configuration publiable, route de réception des
notifications avec vérification de signature et événements.

*English version: [README.md](README.md).*

> **État : aperçu (0.1.0)** — pas encore publié sur Packagist. Laravel 10 à 13, PHP 8.1+.

## Installation

```bash
composer require labyrinthe/labpay-laravel
php artisan vendor:publish --tag=labpay-config   # facultatif
```

```dotenv
LABPAY_API_KEY=test_…               # clé de test (sandbox) ; token réel en production
LABPAY_WEBHOOK_SECRET=whsec_…       # GET /V2/account avec le token réel ; "nouveau,ancien" pendant une rotation
```

## Utilisation

Injectez `Labyrinthe\LabPay\Client` ou utilisez la façade `LabPay::client()->deposits->create(…)` ; toute l'API du
[SDK PHP](../php/README.fr.md) est disponible.

## Notifications

La route `POST /labpay/webhook` (nom `labpay.webhook`, sans CSRF) vérifie la signature sur le corps brut (400 si
invalide, 500 sans secret), déclenche `WebhookReceived` puis `PaymentCompleted` ou `PaymentFailed`, et répond 200.
Écoutez `PaymentCompleted` pour livrer la commande (`$event->payment()->reference`), de façon idempotente : une même
notification peut arriver plusieurs fois (`$event->deliveryId`). Mettez en file les traitements longs (LabPay attend
10 s).

Route personnalisée : `LABPAY_WEBHOOK_PATH=null` et le middleware `labpay.signature` ; l'événement vérifié est dans
`$request->attributes->get(VerifyLabPaySignature::EVENT)`.

## Développement

```bash
composer install && vendor/bin/phpunit && vendor/bin/phpstan analyse
```
