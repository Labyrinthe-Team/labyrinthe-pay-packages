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

        self::assertTrue($payment->isPending());
    }

    public function testRefundBody(): void
    {
        $this->http->queue(self::json(201, ['data' => self::payment([
            'id' => 'RFD-01JCXB7M8N9P0Q1R2S3T4V5W6X',
            'operation' => 'refund',
            'amount' => '300.00',
            'refunded_payment_id' => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6',
        ])]));

        $refund = $this->client()->refunds->create(paymentId: 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', amount: '300', reference: 'REFUND-42-1');

        self::assertSame(['payment_id' => 'DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', 'amount' => '300', 'reference' => 'REFUND-42-1'], $this->lastBody());
        self::assertSame(Operation::Refund, $refund->operation);
        self::assertSame('DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', $refund->refundedPaymentId);
    }

    public function testCancelSyncAndGet(): void
    {
        $this->http->queue(
            self::json(200, ['data' => self::payment(['id' => 'PAY-1', 'operation' => 'payout', 'gateway_status' => 'ENQUEUED'])]),
            self::json(200, ['data' => self::payment()]),
            self::json(200, ['data' => self::payment(['status' => 'completed', 'completed_at' => '2026-11-11T10:00:42+00:00'])]),
        );
        $client = $this->client();

        self::assertTrue($client->payments->get('PAY-1')->isQueued());
        self::assertSame('https://api.example.test/api/V2/payments/PAY-1', (string) $this->lastRequest()->getUri());

        $client->payouts->cancel('PAY-1');
        self::assertSame('https://api.example.test/api/V2/payouts/PAY-1/cancel', (string) $this->lastRequest()->getUri());

        $synced = $client->payments->sync('DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6');
        self::assertSame('POST', $this->lastRequest()->getMethod());
        self::assertStringEndsWith('/payments/DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6/sync', (string) $this->lastRequest()->getUri());
        self::assertTrue($synced->isCompleted());
        self::assertSame('2026-11-11T10:00:42+00:00', $synced->completedAt?->format(\DATE_ATOM));
    }

    public function testListWithFiltersAndPagination(): void
    {
        $meta = ['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2];
        $this->http->queue(
            self::json(200, ['data' => [self::payment()], 'links' => [], 'meta' => $meta]),
            self::json(200, ['data' => [self::payment(['id' => 'DEP-2'])], 'links' => [], 'meta' => ['current_page' => 2] + $meta]),
        );

        $ids = [];
        foreach ($this->client()->payments->iterate(status: PaymentStatus::Completed, operation: 'deposit', from: new \DateTimeImmutable('2026-11-01'), perPage: 1) as $payment) {
            $ids[] = $payment->id;
        }

        self::assertSame(['DEP-01JCX8Q3T6Y0V5W1Z2A3B4C5D6', 'DEP-2'], $ids);
        self::assertSame('status=completed&operation=deposit&from=2026-11-01&per_page=1&page=2', $this->lastRequest()->getUri()->getQuery());
    }

    public function testFindByReference(): void
    {
        $this->http->queue(self::json(200, ['data' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 1, 'total' => 0]]));

        self::assertNull($this->client()->payments->findByReference('ORDER-404'));
        self::assertSame('reference=ORDER-404&per_page=1', $this->lastRequest()->getUri()->getQuery());
    }

    /**
     * @return iterable<string, array{0: callable(\Labyrinthe\LabPay\Client): mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'zero amount' => [static fn (Client $c) => $c->deposits->create(amount: '0', currency: 'CDF', phone: '0813456789')];
        yield 'three decimals' => [static fn (Client $c) => $c->deposits->create(amount: '1.234', currency: 'CDF', phone: '0813456789')];
        yield 'float with three decimals' => [static fn (Client $c) => $c->deposits->create(amount: 1.234, currency: 'CDF', phone: '0813456789')];
        yield 'bad currency' => [static fn (Client $c) => $c->deposits->create(amount: '10', currency: 'FC', phone: '0813456789')];
        yield 'bad reference' => [static fn (Client $c) => $c->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789', reference: 'order 42')];
        yield 'http callback' => [static fn (Client $c) => $c->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789', callbackUrl: 'http://example.test')];
        yield 'short customer message' => [static fn (Client $c) => $c->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789', customerMessage: 'Hi')];
        yield 'too much metadata' => [static fn (Client $c) => $c->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789', metadata: array_fill(0, 21, 'x'))];
        yield 'per page' => [static fn (Client $c) => $c->payments->list(perPage: 101)];
        yield 'status filter' => [static fn (Client $c) => $c->payments->list(status: 'done')];
        yield 'date filter' => [static fn (Client $c) => $c->payments->list(from: '01/11/2026')];
    }

    /**
     * @param callable(\Labyrinthe\LabPay\Client): mixed $call
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testInvalidInputFailsBeforeAnyRequest(callable $call): void
    {
        try {
            $call($this->client());
            self::fail('InvalidArgumentException expected.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->http->requests);
        }
    }
}
