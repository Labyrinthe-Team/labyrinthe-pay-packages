<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Internal;

/**
 * @internal
 */
final class Response
{
    /**
     * @param array<mixed>          $body
     * @param array<string, string> $headers lower-case names
     */
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers,
    ) {
    }

    /**
     * @return array<mixed>
     */
    public function data(): array
    {
        return Data::data($this->body);
    }
}
