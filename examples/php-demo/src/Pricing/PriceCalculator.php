<?php

declare(strict_types=1);

namespace App\Pricing;

final class PriceCalculator
{
    public function __construct(private readonly TaxRateProvider $taxRates)
    {
    }

    public function discountFor(CustomerTier $tier): float
    {
        return match ($tier) {
            CustomerTier::Standard => 0.0,
            CustomerTier::Premium => 0.1,
            CustomerTier::Vip => 0.25,
        };
    }

    public function finalPrice(float $net, string $country, CustomerTier $tier): float
    {
        if ($net < 0) {
            throw new \InvalidArgumentException('Net price cannot be negative');
        }
        $discounted = $net * (1 - $this->discountFor($tier));

        return round($discounted * (1 + $this->taxRates->rateFor($country)), 2);
    }
}
