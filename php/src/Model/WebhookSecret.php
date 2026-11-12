<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * Result of a signing secret rotation: the new secret, and until when the
 * previous one is still accepted (24 h).
 */
final class WebhookSecret
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly string $secret,
        public readonly ?\DateTimeImmutable $previousExpiresAt,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Data::string($data, 'webhook_secret'),
            Data::nullableDateTime($data, 'webhook_secret_previous_expires_at'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['secret' => 'whsec_…', 'previousExpiresAt' => $this->previousExpiresAt];
    }
}
