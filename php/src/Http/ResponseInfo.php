<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Http;

/**
 * Status and headers of an HTTP response received from the API.
 */
final class ResponseInfo
{
    /**
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly array $headers,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** "live" or "test" (X-Labyrinthe-Mode), null when the API did not send it. */
    public function mode(): ?string
    {
        return $this->header('x-labyrinthe-mode');
    }
}
