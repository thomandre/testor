<?php

declare(strict_types=1);

namespace App\Cart;

final class Cart
{
    /** @var array<string, int> */
    private array $items = [];

    public function add(string $sku, int $quantity = 1): void
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException("Invalid quantity for $sku");
        }
        $this->items[$sku] = ($this->items[$sku] ?? 0) + $quantity;
    }

    public function count(): int
    {
        return array_sum($this->items);
    }

    /** @return array<string, int> */
    public function items(): array
    {
        return $this->items;
    }
}
