<?php

declare(strict_types=1);

namespace App\Orders;

interface PaymentGateway
{
    public function charge(float $amount): bool;

    public function fee(float $amount): float;
}
