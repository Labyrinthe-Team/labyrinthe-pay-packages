<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Labyrinthe\LabPay\Client;

/**
 * Facade of the LabPay client. Services are properties of the client: use
 * `LabPay::client()->deposits->create(…)`, or inject Labyrinthe\LabPay\Client.
 *
 * @method static bool isTestMode()
 * @method static string baseUrl()
 * @method static \Labyrinthe\LabPay\Http\ResponseInfo|null lastResponse()
 *
 * @see Client
 */
final class LabPay extends Facade
{
    /** The underlying client, to reach its services ($client->deposits, ->payouts…). */
    public static function client(): Client
    {
        $client = self::getFacadeRoot();
        if (!$client instanceof Client) {
            throw new \LogicException('The LabPay client is not bound in the container.');
        }

        return $client;
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
