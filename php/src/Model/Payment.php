<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Enum\Operation;
use Labyrinthe\LabPay\Enum\PaymentStatus;
use Labyrinthe\LabPay\Internal\Data;

/**
 * A V2 payment: deposit (`DEP-…`), payout (`PAY-…`) or refund (`RFD-…`).
 * Same shape in API responses and in notifications.
 *
 * Amounts are strings with 2 decimals ("1500.00"), never floats.
 */
final class Payment
{
    /**
     * @param array<string, string|null>|null $metadata
     * @param array<mixed>                    $raw      the payment as returned by the API
     */
    public function __construct(
        /** LabPay identifier: DEP-… (deposit), PAY-… (payout), RFD-… (refund). */
        public readonly string $id,
        /** Your reference (unique per token and mode), or null. */
        public readonly ?string $reference,
        public readonly Operation $operation,
        /** False for a test payment (sandbox). */
        public readonly bool $livemode,
        public readonly PaymentStatus $status,
        /** Raw aggregator status, informational (e.g. ACCEPTED, ENQUEUED). Rely on $status. */
        public readonly ?string $gatewayStatus,
        /** Deposit: credited to the merchant. Payout: received by the recipient. Refund: refunded. */
        public readonly string $amount,
        /** LabPay fees. */
        public readonly ?string $fee,
        /** Deposit: paid by the customer. Payout: debited from the merchant balance. */
        public readonly ?string $totalAmount,
        public readonly string $currency,
        /** ISO 3166-1 alpha-3 country of the number, e.g. COD. */
        public readonly ?string $country,
        /** Phone number, in the country's local format. */
        public readonly ?string $phone,
        /** Provider code, e.g. VODACOM_MPESA_COD. */
        public readonly ?string $provider,
        /** Provider reference (the one in the customer's SMS), or null. */
        public readonly ?string $providerTransactionId,
        public readonly ?PaymentFailure $failure,
        public readonly ?array $metadata,
        public readonly ?\DateTimeImmutable $createdAt,
        /** Final status date, null while pending. */
        public readonly ?\DateTimeImmutable $completedAt,
        /** Deposits only: amount already refunded or being refunded. */
        public readonly ?string $refundedAmount,
        /** Refunds only: id of the refunded deposit. */
        public readonly ?string $refundedPaymentId,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
