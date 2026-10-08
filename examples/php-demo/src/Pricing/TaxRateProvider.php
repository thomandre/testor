<?php

declare(strict_types=1);

namespace App\Pricing;

interface TaxRateProvider
{
    public function rateFor(string $country): float;
}
