<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Labyrinthe\LabPay\Exception\InvalidWebhookPayloadException;
use Labyrinthe\LabPay\Exception\SignatureVerificationException;
use Labyrinthe\LabPay\Webhook\Webhook;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the X-Labyrinthe-Signature of a LabPay notification on the RAW
 * body, then stores the typed event in the request attributes
 * (`$request->attributes->get(VerifyLabPaySignature::EVENT)`).
 *
 * Answers 400 when the signature, the timestamp or the payload is invalid,
 * 500 when no signing secret is configured.
 *
 *     Route::post('payments/labpay', MyController::class)->middleware('labpay.signature');
 */
final class VerifyLabPaySignature
{
    public const EVENT = 'labpay.event';

    public function __construct(private readonly Repository $config)
    {
    }

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $secrets = $this->secrets();
        if ($secrets === []) {
            report(new \RuntimeException('LabPay notification received but LABPAY_WEBHOOK_SECRET is not configured.'));

            return new Response('', 500);
        }

        $tolerance = $this->config->get('labpay.webhook.tolerance', Webhook::DEFAULT_TOLERANCE);

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->headers->get(Webhook::SIGNATURE_HEADER, ''),
                $secrets,
                is_numeric($tolerance) ? (int) $tolerance : Webhook::DEFAULT_TOLERANCE,
            );
        } catch (SignatureVerificationException|InvalidWebhookPayloadException) {
            return new Response('', 400);
        }

        $request->attributes->set(self::EVENT, $event);

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function secrets(): array
    {
        $secret = $this->config->get('labpay.webhook.secret');
        $values = \is_array($secret) ? $secret : explode(',', \is_string($secret) ? $secret : '');

        $secrets = [];
        foreach ($values as $value) {
            if (\is_string($value) && trim($value) !== '') {
                $secrets[] = trim($value);
            }
        }

        return $secrets;
    }
}
