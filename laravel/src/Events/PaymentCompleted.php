<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Events;

/**
 * `payment.completed`: deposit credited to your balance, or payout / refund sent.
 * Deliver the order here, once per payment id.
 */
final class PaymentCompleted extends WebhookReceived
{
}
