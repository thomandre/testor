<?php

declare(strict_types=1);

namespace App\Orders;

interface OrderRepository
{
    public function find(int $id): ?Order;

    public function statusOf(int $id): OrderStatus;
}
