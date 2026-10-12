<?php

declare(strict_types=1);

namespace App\Orders;

final class CheckoutService
{
    public function __construct(private readonly Basket $basket)
    {
    }

    public function shippingLabel(): string
    {
        return ShippingPolicy::isFree($this->basket->itemCount()) ? 'Free shipping' : 'Standard shipping';
    }

    public function reserve(): string
    {
        if ($this->basket->itemCount() < 5) {
            throw new \LogicException('Bulk orders only');
        }
        if (!$this->basket->reserve()) {
            return 'Out of stock';
        }

        return 'Reserved';
    }

    public function itemsOrZero(): int
    {
        try {
            return $this->basket->itemCount();
        } catch (\RuntimeException) {
            return 0;
        }
    }
}
