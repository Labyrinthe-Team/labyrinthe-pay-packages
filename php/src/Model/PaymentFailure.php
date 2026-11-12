<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * Why a payment failed (`failure` field), as passed on by LabPay.
 */
final class PaymentFailure
{
    public function __construct(
        public readonly string $code,
        public readonly ?string $message,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::string($data, 'code'), Data::nullableString($data, 'message'));
    }
}
