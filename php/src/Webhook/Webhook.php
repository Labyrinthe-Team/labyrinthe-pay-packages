<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Webhook;

use Labyrinthe\LabPay\Enum\WebhookEventType;
use Labyrinthe\LabPay\Exception\InvalidArgumentException;
use Labyrinthe\LabPay\Exception\InvalidWebhookPayloadException;
use Labyrinthe\LabPay\Exception\SignatureVerificationException;
use Labyrinthe\LabPay\Exception\UnexpectedValueException;
use Labyrinthe\LabPay\Internal\Data;
use Labyrinthe\LabPay\Model\Payment;

/**
 * Verification of the notifications sent to your `callback_url`.
 *
 *     $event = Webhook::constructEvent(
 *         file_get_contents('php://input'),
 *         $_SERVER['HTTP_X_LABYRINTHE_SIGNATURE'] ?? '',
 *         getenv('LABPAY_WEBHOOK_SECRET'),
 *     );
 *
 * Header: `X-Labyrinthe-Signature: t=<timestamp>,v1=<hex>[,v1=<hex>]`, where
 * each v1 is the HMAC-SHA256 of "<timestamp>.<raw body>" keyed with your
 * signing secret (whsec_…, `GET /V2/account` with the live token). During the
 * 24 h after a rotation the header carries two v1 (new and old secret): the
 * notification is accepted when either matches.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'X-Labyrinthe-Signature';
    public const EVENT_HEADER = 'X-Labyrinthe-Event';
    public const DELIVERY_HEADER = 'X-Labyrinthe-Delivery';
    public const MODE_HEADER = 'X-Labyrinthe-Mode';
    public const DEFAULT_TOLERANCE = 300;

    private function __construct()
    {
    }

    /**
     * Verifies a notification and returns the typed event.
     *
     * @param string               $rawBody          the request body, byte for byte (never a re-encoded array)
     * @param string               $signatureHeader  value of the X-Labyrinthe-Signature header
     * @param string|list<string>  $secret           your signing secret (or several, e.g. during your own rotation)
     * @param int                  $toleranceSeconds max age of the signed timestamp; 0 disables the check (not recommended)
     * @param int|null             $now              current Unix time (tests)
     *
     * @throws SignatureVerificationException wrong/missing signature or timestamp out of tolerance: answer 400
     * @throws InvalidWebhookPayloadException signed body that is not a valid notification: answer 400
     */
    public static function constructEvent(
        string $rawBody,
        string $signatureHeader,
        #[\SensitiveParameter]
        string|array $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        $timestamp = self::verify($rawBody, $signatureHeader, $secret, $toleranceSeconds, $now);

        try {
            $body = json_decode($rawBody, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidWebhookPayloadException('The notification body is not valid JSON.');
        }
        if (!\is_array($body)) {
            throw new InvalidWebhookPayloadException('The notification body is not a JSON object.');
        }

        try {
            $name = Data::string($body, 'event');

            return new Event(
                type: WebhookEventType::fromApi($name),
                name: $name,
                payment: Payment::fromArray(Data::array($body, 'payment')),
                sentAt: Data::nullableDateTime($body, 'sent_at'),
                timestamp: $timestamp,
                raw: $body,
            );
        } catch (UnexpectedValueException $e) {
            throw new InvalidWebhookPayloadException(str_replace('Unexpected API payload', 'Invalid notification payload', $e->getMessage()));
        }
    }

    /**
     * Checks the signature (constant time) and the timestamp; returns the signed timestamp.
     *
     * @param string|list<string> $secret
     *
     * @throws SignatureVerificationException
     */
    public static function verify(
        string $rawBody,
        string $signatureHeader,
        #[\SensitiveParameter]
        string|array $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): int {
        $secrets = self::secrets($secret);
        [$timestamp, $signatures] = self::parseHeader($signatureHeader);
        if ($timestamp === null || $signatures === []) {
            throw new SignatureVerificationException('Missing or malformed X-Labyrinthe-Signature header (expected "t=<timestamp>,v1=<signature>").');
        }
