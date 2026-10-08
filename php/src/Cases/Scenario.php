<?php

declare(strict_types=1);

namespace Testor\Cases;

/** One call to test: inputs only, the outcome is observed by the recording pass. */
final readonly class Scenario
{
    /** @param list<Arg> $args */
    public function __construct(
        public string $id,
        public array $method,
        public ?Construction $construction, // null for static methods
        public array $args,
    ) {
    }
}
