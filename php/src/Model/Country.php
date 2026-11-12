<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * A country open to payments (`GET /V2/countries`).
 */
final class Country
{
    /**
     * @param list<string>                $currencies
     * @param array<string, AmountLimits> $limits     per currency
     */
    public function __construct(
        /** ISO 3166-1 alpha-3 code, e.g. COD. */
        public readonly string $country,
        public readonly ?string $name,
        public readonly ?string $dialCode,
        public readonly array $currencies,
        public readonly array $limits,
        /** Country of a local number sent without `country`. */
        public readonly bool $isDefault,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $limits = [];
        foreach (Data::optionalArray($data, 'limits') as $currency => $limit) {
            $limits[(string) $currency] = AmountLimits::fromArray(\is_array($limit) ? $limit : []);
        }

        return new self(
            country: Data::string($data, 'country'),
            name: Data::nullableString($data, 'name'),
            dialCode: Data::nullableString($data, 'dial_code'),
            currencies: Data::stringList($data, 'currencies'),
            limits: $limits,
            isDefault: Data::bool($data, 'default'),
        );
    }
}
