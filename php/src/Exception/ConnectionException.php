<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * The API could not be reached, or did not answer in time.
 *
 * For a deposit, payout or refund this does NOT mean the payment failed:
 * it may have been created. Send the same request again with the same
 * `reference` (the API returns the existing payment), or look it up with
 * $client->payments->list(reference: …).
 */
class ConnectionException extends \RuntimeException implements LabPayException
{
    public function __construct(string $message, private readonly bool $timeout = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** True when the request timed out (the API may still have processed it). */
    public function isTimeout(): bool
    {
        return $this->timeout;
    }
}
