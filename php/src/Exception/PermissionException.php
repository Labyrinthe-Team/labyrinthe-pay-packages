<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 403: `TOKEN_BLOCKED`, `MERCHANT_INACTIVE`, `KYC_REQUIRED` or `LIVE_TOKEN_REQUIRED` (operation reserved to the live token).
 */
class PermissionException extends ApiException
{
}
