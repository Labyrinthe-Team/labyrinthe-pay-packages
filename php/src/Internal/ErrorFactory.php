<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

use Labyrinthe\LabPay\Exception\AmountOutOfRangeException;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\AuthenticationException;
use Labyrinthe\LabPay\Exception\ConflictException;
use Labyrinthe\LabPay\Exception\DailyLimitReachedException;
use Labyrinthe\LabPay\Exception\InsufficientBalanceException;
use Labyrinthe\LabPay\Exception\InvalidPhoneNumberException;
use Labyrinthe\LabPay\Exception\InvalidRequestException;
use Labyrinthe\LabPay\Exception\NotFoundException;
use Labyrinthe\LabPay\Exception\PermissionException;
use Labyrinthe\LabPay\Exception\RateLimitException;
use Labyrinthe\LabPay\Exception\ServiceUnavailableException;
use Labyrinthe\LabPay\Exception\ValidationException;

/**
 * Maps an error response to a typed exception: by error code first, then by
 * HTTP status, then a generic ApiException (unknown codes are expected).
 *
 * @internal
 */
final class ErrorFactory
{
    /** Code given by the SDK to a 429 of the rate limiter (the API sends no code). */
    public const RATE_LIMITED = 'TOO_MANY_REQUESTS';

    /** Code given by the SDK when the API sent none. */
    public const HTTP_ERROR = 'HTTP_ERROR';

    /** @var array<string, class-string<ApiException>> */
    private const BY_CODE = [
        'UNAUTHENTICATED' => AuthenticationException::class,
        'TOKEN_BLOCKED' => PermissionException::class,
        'MERCHANT_INACTIVE' => PermissionException::class,
        'KYC_REQUIRED' => PermissionException::class,
        'LIVE_TOKEN_REQUIRED' => PermissionException::class,
        'PAYMENT_NOT_FOUND' => NotFoundException::class,
        'DEPOSIT_NOT_FOUND' => NotFoundException::class,
        'NOTIFICATION_NOT_FOUND' => NotFoundException::class,
        'NOT_A_PAYOUT' => ConflictException::class,
        'NOT_CANCELLABLE' => ConflictException::class,
        'DEPOSIT_NOT_COMPLETED' => ConflictException::class,
        'REFUND_IN_PROGRESS' => ConflictException::class,
        'DEPOSIT_ALREADY_REFUNDED' => ConflictException::class,
        'VALIDATION_ERROR' => ValidationException::class,
        'INVALID_PHONE_NUMBER' => InvalidPhoneNumberException::class,
        'UNSUPPORTED_COUNTRY' => InvalidRequestException::class,
        'INVALID_CURRENCY' => InvalidRequestException::class,
        'UNSUPPORTED_PROVIDER' => InvalidRequestException::class,
        'INVALID_AMOUNT' => InvalidRequestException::class,
        'AMOUNT_TOO_SMALL' => AmountOutOfRangeException::class,
        'AMOUNT_TOO_LARGE' => AmountOutOfRangeException::class,
        'AMOUNT_OUT_OF_BOUNDS' => AmountOutOfRangeException::class,
        'INSUFFICIENT_BALANCE' => InsufficientBalanceException::class,
        'DAILY_LIMIT_REACHED' => DailyLimitReachedException::class,
        'MAINTENANCE' => ServiceUnavailableException::class,
        'API_DISABLED' => ServiceUnavailableException::class,
        'DEPOSITS_DISABLED' => ServiceUnavailableException::class,
        'WITHDRAWALS_DISABLED' => ServiceUnavailableException::class,
        'PROVIDER_TEMPORARILY_UNAVAILABLE' => ServiceUnavailableException::class,
        'GATEWAY_UNAVAILABLE' => ServiceUnavailableException::class,
        'V2_DISABLED' => ServiceUnavailableException::class,
        'TEST_MODE_DISABLED' => ServiceUnavailableException::class,
        'API_VERSION_DISABLED' => ServiceUnavailableException::class,
        'FEATURE_DISABLED' => ServiceUnavailableException::class,
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $headers lower-case names
     */
    public static function fromResponse(int $status, string $rawBody, array $headers): ApiException
    {
        try {
            $decoded = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $decoded = null;
        }
        $decoded = \is_array($decoded) ? $decoded : [];

        $code = isset($decoded['code']) && \is_string($decoded['code']) && $decoded['code'] !== '' ? $decoded['code'] : null;
        $message = isset($decoded['message']) && \is_string($decoded['message']) && $decoded['message'] !== '' ? $decoded['message'] : null;
        $errors = self::errors($decoded['errors'] ?? null);

        $code ??= match (true) {
            $status === 429 => self::RATE_LIMITED,
            $status === 422 && $errors !== [] => 'VALIDATION_ERROR',
            default => self::HTTP_ERROR,
        };
        $message ??= \sprintf('The LabPay API returned HTTP %d.', $status);

        $class = self::BY_CODE[$code] ?? self::classForStatus($status);

        return new $class($message, $code, $status, $errors, $headers);
    }

    /**
     * @return array<string, list<string>>
     */
    private static function errors(mixed $errors): array
    {
        if (!\is_array($errors)) {
            return [];
        }

        $result = [];
        foreach ($errors as $field => $messages) {
            $list = [];
            foreach (\is_array($messages) ? $messages : [$messages] as $message) {
                if (\is_string($message)) {
                    $list[] = $message;
                }
            }
            $result[(string) $field] = $list;
        }

        return $result;
    }

    /**
     * @return class-string<ApiException>
     */
    private static function classForStatus(int $status): string
    {
        return match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => PermissionException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 422 => InvalidRequestException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServiceUnavailableException::class,
            default => ApiException::class,
        };
    }
}
