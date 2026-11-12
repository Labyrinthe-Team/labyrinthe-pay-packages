<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Labyrinthe\LabPay\Laravel\Events\PaymentCompleted;
use Labyrinthe\LabPay\Laravel\Events\PaymentFailed;
use Labyrinthe\LabPay\Laravel\Events\WebhookReceived;
use Labyrinthe\LabPay\Laravel\Http\Middleware\VerifyLabPaySignature;
use Labyrinthe\LabPay\Webhook\Event;
use Labyrinthe\LabPay\Webhook\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives LabPay notifications (your callback_url): verifies the signature
 * (VerifyLabPaySignature), dispatches WebhookReceived then PaymentCompleted or
 * PaymentFailed, and answers 200.
 *
 * Listeners run before the answer: keep them short (LabPay waits 10 s) and
 * queue long work (ShouldQueue listeners). The same notification can arrive
 * more than once: deduplicate on $event->deliveryId or $event->payment()->id.
 */
final class WebhookController
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly Repository $config,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        return (new VerifyLabPaySignature($this->config))->handle($request, function (Request $request): Response {
            $event = $request->attributes->get(VerifyLabPaySignature::EVENT);
            if (!$event instanceof Event) {
                return new Response('', 400);
            }

            $deliveryId = $request->headers->get(Webhook::DELIVERY_HEADER);
            $mode = $request->headers->get(Webhook::MODE_HEADER);

            $this->events->dispatch(new WebhookReceived($event, $deliveryId, $mode));
            if ($event->isPaymentCompleted()) {
                $this->events->dispatch(new PaymentCompleted($event, $deliveryId, $mode));
            } elseif ($event->isPaymentFailed()) {
                $this->events->dispatch(new PaymentFailed($event, $deliveryId, $mode));
            }

            return new Response('', 200);
        });
    }
}
