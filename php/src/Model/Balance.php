<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * Balance of one currency, in the mode of the key used.
 */
final class Balance
{
    public function __construct(
        public readonly string $currency,
        /** String with 2 decimals, e.g. "125000.00". */
        public readonly string $balance,
        public readonly ?\DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data, 'currency'),
            Data::string($data, 'balance'),
            Data::nullableDateTime($data, 'updated_at'),
        );
    }
}
