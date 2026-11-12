<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 404: `PAYMENT_NOT_FOUND`, `DEPOSIT_NOT_FOUND` or `NOTIFICATION_NOT_FOUND` (for this token and this mode).
 */
class NotFoundException extends ApiException
{
}
