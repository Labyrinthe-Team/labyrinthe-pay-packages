<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Tests;

use Labyrinthe\LabPay\Laravel\Facades\LabPay;
use Labyrinthe\LabPay\Laravel\LabPayServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected const SECRET = 'whsec_test_secret';

    /**
     * @return list<class-string<\Illuminate\Support\ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [LabPayServiceProvider::class];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return ['LabPay' => LabPay::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('labpay.api_key', 'test_abcdefghijklmnop');
        $app['config']->set('labpay.webhook.secret', self::SECRET);
    }

    /**
     * Body of a notification, encoded like the engine (MerchantNotifier).
     */
    protected static function notification(string $event = 'payment.completed', string $status = 'completed'): string
    {
        return json_encode([
            'event' => $event,
            'payment' => [
                'id' => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
                'reference' => 'ORDER-42',
                'operation' => 'deposit',
                'livemode' => false,
                'status' => $status,
                'gateway_status' => 'COMPLETED',
                'amount' => '1000.00',
                'fee' => '35.00',
                'total_amount' => '1035.00',
                'currency' => 'CDF',
                'country' => 'COD',
                'phone' => '0813456789',
                'provider' => 'VODACOM_MPESA_COD',
                'provider_transaction_id' => null,
                'failure' => $status === 'failed' ? ['code' => 'PAYER_NOT_FOUND', 'message' => 'Numéro inconnu'] : null,
                'metadata' => ['orderId' => '42'],
                'created_at' => '2026-11-11T10:00:00+00:00',
                'completed_at' => '2026-11-11T10:00:42+00:00',
                'refunded_amount' => '0.00',
            ],
            'sent_at' => '2026-11-11T10:00:43+00:00',
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }
}
