<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

/**
 * Availability of a provider (`GET /V2/availability`): a status per
 * operation type (`DEPOSIT`, `PAYOUT`…): `OPERATIONAL`, `DELAYED` (payouts
 * queued) or `CLOSED`.
 */
final class ProviderAvailability
{
    public const OPERATIONAL = 'OPERATIONAL';
    public const DELAYED = 'DELAYED';
    public const CLOSED = 'CLOSED';

    /**
     * @param array<string, string|null> $statuses per operation type, e.g. ["DEPOSIT" => "OPERATIONAL"]
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?string $country,
        public readonly array $statuses,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(string $provider, array $data): self
    {
        $country = $data['country'] ?? null;
        $statuses = [];
        foreach ($data as $type => $status) {
            if ($type !== 'country') {
                $statuses[(string) $type] = \is_string($status) ? $status : null;
            }
        }

        return new self($provider, \is_string($country) ? $country : null, $statuses);
    }

    /** Status of an operation type ("DEPOSIT", "PAYOUT"…), null when unknown. */
    public function status(string $operationType): ?string
    {
        return $this->statuses[strtoupper($operationType)] ?? null;
    }

    public function isOperational(string $operationType): bool
    {
        return $this->status($operationType) === self::OPERATIONAL;
    }
}
