<?php

declare(strict_types=1);

namespace Testor;

final class ClassReport
{
    /** @param list<array{string, string}> $skipped [method, reason] */
    public function __construct(
        public readonly string $name,
        public ?string $output = null,
        public int $explored = 0,
        public int $kept = 0,
        public int $unstable = 0,
        public array $skipped = [],
        public ?string $error = null,
        public ?int $executableLines = null, // null when no coverage driver is available
        public ?int $coveredLines = null,
    ) {
    }

    public function coverage(): ?float
    {
        if ($this->executableLines === null) {
            return null;
        }
        return $this->executableLines === 0 ? 100.0 : 100 * $this->coveredLines / $this->executableLines;
    }
}
