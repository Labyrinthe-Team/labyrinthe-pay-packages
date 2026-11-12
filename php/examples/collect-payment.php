<?php

declare(strict_types=1);

/*
 * Collect 1 000 CDF from a customer with the test key, then follow the payment.
 *
 *     LABPAY_API_KEY=test_… php examples/collect-payment.php
 */

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;

$client = Client::fromEnv();

try {
    $payment = $client->deposits->create(
        amount: '1000',
        currency: 'CDF',
        phone: '0813456789',               // sandbox number: success
        reference: 'ORDER-'.date('YmdHis'),  // unique per token: a resend returns the same payment
        customerMessage: 'Commande 42',
        metadata: ['orderId' => '42'],
    );
} catch (ConnectionException $e) {
    // Not a failure: the payment may exist. Resend with the same reference.
    exit('Network error: '.$e->getMessage().\PHP_EOL);
} catch (ApiException $e) {
    exit(\sprintf('Refused: %s (%s)%s', $e->getErrorCode(), $e->getMessage(), \PHP_EOL));
}

echo \sprintf('%s: %s, the customer pays %s %s%s', $payment->id, $payment->status->value, $payment->totalAmount ?? '?', $payment->currency, \PHP_EOL);

if ($payment->isFailed()) {
    exit('Refused at initiation: '.($payment->failure->code ?? 'unknown').\PHP_EOL);
}

// Safety net only: in production, wait for the notification on your callback_url.
for ($i = 0; $i < 10 && !$payment->isFinal(); ++$i) {
    sleep(5);
    $payment = $client->payments->sync($payment->id);
}

echo 'Final status: '.$payment->status->value.\PHP_EOL;
