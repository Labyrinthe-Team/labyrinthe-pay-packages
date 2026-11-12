<?php

declare(strict_types=1);

/*
 * Send 5 000 CDF to a wallet, after checking the balance and the provider.
 *
 *     LABPAY_API_KEY=test_… php examples/send-payout.php
 */

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Exception\InsufficientBalanceException;

$client = Client::fromEnv();

$prediction = $client->providers->predict('0813456789');
echo \sprintf('%s → %s (%s)%s', $prediction->phone, $prediction->provider, $prediction->country, \PHP_EOL);

$balance = $client->account->balance('CDF');
echo 'CDF balance: '.($balance === null ? 'none' : $balance->balance).\PHP_EOL;

try {
    $payout = $client->payouts->create(
        amount: '5000',
        currency: 'CDF',
        phone: '0813456789',
        provider: $prediction->provider,
        reference: 'PAYOUT-'.date('YmdHis'),
    );
    echo \sprintf('%s: %s, %s %s debited%s', $payout->id, $payout->status->value, $payout->totalAmount ?? '?', $payout->currency, \PHP_EOL);

    if ($payout->isQueued()) {
        // The provider is struggling: the payout can be cancelled (balance credited back).
        $client->payouts->cancel($payout->id);
    }
} catch (InsufficientBalanceException $e) {
    echo 'Insufficient balance: '.$e->getMessage().\PHP_EOL;
}
