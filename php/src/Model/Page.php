<?php

declare(strict_types=1);

namespace Labyrinthe\LabPay\Model;

use Labyrinthe\LabPay\Internal\Data;

/**
 * One page of a paginated list (`data` + `meta`).
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $currentPage,
        public readonly int $lastPage,
        public readonly int $perPage,
        public readonly int $total,
    ) {
    }

    /**
     * @template U
     *
     * @param array<mixed>               $body
     * @param callable(array<mixed>): U $map
     *
     * @return self<U>
     */
    public static function fromResponse(array $body, callable $map): self
    {
        $items = [];
        foreach (Data::array($body, 'data') as $item) {
            if (\is_array($item)) {
                $items[] = $map($item);
            }
        }
        $meta = Data::optionalArray($body, 'meta');

        return new self(
            items: $items,
            currentPage: Data::nullableInt($meta, 'current_page') ?? 1,
            lastPage: Data::nullableInt($meta, 'last_page') ?? 1,
            perPage: Data::nullableInt($meta, 'per_page') ?? \count($items),
            total: Data::nullableInt($meta, 'total') ?? \count($items),
        );
    }

    /** True when a next page exists. */
    public function hasMore(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function nextPage(): ?int
    {
        return $this->hasMore() ? $this->currentPage + 1 : null;
    }

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
