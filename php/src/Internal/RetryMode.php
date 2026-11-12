<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

/**
 * When a request may be sent again automatically.
 *
 * In both modes, a 429 from the rate limiter (no `code`) is retried after
 * Retry-After (when no longer than maxRetryWait), and a 503 that refuses the
 * request before any processing (MAINTENANCE, DEPOSITS_DISABLED…) is only
 * retried when the API gives such a Retry-After; never with a blind backoff.
 *
 * @internal
 */
enum RetryMode
{
    /**
     * Replaying cannot cause a side effect twice: GET requests,
     * POST /predict-provider, POST /payments/{id}/sync, and POST /deposits,
     * /payouts, /refunds when they carry a `reference` (the API returns the
     * existing payment). Also retried on network errors and other 5xx.
     */
    case Safe;

    /**
     * A replay could have a visible effect (rotations, notification resend,
     * payout cancellation, payment without reference): never retried after
     * a network error or a 5xx, the request may have been processed.
     */
    case OnlyWhenThrottled;
}
