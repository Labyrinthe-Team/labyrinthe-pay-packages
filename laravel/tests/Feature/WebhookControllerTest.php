<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Labyrinthe\LabPay\Laravel\Events\PaymentCompleted;
use Labyrinthe\LabPay\Laravel\Events\PaymentFailed;
use Labyrinthe\LabPay\Laravel\Events\WebhookReceived;
use Labyrinthe\LabPay\Laravel\Http\Middleware\VerifyLabPaySignature;
use Labyrinthe\LabPay\Laravel\Tests\TestCase;
use Labyrinthe\LabPay\Webhook\Event as LabPayEvent;
use Labyrinthe\LabPay\Webhook\Webhook;

final class WebhookControllerTest extends TestCase
{
    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function deliver(string $body, ?string $signature, string $uri = '/labpay/webhook', array $headers = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_LABYRINTHE_DELIVERY' => '981', 'HTTP_X_LABYRINTHE_MODE' => 'test'];
        if ($signature !== null) {
            $server['HTTP_X_LABYRINTHE_SIGNATURE'] = $signature;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', $uri, [], [], [], $server, $body);
    }

    public function testACompletedPaymentDispatchesTheEvents(): void
    {
        Event::fake();
        $body = self::notification();

        $this->deliver($body, Webhook::sign($body, self::SECRET))->assertOk();

        Event::assertDispatched(WebhookReceived::class, static fn (WebhookReceived $e): bool => $e->deliveryId === '981' && $e->mode === 'test');
        Event::assertDispatched(PaymentCompleted::class, static fn (PaymentCompleted $e): bool => $e->payment()->reference === 'ORDER-42' && $e->isTest());
        Event::assertNotDispatched(PaymentFailed::class);
    }

    public function testAFailedPayment(): void
    {
        Event::fake();
        $body = self::notification('payment.failed', 'failed');

        $this->deliver($body, Webhook::sign($body, self::SECRET))->assertOk();
