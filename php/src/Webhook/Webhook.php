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

        $valid = false;
        foreach ($secrets as $candidate) {
            $expected = self::computeSignature($timestamp, $rawBody, $candidate);
            foreach ($signatures as $signature) {
                // No early exit: every comparison runs in constant time.
                $valid = hash_equals($expected, $signature) || $valid;
            }
        }
        if (!$valid) {
            throw new SignatureVerificationException('Invalid notification signature: the body was not signed with this secret.');
        }

        if ($toleranceSeconds > 0 && abs(($now ?? time()) - $timestamp) > $toleranceSeconds) {
            throw new SignatureVerificationException(\sprintf('Notification timestamp is outside the %d s tolerance (possible replay).', $toleranceSeconds));
        }

        return $timestamp;
    }

    /**
     * True when the signature and timestamp are valid.
     *
     * @param string|list<string> $secret
     */
    public static function isValid(
        string $rawBody,
        string $signatureHeader,
        #[\SensitiveParameter]
        string|array $secret,
        int $toleranceSeconds = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): bool {
        try {
            self::verify($rawBody, $signatureHeader, $secret, $toleranceSeconds, $now);

            return true;
        } catch (SignatureVerificationException) {
            return false;
        }
    }

    /**
     * The X-Labyrinthe-Signature value LabPay would send for this body:
     * useful to test your notification endpoint.
     *
     * @param string|list<string> $secret one v1 per secret
     */
    public static function sign(string $rawBody, #[\SensitiveParameter] string|array $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $parts = ['t='.$timestamp];
        foreach (self::secrets($secret) as $candidate) {
            $parts[] = 'v1='.self::computeSignature($timestamp, $rawBody, $candidate);
        }

        return implode(',', $parts);
    }

    /**
     * @return array{0: int|null, 1: list<string>}
     */
    private static function parseHeader(string $header): array
    {
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (\count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && preg_match('/^\d{1,12}$/', $value) === 1) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && preg_match('/^[0-9a-f]{64}$/', strtolower($value)) === 1) {
                $signatures[] = strtolower($value);
            }
        }

        return [$timestamp, $signatures];
    }

    /**
     * @param string|array<mixed> $secret
     *
     * @return list<string>
     */
    private static function secrets(#[\SensitiveParameter] string|array $secret): array
    {
        $secrets = [];
        foreach (\is_array($secret) ? $secret : [$secret] as $candidate) {
            if (\is_string($candidate) && $candidate !== '') {
                $secrets[] = $candidate;
            }
        }
        if ($secrets === []) {
            throw new InvalidArgumentException('The webhook signing secret cannot be empty (whsec_…, GET /V2/account with the live token).');
        }

        return $secrets;
    }

    private static function computeSignature(int $timestamp, string $rawBody, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
