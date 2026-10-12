<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * A PHP expression used as an argument. Objects are hoisted into a variable for readability;
 * stubs carry what each configured method returns or throws (method name => PHP expression).
 */
final readonly class Val
{
    /**
     * @param array<string, string> $returns
     * @param array<string, string> $throws
     */
    public function __construct(
        public string $expr,
        public bool $hoist = false,
        public array $returns = [],
        public array $throws = [],
    ) {
    }

    public function configured(string $method, ?string $returns, ?string $throws = null): self
    {
        $r = $this->returns;
        $t = $this->throws;
        unset($r[$method], $t[$method]);
        if ($returns !== null) {
            $r[$method] = $returns;
        }
        if ($throws !== null) {
            $t[$method] = $throws;
        }
        return new self($this->expr, $this->hoist, $r, $t);
    }

    /** @param list<Val> $vals */
    public function in(array $vals): bool
    {
        foreach ($vals as $v) {
            if ($v->expr === $this->expr && $v->hoist === $this->hoist && $v->returns === $this->returns && $v->throws === $this->throws) {
                return true;
            }
        }
        return false;
    }
}
