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
