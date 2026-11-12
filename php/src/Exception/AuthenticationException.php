<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 401 `UNAUTHENTICATED`: missing or unknown API token / test key.
 */
class AuthenticationException extends ApiException
{
}
