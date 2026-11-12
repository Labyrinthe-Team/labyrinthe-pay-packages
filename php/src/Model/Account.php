<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Enum\Mode;
use Labyrinthe\LabPay\Internal\Data;

/**
 * The merchant account (`GET /V2/account`).
 */
final class Account
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        /** Mode of the key used. */
        public readonly Mode $mode,
        /** Merchant account name. */
        public readonly ?string $merchant,
        /** True once the token is enabled for production; otherwise live collections have a daily limit. */
        public readonly bool $liveEnabled,
        public readonly bool $kycVerified,
        /** Deposit fee rate, between 0 and 1 (e.g. "0.0350" = 3.5 %). */
        public readonly ?string $depositRate,
        /** Payout fee rate, between 0 and 1. */
        public readonly ?string $payoutRate,
        /** Test key of the account (test_…). */
        public readonly ?string $testKey,
        /** Notification signing secret (whsec_…). Live token only: null with the test key. */
        public readonly ?string $webhookSecret,
        /** During a rotation: expiry of the previous secret. */
        public readonly ?\DateTimeImmutable $webhookSecretPreviousExpiresAt,
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $fees = Data::optionalArray($data, 'fees');

        return new self(
            mode: Mode::fromApi(Data::nullableString($data, 'mode')),
            merchant: Data::nullableString($data, 'merchant'),
            liveEnabled: Data::bool($data, 'live_enabled'),
            kycVerified: Data::bool($data, 'kyc_verified'),
            depositRate: Data::nullableString($fees, 'deposit_rate'),
            payoutRate: Data::nullableString($fees, 'payout_rate'),
            testKey: Data::nullableString($data, 'test_key'),
            webhookSecret: Data::nullableString($data, 'webhook_secret'),
            webhookSecretPreviousExpiresAt: Data::nullableDateTime($data, 'webhook_secret_previous_expires_at'),
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'mode' => $this->mode,
            'merchant' => $this->merchant,
            'liveEnabled' => $this->liveEnabled,
            'kycVerified' => $this->kycVerified,
            'depositRate' => $this->depositRate,
            'payoutRate' => $this->payoutRate,
            'testKey' => $this->testKey === null ? null : 'test_…',
            'webhookSecret' => $this->webhookSecret === null ? null : 'whsec_…',
        ];
    }
}
