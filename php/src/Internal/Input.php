<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

use Labyrinthe\LabPay\Enum\NotificationStatus;
use Labyrinthe\LabPay\Enum\Operation;
use Labyrinthe\LabPay\Enum\PaymentStatus;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;

/**
 * Local checks of the input, mirroring the API validation rules
 * (`MobileMoneyPaymentRequest`, `InitiateRefundRequest`, `ListPaymentsRequest`),
 * so that obvious mistakes fail before any network call.
 *
 * @internal
 */
final class Input
{
    public const AMOUNT_PATTERN = '/^\d{1,16}(\.\d{1,2})?$/';
    public const REFERENCE_PATTERN = '/^[A-Za-z0-9._:\-]{1,100}$/';
    public const CUSTOMER_MESSAGE_PATTERN = '/^[A-Za-z0-9 ]{4,22}$/';
    public const MAX_METADATA_ENTRIES = 20;
    public const MAX_METADATA_VALUE_LENGTH = 255;
    public const MAX_PHONE_LENGTH = 20;
    public const MAX_PER_PAGE = 100;

    private function __construct()
    {
    }

    /**
     * "1500", "12.50", 1500 or 12.5 → "1500" / "12.50" / "1500" / "12.50".
     * Floats are accepted only when they have at most 2 decimals.
     */
    public static function amount(string|int|float $amount, string $field = 'amount'): string
    {
        if (\is_float($amount)) {
            if (!is_finite($amount) || round($amount, 2) !== $amount) {
                throw new InvalidArgumentException(\sprintf('%s must have at most 2 decimals; pass a string such as "12.50".', $field));
            }
            $amount = number_format($amount, 2, '.', '');
        }
        $value = trim((string) $amount);
        if (preg_match(self::AMOUNT_PATTERN, $value) !== 1 || preg_match('/^0+(\.0+)?$/', $value) === 1) {
            throw new InvalidArgumentException(\sprintf('%s must be a positive number with at most 2 decimals (e.g. "1500" or "12.50").', $field));
        }

        return $value;
    }

    public static function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new InvalidArgumentException('currency must be a 3-letter code, e.g. "CDF" or "USD".');
        }

        return $currency;
    }

    public static function country(?string $country, string $field = 'country'): ?string
    {
        if ($country === null || trim($country) === '') {
            return null;
        }
        $country = strtoupper(trim($country));
        if (preg_match('/^[A-Z]{3}$/', $country) !== 1) {
            throw new InvalidArgumentException(\sprintf('%s must be an ISO 3166-1 alpha-3 code, e.g. "COD".', $field));
        }

        return $country;
    }

    public static function phone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '' || \strlen($phone) > self::MAX_PHONE_LENGTH) {
            throw new InvalidArgumentException(\sprintf('phone is required and must be at most %d characters.', self::MAX_PHONE_LENGTH));
        }

        return $phone;
    }

    public static function provider(?string $provider): ?string
    {
        if ($provider === null || trim($provider) === '') {
            return null;
        }
        $provider = trim($provider);
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $provider) !== 1) {
            throw new InvalidArgumentException('provider must be a provider code, e.g. "VODACOM_MPESA_COD".');
        }

        return $provider;
    }

    public static function reference(?string $reference): ?string
    {
        if ($reference === null) {
            return null;
        }
        if (preg_match(self::REFERENCE_PATTERN, $reference) !== 1) {
            throw new InvalidArgumentException('reference must be 1 to 100 characters among A-Z a-z 0-9 . _ : -');
        }

        return $reference;
    }

    public static function customerMessage(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return null;
        }
        if (preg_match(self::CUSTOMER_MESSAGE_PATTERN, $message) !== 1) {
            throw new InvalidArgumentException('customerMessage must be 4 to 22 characters (letters, digits, spaces).');
        }

        return $message;
    }

