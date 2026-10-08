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
}
