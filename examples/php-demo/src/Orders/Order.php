<?php

declare(strict_types=1);

namespace App\Orders;

final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly float $amount,
        public OrderStatus $status = OrderStatus::Pending,
    ) {
    }
}
