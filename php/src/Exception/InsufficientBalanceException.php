<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 422 `INSUFFICIENT_BALANCE`: the merchant balance in this currency does not cover the payout or the refund. Nothing was sent.
 */
class InsufficientBalanceException extends InvalidRequestException
{
}
