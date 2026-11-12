<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * 429 `DAILY_LIMIT_REACHED`: the token is not enabled for production yet
 * (`live_enabled: false`) and live collections reached the daily limit.
 * Never retried automatically.
 */
class DailyLimitReachedException extends RateLimitException
{
}
