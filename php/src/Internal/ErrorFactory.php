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
    ];

    private function __construct()
