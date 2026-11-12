<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Events;

use Labyrinthe\LabPay\Model\Payment;
use Labyrinthe\LabPay\Webhook\Event;

/**
 * Any verified LabPay notification (dispatched before PaymentCompleted / PaymentFailed).
 */
class WebhookReceived
{
    public function __construct(
        public readonly Event $event,
        /** X-Labyrinthe-Delivery: unique notification id, to deduplicate redeliveries. */
        public readonly ?string $deliveryId,
        /** X-Labyrinthe-Mode: "live" or "test". */
        public readonly ?string $mode,
    ) {
    }

    public function payment(): Payment
    {
        return $this->event->payment;
    }

    public function isTest(): bool
    {
        return $this->mode === 'test' || !$this->event->payment->livemode;
    }
}
