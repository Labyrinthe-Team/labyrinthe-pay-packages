<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * A validated phone number and its predicted provider (`POST /V2/predict-provider`).
 */
final class ProviderPrediction
{
    public function __construct(
        /** International format, without "+", e.g. 243813456789. */
        public readonly string $phone,
        public readonly string $provider,
        public readonly string $country,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Data::string($data, 'phone'), Data::string($data, 'provider'), Data::string($data, 'country'));
    }
}
