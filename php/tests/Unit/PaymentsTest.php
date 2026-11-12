<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Unit;

use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Enum\Operation;
use Labyrinthe\LabPay\Enum\PaymentStatus;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Tests\Support\TestCase;

final class PaymentsTest extends TestCase
{
    public function testDepositSendsTheDocumentedBodyAndHeaders(): void
    {
        $this->http->queue(self::json(201, ['data' => self::payment()]));

        $payment = $this->client()->deposits->create(
            amount: 1000,
            currency: 'cdf',
            phone: '0813456789',
            reference: 'ORDER-42',
            callbackUrl: 'https://boutique.example/webhooks/labpay',
            customerMessage: 'Commande 42',
            metadata: ['orderId' => 42],
        );

        $request = $this->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test/api/V2/deposits', (string) $request->getUri());
        self::assertSame('Bearer test_abcdefghijklmnop', $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('labpay-php/', $request->getHeaderLine('User-Agent'));
        self::assertSame([
            'amount' => '1000',
            'currency' => 'CDF',
            'phone' => '0813456789',
            'reference' => 'ORDER-42',
            'callback_url' => 'https://boutique.example/webhooks/labpay',
            'customer_message' => 'Commande 42',
            'metadata' => ['orderId' => '42'],
        ], $this->lastBody());

        self::assertSame('DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $payment->id);
        self::assertSame(Operation::Deposit, $payment->operation);
        self::assertSame(PaymentStatus::Pending, $payment->status);
        self::assertSame('1000.00', $payment->amount);
        self::assertSame('1035.00', $payment->totalAmount);
        self::assertSame(['orderId' => '42'], $payment->metadata);
        self::assertTrue($payment->isTest());
        self::assertFalse($payment->isFinal());
    }

    public function testAReferenceIsGeneratedWhenMissing(): void
    {
        $this->http->queue(self::json(201, ['data' => self::payment()]));

        $this->client()->deposits->create(amount: '12.50', currency: 'USD', phone: '+243813456789');

        $body = $this->lastBody();
        self::assertIsString($body['reference']);
        self::assertMatchesRegularExpression('/^sdk_[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $body['reference']);
        self::assertSame('12.50', $body['amount']);
    }

    public function testNoReferenceIsGeneratedWhenDisabled(): void
    {
        $this->http->queue(self::json(201, ['data' => self::payment()]));

        $this->client(autoReference: false)->payouts->create(amount: '5000', currency: 'CDF', phone: '0813456789');

        self::assertArrayNotHasKey('reference', $this->lastBody());
        self::assertSame('https://api.example.test/api/V2/payouts', (string) $this->lastRequest()->getUri());
    }

    public function testAPaymentRefusedAtInitiationIsReturnedNotThrown(): void
    {
        $this->http->queue(self::json(422, ['data' => self::payment([
            'status' => 'failed',
            'gateway_status' => 'REJECTED',
            'failure' => ['code' => 'PAYER_LIMIT_REACHED', 'message' => 'Limite atteinte'],
        ])]));

        $client = $this->client();
        $payment = $client->deposits->create(amount: '1000', currency: 'CDF', phone: '243813456019');

        self::assertTrue($payment->isFailed());
        self::assertSame('PAYER_LIMIT_REACHED', $payment->failure?->code);
        self::assertSame(422, $client->lastResponse()?->statusCode);
        self::assertSame('test', $client->lastResponse()?->mode());
    }

    public function testAnUnknownGatewayAnswerIsStillPending(): void
    {
        $this->http->queue(self::json(202, ['data' => self::payment(['gateway_status' => 'UNKNOWN'])]));

        $payment = $this->client()->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789');

