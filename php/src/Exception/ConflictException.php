<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 409: `NOT_A_PAYOUT`, `NOT_CANCELLABLE`, `DEPOSIT_NOT_COMPLETED`, `REFUND_IN_PROGRESS` or `DEPOSIT_ALREADY_REFUNDED`.
 */
class ConflictException extends ApiException
{
}
