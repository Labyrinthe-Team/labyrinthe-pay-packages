<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * Rules of a provider for one currency and one operation.
 */
final class ProviderCurrency
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public readonly ?string $minAmount,
        public readonly ?string $maxAmount,
        /** Decimals accepted in the amount (e.g. "NONE": whole amount only). */
        public readonly ?string $decimals,
        /** Status of the operation for this provider. */
        public readonly ?string $status,
        public readonly ?string $pinPrompt,
        public readonly ?string $pinPromptRevivable,
        /** Instructions to show the customer (structure passed on as is). */
        public readonly mixed $pinPromptInstructions,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            minAmount: Data::nullableString($data, 'min_amount'),
            maxAmount: Data::nullableString($data, 'max_amount'),
            decimals: Data::nullableString($data, 'decimals'),
            status: Data::nullableString($data, 'status'),
            pinPrompt: self::scalar($data['pin_prompt'] ?? null),
            pinPromptRevivable: self::scalar($data['pin_prompt_revivable'] ?? null),
            pinPromptInstructions: $data['pin_prompt_instructions'] ?? null,
            raw: $data,
        );
    }

    private static function scalar(mixed $value): ?string
    {
        return match (true) {
            \is_bool($value) => $value ? 'true' : 'false',
            \is_string($value), \is_int($value), \is_float($value) => (string) $value,
            default => null,
        };
    }
}
