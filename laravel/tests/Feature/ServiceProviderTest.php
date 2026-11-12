<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Laravel\Facades\LabPay;
use Labyrinthe\LabPay\Laravel\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function testTheClientIsASingletonBuiltFromTheConfig(): void
    {
        $client = $this->app?->make(Client::class);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame($client, $this->app?->make('labpay'));
        self::assertTrue($client->isTestMode());
        self::assertSame('https://api.labyrinthe-rdc.com/api/V2', $client->baseUrl());
    }

    public function testTheFacade(): void
    {
        self::assertTrue(LabPay::isTestMode());
        self::assertSame(LabPay::client(), $this->app?->make(Client::class));
    }

    public function testTheBaseUrlCanBeChanged(): void
    {
        $this->app?->forgetInstance(Client::class);
        config()->set('labpay.base_url', 'http://localhost:8000');

        self::assertSame('http://localhost:8000/api/V2', $this->app?->make(Client::class)->baseUrl());
    }

    public function testTheWebhookRouteIsRegistered(): void
    {
        $route = Route::getRoutes()->getByName('labpay.webhook');

        self::assertNotNull($route);
        self::assertSame('labpay/webhook', $route->uri());
        self::assertSame(['POST'], $route->methods());
    }
}
