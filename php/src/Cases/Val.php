<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * A PHP expression used as an argument. Objects are hoisted into a variable for readability;
 * stubs carry the return value configured for each method (method name => PHP expression).
 */
final readonly class Val
{
    /** @param array<string, string> $returns */
    public function __construct(public string $expr, public bool $hoist = false, public array $returns = [])
    {
    }

    public function withReturns(array $returns): self
    {
        return new self($this->expr, $this->hoist, $returns);
    }

    /** @param list<Val> $vals */
    public function in(array $vals): bool
    {
        foreach ($vals as $v) {
            if ($v->expr === $this->expr && $v->hoist === $this->hoist && $v->returns === $this->returns) {
                return true;
            }
        }
        return false;
    }
}
