<?php

declare(strict_types=1);

namespace Testor\Cases;

/** One call to test: inputs only, the outcome is observed by the recording pass. */
final readonly class Scenario
{
    /**
     * @param list<Arg> $args
     * @param list<int> $choice index of the option chosen in each dimension of the method's input space
     * @param list<string> $variations labels of the options that differ from the baseline (for test names)
     */
    public function __construct(
        public string $id,
        public array $method,
        public ?Construction $construction, // null for static methods
        public array $args,
        public array $choice = [],
        public array $variations = [],
    ) {
    }
}
