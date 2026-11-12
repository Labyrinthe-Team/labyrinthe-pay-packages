<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Enum;

/**
 * Public status of a payment. `completed` and `failed` are final: a payment no longer changes afterwards.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Failed = 'failed';

    /** Value sent by the API that this SDK version does not know yet. */
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }
}
