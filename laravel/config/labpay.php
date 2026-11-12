<?php

declare(strict_types=1);

return [

    /*
    | Live token or test key (test_…). With the test key, payments go through
    | the sandbox: no real money, separate balances. Keep it in .env only.
    */
    'api_key' => env('LABPAY_API_KEY'),

    /*
    | API root; "/V2" is added by the SDK.
    */
    'base_url' => env('LABPAY_BASE_URL', 'https://api.labyrinthe-rdc.com/api'),

    'timeout' => (float) env('LABPAY_TIMEOUT', 30),

    'max_retries' => (int) env('LABPAY_MAX_RETRIES', 2),

    /*
    | Generate a `reference` for payments sent without one, so that the SDK
    | can retry them safely (the API returns the existing payment).
    */
    'auto_reference' => true,

    'webhook' => [

        /*
        | Signing secret (whsec_…, GET /V2/account with the live token). During
        | your own rotation, list both secrets separated by a comma.
        */
        'secret' => env('LABPAY_WEBHOOK_SECRET'),

        /*
        | Path of the notification route (your callback_url), or null to
        | register your own route with the VerifyLabPaySignature middleware.
        */
        'path' => env('LABPAY_WEBHOOK_PATH', 'labpay/webhook'),

        /*
        | Middleware of the route. Never "web": notifications carry no CSRF token.
        */
        'middleware' => [],

        /*
        | Max age of the signed timestamp, in seconds (replay protection).
        */
        'tolerance' => 300,
    ],
];
