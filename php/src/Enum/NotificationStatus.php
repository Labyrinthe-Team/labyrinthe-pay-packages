<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Enum;

/**
 * Delivery status of a notification sent to a `callback_url`.
 */
enum NotificationStatus: string
{
    case Delivered = 'delivered';
    case Pending = 'pending';
    case Abandoned = 'abandoned';

    /** Value sent by the API that this SDK version does not know yet. */
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }
}
