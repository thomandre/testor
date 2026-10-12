<?php

declare(strict_types=1);

namespace App\Math;

final class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function divide(int $a, int $b): float
    {
        if ($b === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }

        return $a / $b;
    }

    public function factorial(int $n): int
    {
        if ($n < 0) {
            throw new \DomainException('Factorial of a negative number');
        }

        return $n <= 1 ? 1 : $n * $this->factorial($n - 1);
    }

    /** Steps needed to go from $from down to 0 (never ends when $step <= 0: a bug testor must survive). */
    public function countdown(int $from, int $step): int
    {
        $steps = 0;
        while ($from > 0) {
            $from -= $step;
            $steps++;
        }

        return $steps;
    }

    public static function isEven(int $n): bool
    {
        return $n % 2 === 0;
    }
}
