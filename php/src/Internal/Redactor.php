<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

/**
 * Keeps secrets and phone numbers out of logs and dumps.
 *
 * @internal
 */
final class Redactor
{
    private function __construct()
    {
    }

    /** "test_abcd…wxyz" → "test_…wxyz". */
    public static function apiKey(string $key): string
    {
        $prefix = str_starts_with($key, 'test_') ? 'test_' : '';

        return $prefix.'…'.(\strlen($key) > 12 ? substr($key, -4) : '');
    }

    /** Path for logs: query values are dropped (references, phone numbers). */
    public static function path(string $path, string $query): string
    {
        if ($query === '') {
            return $path;
        }
        $names = [];
        foreach (explode('&', $query) as $pair) {
            $names[] = explode('=', $pair, 2)[0].'=…';
        }

        return $path.'?'.implode('&', $names);
    }
}
