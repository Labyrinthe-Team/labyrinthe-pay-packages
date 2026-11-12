<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Exception;

/**
 * The API answered with an error (`{ "message", "code", "errors"? }`).
 *
 * Subclasses exist for the codes an integration usually handles; any other
 * code (including codes added to the API later) is thrown as a plain
 * ApiException: always switch on getErrorCode(), never on the message
 * (written in French, for humans, and subject to change).
 */
class ApiException extends \RuntimeException implements LabPayException
{
    /**
     * @param array<string, list<string>> $errors  per-field messages (validation errors)
     * @param array<string, string>       $headers response headers (lower-case names)
     */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $statusCode,
        private readonly array $errors = [],
        private readonly array $headers = [],
    ) {
        parent::__construct($message, $statusCode);
    }

    /** Stable machine-readable code, e.g. "INSUFFICIENT_BALANCE" ("HTTP_ERROR" when the API gave none). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status code of the response. */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Per-field messages of a validation error (`errors`), e.g. ["amount" => ["…"]].
     *
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** A response header (case-insensitive name). */
    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Mode of the key that made the request (X-Labyrinthe-Mode: "live" or "test"), when the API sent it. */
    public function getMode(): ?string
    {
        return $this->getHeader('x-labyrinthe-mode');
    }

    /** Seconds from the Retry-After header (delta-seconds), null when the API does not say. */
    public function getRetryAfter(): ?int
    {
        $header = $this->getHeader('retry-after');
        if ($header !== null && preg_match('/^\d+$/', trim($header)) === 1) {
            return (int) trim($header);
        }

        return null;
    }
}
