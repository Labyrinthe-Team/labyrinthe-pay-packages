<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use Labyrinthe\LabPay\Exception\AmountOutOfRangeException;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\AuthenticationException;
use Labyrinthe\LabPay\Exception\ConflictException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\DailyLimitReachedException;
use Labyrinthe\LabPay\Exception\InsufficientBalanceException;
use Labyrinthe\LabPay\Exception\InvalidPhoneNumberException;
use Labyrinthe\LabPay\Exception\InvalidRequestException;
use Labyrinthe\LabPay\Exception\LabPayException;
use Labyrinthe\LabPay\Exception\NotFoundException;
use Labyrinthe\LabPay\Exception\PermissionException;
use Labyrinthe\LabPay\Exception\RateLimitException;
use Labyrinthe\LabPay\Exception\ServiceUnavailableException;
use Labyrinthe\LabPay\Exception\UnexpectedValueException;
use Labyrinthe\LabPay\Exception\ValidationException;
use Labyrinthe\LabPay\Tests\Support\TestCase;

final class ErrorsAndRetryTest extends TestCase
{
    /**
     * @return iterable<string, array{0: int, 1: string|null, 2: class-string<ApiException>}>
     */
    public static function errors(): iterable
    {
        yield 'unauthenticated' => [401, 'UNAUTHENTICATED', AuthenticationException::class];
        yield 'kyc' => [403, 'KYC_REQUIRED', PermissionException::class];
        yield 'live token' => [403, 'LIVE_TOKEN_REQUIRED', PermissionException::class];
        yield 'not found' => [404, 'PAYMENT_NOT_FOUND', NotFoundException::class];
        yield 'refund in progress' => [409, 'REFUND_IN_PROGRESS', ConflictException::class];
        yield 'phone' => [422, 'INVALID_PHONE_NUMBER', InvalidPhoneNumberException::class];
        yield 'currency' => [422, 'INVALID_CURRENCY', InvalidRequestException::class];
        yield 'amount' => [422, 'AMOUNT_TOO_LARGE', AmountOutOfRangeException::class];
        yield 'balance' => [422, 'INSUFFICIENT_BALANCE', InsufficientBalanceException::class];
        yield 'daily limit' => [429, 'DAILY_LIMIT_REACHED', DailyLimitReachedException::class];
        yield 'maintenance' => [503, 'MAINTENANCE', ServiceUnavailableException::class];
        yield 'unknown code' => [418, 'SOMETHING_NEW', ApiException::class];
        yield 'unknown 409 code' => [409, 'SOMETHING_NEW', ConflictException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('errors')]
    public function testErrorsAreTyped(int $status, ?string $code, string $class): void
    {
        $this->http->queue(self::json($status, ['message' => 'Message en français', 'code' => $code]));

        try {
            $this->client(maxRetries: 0)->payments->get('DEP-1');
            self::fail('Exception expected.');
        } catch (ApiException $e) {
            self::assertSame($class, $e::class);
            self::assertSame($code, $e->getErrorCode());
            self::assertSame($status, $e->getStatusCode());
            self::assertSame('Message en français', $e->getMessage());
            self::assertSame('test', $e->getMode());
        }
    }

    public function testValidationErrorsAreExposed(): void
    {
        $this->http->queue(self::json(422, [
            'message' => 'Le montant doit être un nombre positif.',
            'code' => 'VALIDATION_ERROR',
            'errors' => ['amount' => ['Le montant doit être un nombre positif.']],
        ]));

        try {
            $this->client()->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789');
            self::fail('ValidationException expected.');
        } catch (ValidationException $e) {
            self::assertSame(['amount' => ['Le montant doit être un nombre positif.']], $e->getErrors());
        }
    }

    public function testFilterErrorsWithoutCodeAreValidationErrors(): void
    {
        $this->http->queue(self::json(422, ['message' => 'The selected status is invalid.', 'errors' => ['status' => ['The selected status is invalid.']]]));

        $this->expectException(ValidationException::class);
        $this->client()->notifications->list();
    }

    public function testRateLimitIsRetriedAfterRetryAfter(): void
    {
        $this->http->queue(
            self::json(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '3']),
            self::json(200, ['data' => self::payment()]),
        );

        $this->client()->payments->get('DEP-1');

        self::assertCount(2, $this->http->requests);
        self::assertSame([3.0], $this->sleeper->sleeps);
    }

    public function testRateLimitLongerThanMaxWaitIsThrown(): void
    {
        $this->http->queue(self::json(429, ['message' => 'Too Many Attempts.'], ['Retry-After' => '60']));

        try {
            $this->client()->payments->get('DEP-1');
            self::fail('RateLimitException expected.');
        } catch (RateLimitException $e) {
            self::assertSame('TOO_MANY_REQUESTS', $e->getErrorCode());
            self::assertSame(60, $e->getRetryAfter());
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testDailyLimitIsNeverRetried(): void
    {
        $this->http->queue(self::json(429, ['message' => 'Limite journalière', 'code' => 'DAILY_LIMIT_REACHED'], ['Retry-After' => '1']));

        $this->expectException(DailyLimitReachedException::class);
        try {
            $this->client()->deposits->create(amount: '10', currency: 'CDF', phone: '0813456789');
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testServerErrorsOnReadsAreRetriedWithBackoff(): void
    {
        $this->http->queue(
            self::json(503, ['message' => 'Indisponible', 'code' => 'GATEWAY_UNAVAILABLE']),
            new Response(502, [], '<html>Bad gateway</html>'),
            self::json(200, ['data' => []]),
        );

        self::assertSame([], $this->client()->providers->list());
        self::assertCount(3, $this->http->requests);
        self::assertCount(2, $this->sleeper->sleeps);
    }

    public function testMaintenanceIsOnlyRetriedWithRetryAfter(): void
    {
        $this->http->queue(self::json(503, ['message' => 'Maintenance', 'code' => 'MAINTENANCE']));

        $this->expectException(ServiceUnavailableException::class);
        try {
            $this->client()->payments->get('DEP-1');
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testPaymentWithReferenceIsRetriedAfterANetworkError(): void
    {
        $this->http->queue(null, self::json(201, ['data' => self::payment()]));

        $this->client()->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789', reference: 'ORDER-42');

        self::assertCount(2, $this->http->requests);
        // The same body (same reference) is sent again.
        self::assertSame((string) $this->http->requests[0]->getBody(), (string) $this->http->requests[1]->getBody());
    }

    public function testPaymentWithGeneratedReferenceKeepsItAcrossRetries(): void
    {
        $this->http->queue(new Response(500), self::json(201, ['data' => self::payment()]));

        $this->client()->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789');

        self::assertCount(2, $this->http->requests);
        self::assertSame((string) $this->http->requests[0]->getBody(), (string) $this->http->requests[1]->getBody());
    }

    public function testPaymentWithoutReferenceIsNotRetriedAfterANetworkError(): void
    {
        $this->http->queue(null);

        try {
            $this->client(autoReference: false)->deposits->create(amount: '1000', currency: 'CDF', phone: '0813456789');
            self::fail('ConnectionException expected.');
        } catch (ConnectionException $e) {
            self::assertTrue($e->isTimeout());
            self::assertStringContainsString('may have been processed', $e->getMessage());
            self::assertStringNotContainsString('0813456789', $e->getMessage());
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testRotationsAreNeverRetriedAfterAServerError(): void
    {
        $this->http->queue(new Response(500));

        $this->expectException(ServiceUnavailableException::class);
        try {
            $this->client('live_token_abcdefgh')->account->rotateWebhookSecret();
        } finally {
            self::assertCount(1, $this->http->requests);
        }
    }

    public function testInvalidJsonIsAnUnexpectedValue(): void
    {
        $this->http->queue(new Response(200, [], 'not json'));

        $this->expectException(UnexpectedValueException::class);
        $this->client()->account->get();
    }
}
