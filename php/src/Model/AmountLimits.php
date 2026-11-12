<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * Minimum and maximum amounts of a currency (null when not defined).
 */
final class AmountLimits
{
    public function __construct(
        public readonly ?string $minAmount,
        public readonly ?string $maxAmount,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::nullableString($data, 'min_amount'), Data::nullableString($data, 'max_amount'));
    }
}
