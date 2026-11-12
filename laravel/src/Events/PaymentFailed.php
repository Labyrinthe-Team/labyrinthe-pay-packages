<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Events;

/**
 * `payment.failed`: failed or cancelled (see payment()->failure). For a payout
 * or a refund, your balance was credited back.
 */
final class PaymentFailed extends WebhookReceived
{
}
