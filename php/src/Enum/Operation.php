<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Enum;

/**
 * Kind of payment: collection from a customer, payout to a wallet, or refund of a deposit.
 */
enum Operation: string
{
    case Deposit = 'deposit';
    case Payout = 'payout';
    case Refund = 'refund';

    /** Value sent by the API that this SDK version does not know yet. */
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }
}
