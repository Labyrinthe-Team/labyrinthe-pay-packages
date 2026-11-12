# LabPay for Laravel

Laravel integration of [LabPay](https://pay.labyrinthe-rdc.com) (Mobile Money in the DR Congo), on top of
[`labyrinthe/labpay-php`](../php): client singleton, facade, publishable config, and a notification route with
signature verification and events.

*Version française : [README.fr.md](README.fr.md).*

> **Status: preview (0.1.0)** — not yet published on Packagist. Laravel 10 to 13, PHP 8.1+.

## Install

```bash
composer require labyrinthe/labpay-laravel
php artisan vendor:publish --tag=labpay-config   # optional
```

```dotenv
LABPAY_API_KEY=test_…               # test key (sandbox); the live token in production
LABPAY_WEBHOOK_SECRET=whsec_…       # GET /V2/account with the live token; "new,old" during a rotation
# LABPAY_BASE_URL=https://api.labyrinthe-rdc.com/api
# LABPAY_WEBHOOK_PATH=labpay/webhook  (null to register your own route)
```

## Use the client

```php
use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Laravel\Facades\LabPay;

public function pay(Order $order, Client $labpay)
{
    $payment = $labpay->deposits->create(
        amount: $order->total,             // "1000" or "12.50"
        currency: 'CDF',
        phone: $order->phone,
        reference: 'ORDER-'.$order->id,    // a resend returns the same payment
        callbackUrl: route('labpay.webhook'),
    );
}

LabPay::client()->payments->get('DEP-01JCX…');   // facade
```

The whole SDK API is available: see the [PHP SDK README](../php/README.md).

## Receive notifications

The package registers `POST /labpay/webhook` (name `labpay.webhook`, no CSRF middleware). It verifies
`X-Labyrinthe-Signature` on the raw body (400 if invalid, 500 if `LABPAY_WEBHOOK_SECRET` is missing), dispatches
`WebhookReceived`, then `PaymentCompleted` or `PaymentFailed`, and answers 200.

```php
use Labyrinthe\LabPay\Laravel\Events\PaymentCompleted;

Event::listen(function (PaymentCompleted $event) {
    // $event->payment()->reference, $event->deliveryId (deduplicate), $event->mode ("live" / "test")
    Order::where('reference', $event->payment()->reference)->first()?->markAsPaid();
});
```

Listeners run before the answer and LabPay waits 10 seconds: queue long work (`ShouldQueue`). The same notification
can arrive more than once: make your listener idempotent.

Your own route: set `LABPAY_WEBHOOK_PATH=null` and use the middleware, the verified event is in the request:

```php
use Labyrinthe\LabPay\Laravel\Http\Middleware\VerifyLabPaySignature;

Route::post('payments/labpay', function (Request $request) {
    $event = $request->attributes->get(VerifyLabPaySignature::EVENT); // Labyrinthe\LabPay\Webhook\Event
    return response()->noContent(200);
})->middleware('labpay.signature');
```

## Development

```bash
composer install            # uses ../php through a Composer path repository
vendor/bin/phpunit          # Orchestra Testbench
vendor/bin/phpstan analyse  # Larastan, level max
```
