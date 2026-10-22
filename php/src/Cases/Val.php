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
     * @param list<string> $spied void methods whose calls are recorded and asserted (configured ones are too)
     */
    public function __construct(
        public string $expr,
        public bool $hoist = false,
        public array $returns = [],
        public array $throws = [],
        public array $spied = [],
    ) {
    }

    /** A PHPUnit stub, whose methods can be configured (not a real object). */
    public function isStub(): bool
    {
        return str_starts_with($this->expr, '$this->createStub(');
    }

    /** @return list<string> methods whose calls are recorded and asserted */
    public function spiedMethods(): array
    {
        return array_values(array_unique([...array_keys($this->returns), ...array_keys($this->throws), ...$this->spied]));
    }

    public function withSpied(array $methods): self
    {
        return new self($this->expr, $this->hoist, $this->returns, $this->throws, $methods);
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
        return new self($this->expr, $this->hoist, $r, $t, $this->spied);
    }

    /** @param list<Val> $vals */
    public function in(array $vals): bool
    {
        foreach ($vals as $v) {
            if ($v->expr === $this->expr && $v->hoist === $this->hoist && $v->returns === $this->returns && $v->throws === $this->throws
                && $v->spied === $this->spied) {
                return true;
            }
        }
        return false;
    }
}
