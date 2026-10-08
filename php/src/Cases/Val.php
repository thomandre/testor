<?php

declare(strict_types=1);

namespace Testor\Cases;

/** A PHP expression used as an argument. Objects are hoisted into a variable for readability. */
final readonly class Val
{
    public function __construct(public string $expr, public bool $hoist = false)
    {
    }

    /** @param list<Val> $vals */
    public function in(array $vals): bool
    {
        foreach ($vals as $v) {
            if ($v->expr === $this->expr && $v->hoist === $this->hoist) {
                return true;
            }
        }
        return false;
    }
}
