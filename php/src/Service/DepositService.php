<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Collect from a customer (`$client->deposits`, `POST /V2/deposits`).
 */
final class DepositService extends MobileMoneyService
{
    /**
     * Asks the customer to pay: they get a PIN prompt on their phone. The
     * returned payment is usually `pending`; its final status arrives by
     * notification on `callbackUrl` (or read it with $client->payments->get()).
     * Only deliver an order on `completed`.
     *
     * A payment refused at initiation is returned with the `failed` status
     * (and `failure`), not thrown: it was recorded, with its id.
     *
     * Without `reference`, the SDK generates one ("sdk_<uuid>") so that it can
     * retry safely after a network error: the API returns the existing
     * payment instead of charging twice. Pass your own (e.g. "ORDER-42") to
     * also protect your own retries.
     *
     * @param string|int|float             $amount          net amount credited to you, > 0, at most 2 decimals
     *                                                      ("1500", "12.50"); the customer pays it plus fees
     * @param string                       $currency        "CDF" or "USD"
     * @param string                       $phone           local ("0812345678") or international ("+243812345678")
     * @param string|null                  $country         ISO alpha-3 country of the number (default: DR Congo for a local number)
     * @param string|null                  $provider        provider code (inferred from the number when null)
     * @param string|null                  $reference       your reference, unique per token: 1 to 100 of A-Z a-z 0-9 . _ : -
     * @param string|null                  $callbackUrl     public HTTPS URL receiving the final status notification
     * @param string|null                  $customerMessage text shown to the customer, 4 to 22 letters, digits, spaces
     * @param array<array-key, mixed>|null $metadata        up to 20 key/value pairs (strings ≤ 255), returned as is
     *
     * @throws InvalidArgumentException before any call when the input is invalid
     * @throws ApiException             PermissionException (KYC_REQUIRED…), InvalidRequestException, AmountOutOfRangeException,
     *                                  DailyLimitReachedException, ServiceUnavailableException…
     * @throws ConnectionException      the payment may have been created: resend with the same reference
     */
    public function create(
        string|int|float $amount,
        string $currency,
        string $phone,
        ?string $country = null,
        ?string $provider = null,
        ?string $reference = null,
        ?string $callbackUrl = null,
        ?string $customerMessage = null,
        ?array $metadata = null,
    ): Payment {
        return $this->initiate('/deposits', $amount, $currency, $phone, $country, $provider, $reference, $callbackUrl, $customerMessage, $metadata);
    }
}
