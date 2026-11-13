<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 5xx: `MAINTENANCE`, `API_DISABLED`, `DEPOSITS_DISABLED`, `WITHDRAWALS_DISABLED`, `PROVIDER_TEMPORARILY_UNAVAILABLE`, `GATEWAY_UNAVAILABLE`, `V2_DISABLED`, `TEST_MODE_DISABLED`, `API_VERSION_DISABLED`, `FEATURE_DISABLED` or a server error.
 */
class ServiceUnavailableException extends ApiException
{
}
