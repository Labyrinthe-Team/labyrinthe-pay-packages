<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Labyrinthe\LabPay\Client;
use Labyrinthe\LabPay\Laravel\Http\Controllers\WebhookController;
use Labyrinthe\LabPay\Laravel\Http\Middleware\VerifyLabPaySignature;
use Psr\Log\LoggerInterface;

/**
 * Registers the LabPay client (singleton, built on first use from
 * config/labpay.php), the signature middleware and the notification route.
 */
final class LabPayServiceProvider extends ServiceProvider
{
    public const VERSION = '0.1.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/labpay.php', 'labpay');

        $this->app->singleton(Client::class, static function (Application $app): Client {
            $config = $app->make(Repository::class);

            $apiKey = $config->get('labpay.api_key');
            $baseUrl = $config->get('labpay.base_url');
            $timeout = $config->get('labpay.timeout', 30);
            $retries = $config->get('labpay.max_retries', 2);

            return new Client(
                apiKey: \is_string($apiKey) ? $apiKey : '',
                baseUrl: \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
                timeout: is_numeric($timeout) ? (float) $timeout : 30.0,
                maxRetries: is_numeric($retries) ? (int) $retries : 2,
                autoReference: (bool) $config->get('labpay.auto_reference', true),
                logger: $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
                userAgentSuffix: 'labpay-laravel/'.self::VERSION,
            );
        });
        $this->app->alias(Client::class, 'labpay');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/labpay.php' => $this->app->configPath('labpay.php')], 'labpay-config');
        }

        $this->app->make('router')->aliasMiddleware('labpay.signature', VerifyLabPaySignature::class);

        $config = $this->app->make(Repository::class);
        $path = $config->get('labpay.webhook.path');
        if (\is_string($path) && trim($path, '/') !== '') {
            $middleware = $config->get('labpay.webhook.middleware', []);
            Route::post(trim($path, '/'), WebhookController::class)
                ->middleware(\is_array($middleware) ? array_values(array_filter($middleware, 'is_string')) : [])
                ->name('labpay.webhook');
        }
    }
}
