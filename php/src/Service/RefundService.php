<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\Reference;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Refund a deposit (`$client->refunds`, `POST /V2/refunds`).
 */
final class RefundService
{
    /**
     * @internal use $client->refunds
     */
    public function __construct(
        private readonly Transport $transport,
        private readonly bool $autoReference,
    ) {
    }

    /**
     * Refunds all or part of a completed V2 deposit, to the wallet that paid.
     * The refundable amount is the net amount credited to you (fees are not
     * refunded); only one refund in progress at a time per deposit. Your
     * balance is debited at initiation and credited back if the refund fails.
     *
     * @param string                       $paymentId id of the deposit (DEP-…)
     * @param string|int|float|null        $amount    default: everything left to refund
     * @param string|null                  $reference your reference (generated when null, see deposits->create())
     * @param array<array-key, mixed>|null $metadata
     *
     * @throws InvalidArgumentException before any call when the input is invalid
     * @throws ApiException             NotFoundException (DEPOSIT_NOT_FOUND), ConflictException (DEPOSIT_NOT_COMPLETED,
     *                                  REFUND_IN_PROGRESS, DEPOSIT_ALREADY_REFUNDED), AmountOutOfRangeException,
     *                                  InsufficientBalanceException…
     * @throws ConnectionException      the refund may have been created: resend with the same reference
     */
    public function create(
        string $paymentId,
        string|int|float|null $amount = null,
        ?string $reference = null,
        ?string $callbackUrl = null,
        ?array $metadata = null,
    ): Payment {
        $reference = Input::reference($reference) ?? ($this->autoReference ? Reference::generate() : null);

        $body = array_filter([
            'payment_id' => Input::id($paymentId, 'paymentId'),
            'amount' => $amount === null ? null : Input::amount($amount),
            'reference' => $reference,
            'callback_url' => Input::callbackUrl($callbackUrl),
            'metadata' => Input::metadata($metadata),
        ], static fn (mixed $v): bool => $v !== null);

        $mode = $reference !== null ? RetryMode::Safe : RetryMode::OnlyWhenThrottled;
        $response = $this->transport->request('POST', '/refunds', $mode, body: $body, paymentOnRefusal: true);

        return Payment::fromArray($response->data());
    }
}
