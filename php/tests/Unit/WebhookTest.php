<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Unit;

use Labyrinthe\LabPay\Enum\WebhookEventType;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Exception\InvalidWebhookPayloadException;
use Labyrinthe\LabPay\Exception\SignatureVerificationException;
use Labyrinthe\LabPay\Tests\Support\TestCase;
use Labyrinthe\LabPay\Webhook\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1_794_391_243;

    private static function body(): string
    {
        // Same encoding as the engine (JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).
        return json_encode([
            'event' => 'payment.completed',
            'payment' => self::payment(['status' => 'completed', 'gateway_status' => 'COMPLETED', 'completed_at' => '2026-11-11T10:00:42+00:00']),
            'sent_at' => '2026-11-11T10:00:43+00:00',
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    public function testSignatureMatchesTheEngineAlgorithm(): void
    {
        $body = self::body();
        // MerchantNotifier::signatureHeader(): "t=<ts>,v1=" . hash_hmac('sha256', "<ts>.<body>", secret)
        $expected = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$body, self::SECRET);

        self::assertSame($expected, Webhook::sign($body, self::SECRET, self::NOW));
    }

    public function testConstructEvent(): void
    {
        $body = self::body();

        $event = Webhook::constructEvent($body, Webhook::sign($body, self::SECRET, self::NOW), self::SECRET, now: self::NOW + 10);

        self::assertSame(WebhookEventType::PaymentCompleted, $event->type);
        self::assertTrue($event->isPaymentCompleted());
        self::assertTrue($event->payment->isCompleted());
        self::assertSame(self::NOW, $event->timestamp);
        self::assertSame('2026-11-11T10:00:43+00:00', $event->sentAt?->format(\DATE_ATOM));
    }

    public function testEitherSignatureIsAcceptedDuringARotation(): void
    {
        $body = self::body();
        $header = Webhook::sign($body, ['whsec_new', self::SECRET], self::NOW);

        self::assertSame(2, substr_count($header, 'v1='));
        self::assertTrue(Webhook::isValid($body, $header, self::SECRET, now: self::NOW));
        self::assertTrue(Webhook::isValid($body, $header, 'whsec_new', now: self::NOW));
        self::assertFalse(Webhook::isValid($body, $header, 'whsec_other', now: self::NOW));
        self::assertTrue(Webhook::isValid($body, Webhook::sign($body, self::SECRET, self::NOW), ['whsec_new', self::SECRET], now: self::NOW));
    }

    public function testTamperedBodyIsRefused(): void
    {
        $body = self::body();
        $header = Webhook::sign($body, self::SECRET, self::NOW);

        $this->expectException(SignatureVerificationException::class);
        Webhook::constructEvent(str_replace('1000.00', '9000.00', $body), $header, self::SECRET, now: self::NOW);
    }

    public function testOldTimestampIsRefused(): void
    {
        $body = self::body();
        $header = Webhook::sign($body, self::SECRET, self::NOW);

        $this->expectException(SignatureVerificationException::class);
        $this->expectExceptionMessage('tolerance');
        Webhook::constructEvent($body, $header, self::SECRET, now: self::NOW + 301);
    }

    public function testToleranceCanBeDisabled(): void
    {
        $body = self::body();

        self::assertTrue(Webhook::isValid($body, Webhook::sign($body, self::SECRET, 1), self::SECRET, 0, self::NOW));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function malformedHeaders(): iterable
    {
        yield 'empty' => [''];
        yield 'no timestamp' => ['v1='.str_repeat('a', 64)];
        yield 'no signature' => ['t=123'];
        yield 'short signature' => ['t=123,v1=abc'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function testMalformedHeadersAreRefused(string $header): void
    {
        $this->expectException(SignatureVerificationException::class);
        Webhook::verify(self::body(), $header, self::SECRET, now: self::NOW);
    }

    public function testSignedButInvalidPayload(): void
    {
        $body = '{"event":"payment.completed"}';

        $this->expectException(InvalidWebhookPayloadException::class);
        Webhook::constructEvent($body, Webhook::sign($body, self::SECRET, self::NOW), self::SECRET, now: self::NOW);
    }

    public function testUnknownEventIsKept(): void
    {
        $body = json_encode(['event' => 'payment.something', 'payment' => self::payment(), 'sent_at' => null], \JSON_THROW_ON_ERROR);

        $event = Webhook::constructEvent($body, Webhook::sign($body, self::SECRET, self::NOW), self::SECRET, now: self::NOW);

        self::assertSame(WebhookEventType::Unknown, $event->type);
        self::assertSame('payment.something', $event->name);
    }

    public function testEmptySecretIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Webhook::verify('{}', 't=1,v1='.str_repeat('a', 64), '');
    }
}
