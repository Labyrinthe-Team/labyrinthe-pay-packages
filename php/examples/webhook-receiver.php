<?php

declare(strict_types=1);

/*
 * Notification endpoint (callback_url), plain PHP.
 *
 *     LABPAY_WEBHOOK_SECRET=whsec_… php -S 0.0.0.0:8080 examples/webhook-receiver.php
 */

require __DIR__.'/../vendor/autoload.php';

use Labyrinthe\LabPay\Exception\InvalidWebhookPayloadException;
use Labyrinthe\LabPay\Exception\SignatureVerificationException;
use Labyrinthe\LabPay\Webhook\Webhook;

$secret = getenv('LABPAY_WEBHOOK_SECRET');
if (!\is_string($secret) || $secret === '') {
    http_response_code(500);
    exit;
}

$rawBody = file_get_contents('php://input');   // RAW body, before any json_decode
$signature = $_SERVER['HTTP_X_LABYRINTHE_SIGNATURE'] ?? '';

try {
    $event = Webhook::constructEvent(\is_string($rawBody) ? $rawBody : '', \is_string($signature) ? $signature : '', $secret);
} catch (SignatureVerificationException|InvalidWebhookPayloadException) {
    http_response_code(400);
    exit;
}

// Deduplicate: the same notification can arrive more than once.
$deliveryId = $_SERVER['HTTP_X_LABYRINTHE_DELIVERY'] ?? $event->payment->id;

if ($event->isPaymentCompleted()) {
    // Deliver the order $event->payment->reference (once per $deliveryId / payment id).
} elseif ($event->isPaymentFailed()) {
    // Mark it as failed: $event->payment->failure?->code
}

// Answer 2xx within 10 seconds; do long work afterwards.
http_response_code(200);
