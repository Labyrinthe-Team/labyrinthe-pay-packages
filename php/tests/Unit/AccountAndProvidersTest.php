<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Unit;

use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Enum\Mode;
use Labyrinthe\LabPay\Enum\NotificationStatus;
use Labyrinthe\LabPay\Enum\WebhookEventType;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Model\ProviderAvailability;
use Labyrinthe\LabPay\Tests\Support\TestCase;

final class AccountAndProvidersTest extends TestCase
{
    public function testAccount(): void
    {
        $this->http->queue(self::json(200, ['data' => [
            'mode' => 'live',
            'merchant' => 'Boutique',
            'live_enabled' => true,
            'kyc_verified' => true,
            'fees' => ['deposit_rate' => '0.0350', 'payout_rate' => '0.0350'],
            'test_key' => 'test_abc',
            'webhook_secret' => 'whsec_secret',
        ]]));

        $account = $this->client('live_token_abcdefgh')->account->get();

        self::assertSame(Mode::Live, $account->mode);
        self::assertTrue($account->liveEnabled);
        self::assertSame('0.0350', $account->depositRate);
        self::assertSame('whsec_secret', $account->webhookSecret);
        self::assertNull($account->webhookSecretPreviousExpiresAt);
        self::assertStringNotContainsString('whsec_secret', print_r($account, true));
    }

    public function testBalancesAndRotations(): void
    {
        $this->http->queue(
            self::json(200, ['data' => [
                ['currency' => 'CDF', 'balance' => '125000.00', 'updated_at' => '2026-11-11T10:00:42+00:00'],
                ['currency' => 'USD', 'balance' => '0.00', 'updated_at' => null],
            ]]),
            self::json(200, ['data' => ['webhook_secret' => 'whsec_new', 'webhook_secret_previous_expires_at' => '2026-11-12T10:00:00+00:00']]),
            self::json(200, ['data' => ['test_key' => 'test_new']]),
        );
        $client = $this->client('live_token_abcdefgh');

        $balances = $client->account->balances();
        self::assertCount(2, $balances);
        self::assertSame('125000.00', $balances[0]->balance);
        self::assertStringEndsWith('/V2/balances', (string) $this->lastRequest()->getUri());

        $secret = $client->account->rotateWebhookSecret();
        self::assertSame('whsec_new', $secret->secret);
        self::assertStringEndsWith('/V2/account/webhook-secret/rotate', (string) $this->lastRequest()->getUri());

        self::assertSame('test_new', $client->account->rotateTestKey());
        self::assertStringEndsWith('/V2/account/test-key/rotate', (string) $this->lastRequest()->getUri());
    }

    public function testCountriesProvidersAvailabilityAndPrediction(): void
    {
        $this->http->queue(
            self::json(200, ['data' => [[
                'country' => 'COD',
                'name' => 'RD Congo',
                'dial_code' => '243',
                'currencies' => ['CDF', 'USD'],
                'limits' => ['CDF' => ['min_amount' => '100', 'max_amount' => '20000000'], 'USD' => ['min_amount' => null, 'max_amount' => '10000']],
                'default' => true,
            ]]]),
            self::json(200, ['data' => [[
                'provider' => 'VODACOM_MPESA_COD',
                'country' => 'COD',
                'name' => 'Vodacom M-Pesa',
                'name_displayed_to_customer' => 'M-PESA',
                'logo' => null,
                'currencies' => ['CDF' => ['min_amount' => '100', 'max_amount' => '2500000', 'decimals' => 'NONE', 'status' => 'OPERATIONAL', 'pin_prompt' => 'AUTOMATIC', 'pin_prompt_revivable' => false, 'pin_prompt_instructions' => null]],
            ]]]),
            self::json(200, ['data' => ['VODACOM_MPESA_COD' => ['country' => 'COD', 'DEPOSIT' => 'OPERATIONAL', 'PAYOUT' => 'DELAYED']]]),
            self::json(200, ['data' => ['phone' => '243813456789', 'provider' => 'VODACOM_MPESA_COD', 'country' => 'COD']]),
        );
        $client = $this->client();

        $countries = $client->providers->countries();
        self::assertTrue($countries[0]->isDefault);
        self::assertSame('20000000', $countries[0]->limits['CDF']->maxAmount ?? null);
        self::assertArrayHasKey('USD', $countries[0]->limits);
        self::assertNull($countries[0]->limits['USD']->minAmount);

        $providers = $client->providers->list(operation: 'payout', country: 'cod');
        self::assertSame('operation=payout&country=COD', $this->lastRequest()->getUri()->getQuery());
        self::assertSame('NONE', $providers[0]->currencies['CDF']->decimals ?? null);
        self::assertSame('false', $providers[0]->currencies['CDF']->pinPromptRevivable ?? null);

        $availability = $client->providers->availability('COD');
        self::assertSame('COD', $availability['VODACOM_MPESA_COD']->country ?? null);
        self::assertTrue($availability['VODACOM_MPESA_COD']->isOperational('deposit'));
        self::assertSame(ProviderAvailability::DELAYED, $availability['VODACOM_MPESA_COD']->status('PAYOUT'));

        $prediction = $client->providers->predict('0813456789', 'COD');
        self::assertSame(['phone' => '0813456789', 'country' => 'COD'], $this->lastBody());
        self::assertSame('243813456789', $prediction->phone);
    }

    public function testEmptyAvailabilityIsAnObject(): void
    {
        $this->http->queue(self::json(200, ['data' => new \stdClass()]));

        self::assertSame([], $this->client()->providers->availability());
    }

    public function testNotifications(): void
    {
        $notification = [
            'id' => 12, 'event' => 'payment.completed', 'payment_id' => 'DEP-1', 'url' => 'https://boutique.example/webhooks/labpay',
            'status' => 'abandoned', 'attempts' => 6, 'last_status_code' => 500, 'last_error' => 'HTTP 500',
            'next_attempt_at' => null, 'delivered_at' => null, 'created_at' => '2026-11-11T10:00:43+00:00',
        ];
        $this->http->queue(
            self::json(200, ['data' => [$notification], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 1]]),
            self::json(202, ['data' => ['id' => 13, 'status' => 'pending', 'attempts' => 0] + $notification]),
        );
        $client = $this->client();

        $page = $client->notifications->list(paymentId: 'DEP-1', status: NotificationStatus::Abandoned);
        self::assertSame('payment_id=DEP-1&status=abandoned', $this->lastRequest()->getUri()->getQuery());
        self::assertCount(1, $page);
        self::assertFalse($page->hasMore());
        self::assertSame(WebhookEventType::PaymentCompleted, $page->items[0]->event);
        self::assertSame(500, $page->items[0]->lastStatusCode);

        $resent = $client->notifications->resend(12);
        self::assertStringEndsWith('/V2/notifications/12/resend', (string) $this->lastRequest()->getUri());
        self::assertSame(13, $resent->id);
        self::assertSame(NotificationStatus::Pending, $resent->status);
    }

    public function testBaseUrlNormalization(): void
    {
        self::assertSame('https://api.labyrinthe-rdc.com/api/V2', (new Client('test_x'))->baseUrl());
        self::assertSame('http://localhost:8000/api/V2', (new Client('test_x', 'http://localhost:8000'))->baseUrl());
        self::assertSame('https://pay.example/api/V2', (new Client('test_x', 'https://pay.example/api/V2/'))->baseUrl());
        self::assertTrue((new Client('test_x'))->isTestMode());
        self::assertFalse((new Client('live'))->isTestMode());
    }

    public function testTheKeyNeverLeaks(): void
    {
        $client = new Client('live_secret_token_value');

        self::assertStringNotContainsString('live_secret_token_value', print_r($client, true));
        $this->expectException(\LogicException::class);
        serialize($client);
    }

    public function testEmptyKeyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client('  ');
    }
}
