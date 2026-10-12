<?php

declare(strict_types=1);

namespace App\Orders;

interface Basket
{
    public function itemCount(): int;

    public function reserve(): bool;
}
