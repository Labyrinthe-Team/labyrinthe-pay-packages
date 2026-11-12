<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * A Mobile Money provider and its rules for one operation (`GET /V2/providers`).
 */
final class Provider
{
    /**
     * @param array<string, ProviderCurrency> $currencies rules per currency
     * @param array<mixed>                    $raw
     */
    public function __construct(
        /** Provider code, to pass as `provider`, e.g. VODACOM_MPESA_COD. */
        public readonly string $provider,
        public readonly ?string $country,
        public readonly ?string $name,
        public readonly ?string $nameDisplayedToCustomer,
        public readonly ?string $logo,
        public readonly array $currencies,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $currencies = [];
        foreach (Data::optionalArray($data, 'currencies') as $currency => $rules) {
            $currencies[(string) $currency] = ProviderCurrency::fromArray(\is_array($rules) ? $rules : []);
        }

        return new self(
            provider: Data::string($data, 'provider'),
            country: Data::nullableString($data, 'country'),
            name: Data::nullableString($data, 'name'),
            nameDisplayedToCustomer: Data::nullableString($data, 'name_displayed_to_customer'),
            logo: Data::nullableString($data, 'logo'),
            currencies: $currencies,
            raw: $data,
        );
    }
}
