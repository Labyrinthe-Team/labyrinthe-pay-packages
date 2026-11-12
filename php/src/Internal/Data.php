<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

use Labyrinthe\LabPay\Exception\UnexpectedValueException;

/**
 * Typed readers over decoded JSON. Lenient on representation (numbers are
 * accepted where a string is expected, numeric strings where an integer is
 * expected), strict on the presence of required fields.
 *
 * @internal
 */
final class Data
{
    private function __construct()
    {
    }

    /**
     * @param array<mixed> $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw self::invalid($key, 'string');
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::string($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw self::invalid($key, 'integer');
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableInt(array $data, string $key): ?int
    {
        if (!isset($data[$key])) {
            return null;
        }

        return self::int($data, $key);
    }

    /**
     * @param array<mixed> $data
     */
    public static function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        return $default;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function array(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if (\is_array($value)) {
            return $value;
        }

        throw self::invalid($key, 'object or array');
    }

    /**
     * Missing or null gives an empty array.
     *
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function optionalArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (\is_array($value)) {
            return $value;
        }

        throw self::invalid($key, 'object or array');
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>|null
     */
    public static function nullableArray(array $data, string $key): ?array
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (\is_array($value)) {
            return $value;
        }

        throw self::invalid($key, 'object or array');
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function stringList(array $data, string $key): array
    {
        $list = [];
        foreach (self::optionalArray($data, $key) as $value) {
            if (\is_string($value)) {
                $list[] = $value;
            }
        }

        return $list;
    }

    /**
     * Object of string (or null) values, e.g. payment metadata.
     *
     * @param array<mixed> $data
     *
     * @return array<string, string|null>|null
     */
    public static function nullableStringMap(array $data, string $key): ?array
    {
        $value = self::nullableArray($data, $key);
        if ($value === null) {
            return null;
        }

        $map = [];
        foreach ($value as $name => $item) {
            $map[(string) $name] = \is_scalar($item) ? (string) $item : null;
        }

        return $map;
    }

    /**
     * @param array<mixed> $data
     */
    public static function nullableDateTime(array $data, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableString($data, $key);
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw self::invalid($key, 'ISO 8601 date');
        }
    }

    /**
     * The `data` member of a response body.
     *
     * @param array<mixed> $body
     *
     * @return array<mixed>
     */
    public static function data(array $body): array
    {
        return self::array($body, 'data');
    }

    private static function invalid(string $key, string $expected): UnexpectedValueException
    {
        return new UnexpectedValueException(\sprintf('Unexpected API payload: "%s" should be a %s.', $key, $expected));
    }
}
