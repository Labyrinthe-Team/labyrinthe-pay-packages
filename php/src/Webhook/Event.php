<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Webhook;

use Labyrinthe\LabPay\Enum\WebhookEventType;
use Labyrinthe\LabPay\Model\Payment;

/**
 * A verified notification: `{ "event", "payment", "sent_at" }`.
 *
 * The same notification can arrive more than once (redelivery): deduplicate
 * on the X-Labyrinthe-Delivery header or on $payment->id. Arrival order is
 * not guaranteed: when in doubt, read the payment.
 */
final class Event
{
    /**
     * @param array<mixed> $raw the decoded body
     */
    public function __construct(
        public readonly WebhookEventType $type,
        /** The event name as sent ("payment.completed", "payment.failed", or a newer one). */
        public readonly string $name,
        public readonly Payment $payment,
        public readonly ?\DateTimeImmutable $sentAt,
        /** Signed Unix timestamp of the delivery (`t` of X-Labyrinthe-Signature). */
        public readonly int $timestamp,
        public readonly array $raw,
    ) {
    }

    public function isPaymentCompleted(): bool
    {
        return $this->type === WebhookEventType::PaymentCompleted;
    }

    public function isPaymentFailed(): bool
    {
        return $this->type === WebhookEventType::PaymentFailed;
    }
}
