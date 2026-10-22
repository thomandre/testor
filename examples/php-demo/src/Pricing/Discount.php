<?php

declare(strict_types=1);

namespace App\Pricing;

/** Base of the discount rules: each rule gives its rate, the discount is capped. */
abstract class Discount
{
    public function __construct(private readonly float $cap)
    {
    }

    abstract protected function rate(): float;

    public function apply(float $amount): float
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        return round($amount - $this->capped($amount * $this->rate()), 2);
    }

    protected function capped(float $discount): float
    {
        return $discount > $this->cap ? $this->cap : $discount;
    }
}
