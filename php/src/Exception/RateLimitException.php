<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 429: too many requests for this token (120 per minute). getRetryAfter()
 * gives the seconds to wait.
 */
class RateLimitException extends ApiException
{
}
