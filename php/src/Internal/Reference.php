<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

/**
 * Generates the `reference` of a payment sent without one, so that the SDK
 * can safely retry it (the API returns the existing payment for a known
 * reference instead of creating a second one).
 *
 * @internal
 */
final class Reference
{
    public const PREFIX = 'sdk_';

    private function __construct()
    {
    }

    /** "sdk_" followed by a random UUID v4: 40 characters among A-Z a-z 0-9 . _ : - */
    public static function generate(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return self::PREFIX.\sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
