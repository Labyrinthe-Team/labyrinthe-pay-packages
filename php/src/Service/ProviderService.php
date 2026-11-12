<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Service;

use Labyrinthe\LabPay\Enum\Operation;
use Labyrinthe\LabPay\Exception\ApiException;
use Labyrinthe\LabPay\Exception\ConnectionException;
use Labyrinthe\LabPay\Internal\Data;
use Labyrinthe\LabPay\Internal\Input;
use Labyrinthe\LabPay\Internal\RetryMode;
use Labyrinthe\LabPay\Internal\Transport;
use Labyrinthe\LabPay\Model\Country;
use Labyrinthe\LabPay\Model\Provider;
use Labyrinthe\LabPay\Model\ProviderAvailability;
use Labyrinthe\LabPay\Model\ProviderPrediction;

/**
 * Countries, providers, availability and prediction (`$client->providers`).
 */
final class ProviderService
{
    /**
     * @internal use $client->providers
     */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Countries open to payments: dial code, currencies, limits per currency.
     *
     * @return list<Country>
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function countries(): array
    {
        $response = $this->transport->request('GET', '/countries', RetryMode::Safe);

        return self::map($response->body, Country::fromArray(...));
    }

    /**
     * Providers of the open countries and their rules for an operation
     * (limits, decimals, PIN prompt).
     *
     * @param Operation|string|null $operation deposit (API default), payout or refund
     * @param string|null           $country   ISO alpha-3 code; every open country when null
     *
     * @return list<Provider>
     *
     * @throws \Labyrinthe\LabPay\Exception\ServiceUnavailableException GATEWAY_UNAVAILABLE (retried automatically)
     * @throws ApiException
     * @throws ConnectionException
     */
    public function list(Operation|string|null $operation = null, ?string $country = null): array
    {
        $response = $this->transport->request('GET', '/providers', RetryMode::Safe, query: [
            'operation' => Input::operation($operation),
            'country' => Input::country($country),
        ]);

        return self::map($response->body, Provider::fromArray(...));
    }

    /**
     * Real-time availability per provider code: OPERATIONAL, DELAYED (payouts queued) or CLOSED.
     *
     * @return array<string, ProviderAvailability> keyed by provider code
     *
     * @throws ApiException
     * @throws ConnectionException
     */
    public function availability(?string $country = null): array
    {
        $response = $this->transport->request('GET', '/availability', RetryMode::Safe, query: ['country' => Input::country($country)]);

        $result = [];
        foreach (Data::optionalArray($response->body, 'data') as $provider => $statuses) {
            $result[(string) $provider] = ProviderAvailability::fromArray((string) $provider, \is_array($statuses) ? $statuses : []);
        }

        return $result;
    }

    /**
     * Validates a number and predicts its provider, without creating a payment.
     *
     * @throws \Labyrinthe\LabPay\Exception\InvalidPhoneNumberException INVALID_PHONE_NUMBER
     * @throws ApiException                                             UNSUPPORTED_COUNTRY…
     * @throws ConnectionException
     */
    public function predict(string $phone, ?string $country = null): ProviderPrediction
    {
        $response = $this->transport->request('POST', '/predict-provider', RetryMode::Safe, body: array_filter([
            'phone' => Input::phone($phone),
            'country' => Input::country($country),
        ], static fn (mixed $v): bool => $v !== null));

        return ProviderPrediction::fromArray($response->data());
    }

    /**
     * @template T
     *
     * @param array<mixed>               $body
     * @param callable(array<mixed>): T $map
     *
     * @return list<T>
     */
    private static function map(array $body, callable $map): array
    {
        $items = [];
        foreach (Data::array($body, 'data') as $item) {
            if (\is_array($item)) {
                $items[] = $map($item);
            }
        }

        return $items;
    }
}
