<?php

declare(strict_types=1);

namespace Testor\PhpUnit;

final readonly class RunResult
{
    /**
     * @param list<string> $failed test methods that failed or errored
     * @param list<string> $passed
     * @param list<string> $noisy  test methods that triggered PHP warnings/notices/deprecations
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public array $failed,
        public array $passed,
        public array $noisy,
        public bool $killed = false,
    ) {
    }
}
