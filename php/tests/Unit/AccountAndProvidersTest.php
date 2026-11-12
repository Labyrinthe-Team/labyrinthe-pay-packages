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
