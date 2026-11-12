<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Send money to a Mobile Money wallet (`$client->payouts`, `POST /V2/payouts`).
 */
final class PayoutService extends MobileMoneyService
{
    /**
     * Sends `amount` to the number. Your balance in the currency is debited
     * immediately with amount + payout fees, and credited back in full if
     * the payout fails. Same parameters and reference rules as
     * $client->deposits->create().
     *
     * A decimal amount is refused (INVALID_AMOUNT) when the provider does not
     * accept decimals in the currency (e.g. Vodacom in CDF).
     *
     * @param array<array-key, mixed>|null $metadata
     *
     * @throws InvalidArgumentException before any call when the input is invalid
     * @throws ApiException             InsufficientBalanceException, PermissionException (KYC_REQUIRED…), InvalidRequestException…
     * @throws ConnectionException      the payout may have been created: resend with the same reference
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
        return $this->initiate('/payouts', $amount, $currency, $phone, $country, $provider, $reference, $callbackUrl, $customerMessage, $metadata);
    }

    /**
     * Cancels a payout still queued by the provider (`gateway_status: ENQUEUED`,
     * see Payment::isQueued()). Asynchronous: the returned payout then moves to
     * `failed` and your balance is credited back.
     *
     * Not retried automatically after a network error or a 5xx.
     *
     * @throws \Labyrinthe\LabPay\Exception\ConflictException          NOT_A_PAYOUT, NOT_CANCELLABLE
     * @throws \Labyrinthe\LabPay\Exception\NotFoundException          PAYMENT_NOT_FOUND
     * @throws \Labyrinthe\LabPay\Exception\ServiceUnavailableException GATEWAY_UNAVAILABLE: retry later
     * @throws ApiException
     * @throws ConnectionException
     */
    public function cancel(string $id): Payment
    {
        $response = $this->transport->request('POST', '/payouts/'.rawurlencode(Input::id($id)).'/cancel', RetryMode::OnlyWhenThrottled);

        return Payment::fromArray($response->data());
    }
}
