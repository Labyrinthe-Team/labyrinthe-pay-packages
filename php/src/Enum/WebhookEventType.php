<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Enum;

/**
 * Event of a notification (`X-Labyrinthe-Event` header and `event` field).
 */
enum WebhookEventType: string
{
    case PaymentCompleted = 'payment.completed';
    case PaymentFailed = 'payment.failed';

    /** Value sent by the API that this SDK version does not know yet. */
    case Unknown = 'unknown';

    public static function fromApi(?string $value): self
    {
        return $value === null ? self::Unknown : (self::tryFrom($value) ?? self::Unknown);
    }
}
