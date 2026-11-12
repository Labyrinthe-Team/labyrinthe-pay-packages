<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Enum\NotificationStatus;
use Labyrinthe\LabPay\Enum\WebhookEventType;
use Labyrinthe\LabPay\Internal\Data;

/**
 * A notification sent (or being sent) to a `callback_url` (`GET /V2/notifications`).
 */
final class Notification
{
    public function __construct(
        /** Also the X-Labyrinthe-Delivery header of the delivery. */
        public readonly int $id,
        public readonly WebhookEventType $event,
        public readonly ?string $paymentId,
        public readonly ?string $url,
        public readonly NotificationStatus $status,
        public readonly int $attempts,
        public readonly ?int $lastStatusCode,
        public readonly ?string $lastError,
        public readonly ?\DateTimeImmutable $nextAttemptAt,
        public readonly ?\DateTimeImmutable $deliveredAt,
        public readonly ?\DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: Data::int($data, 'id'),
            event: WebhookEventType::fromApi(Data::nullableString($data, 'event')),
            paymentId: Data::nullableString($data, 'payment_id'),
            url: Data::nullableString($data, 'url'),
            status: NotificationStatus::fromApi(Data::nullableString($data, 'status')),
            attempts: Data::nullableInt($data, 'attempts') ?? 0,
            lastStatusCode: Data::nullableInt($data, 'last_status_code'),
            lastError: Data::nullableString($data, 'last_error'),
            nextAttemptAt: Data::nullableDateTime($data, 'next_attempt_at'),
            deliveredAt: Data::nullableDateTime($data, 'delivered_at'),
            createdAt: Data::nullableDateTime($data, 'created_at'),
        );
    }
}
