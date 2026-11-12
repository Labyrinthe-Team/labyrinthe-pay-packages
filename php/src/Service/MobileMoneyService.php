<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\Reference;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Shared logic of deposits and payouts (same fields).
 *
 * @internal
 */
abstract class MobileMoneyService
{
    /**
     * @internal use $client->deposits / $client->payouts
     */
    public function __construct(
        protected readonly Transport $transport,
        protected readonly bool $autoReference,
    ) {
    }

    /**
     * @param array<array-key, mixed>|null $metadata
     */
    protected function initiate(
        string $path,
        string|int|float $amount,
        string $currency,
        string $phone,
        ?string $country,
        ?string $provider,
        ?string $reference,
        ?string $callbackUrl,
        ?string $customerMessage,
        ?array $metadata,
    ): Payment {
        $reference = Input::reference($reference) ?? ($this->autoReference ? Reference::generate() : null);

        $body = array_filter([
            'amount' => Input::amount($amount),
            'currency' => Input::currency($currency),
            'phone' => Input::phone($phone),
            'country' => Input::country($country),
            'provider' => Input::provider($provider),
            'reference' => $reference,
            'callback_url' => Input::callbackUrl($callbackUrl),
            'customer_message' => Input::customerMessage($customerMessage),
            'metadata' => Input::metadata($metadata),
        ], static fn (mixed $v): bool => $v !== null);

        // With a reference, a replay returns the existing payment: retrying is safe.
        $mode = $reference !== null ? RetryMode::Safe : RetryMode::OnlyWhenThrottled;
        $response = $this->transport->request('POST', $path, $mode, body: $body, paymentOnRefusal: true);

        return Payment::fromArray($response->data());
    }
}
