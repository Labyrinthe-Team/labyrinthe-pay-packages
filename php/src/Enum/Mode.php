<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Enum;

/**
 * Mode of the key used: `live` (real money) or `test` (sandbox, separate balances).
 */
enum Mode: string
{
    case Live = 'live';
    case Test = 'test';

    /** Value sent by the API that this SDK version does not know yet. */
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }
}
