<?php

declare(strict_types=1);

namespace App\Orders;

final class ShippingPolicy
{
    public static function isFree(int $items): bool
    {
        return $items >= 3;
    }
}
