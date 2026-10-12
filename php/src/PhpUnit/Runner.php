<?php

declare(strict_types=1);

namespace Testor\PhpUnit;

use Testor\Process;

/** Runs PHPUnit in the target project and reports, per test method, failures and warnings. */
final class Runner
{
    // e.g. "Test Triggered PHP Warning (Tests\FooTest::testBar) in /src/Foo.php:12"
    // and "Test Considered Risky (Tests\FooTest::testBar)" (fails projects configured with failOnRisky).
    private const EVENT_NOTICE = '/^Test (?:Triggered (?:PHP )?(?:Warning|Notice|Deprecation)|Considered Risky) \((?<test>[^,)]+)(?<extra>[^)]*)\)/m';
    private const CONFIG_FILES = ['phpunit.xml', 'phpunit.xml.dist', 'phpunit.dist.xml'];

    public function __construct(private readonly string $project)
    {
    }

    public function check(): ?string
    {
        if (!is_file("$this->project/vendor/autoload.php")) {
            return "$this->project/vendor/autoload.php not found: run `composer install` in the project";
        }
        if (!is_file("$this->project/vendor/bin/phpunit")) {
            return 'vendor/bin/phpunit not found: add phpunit/phpunit to require-dev';
        }
        return null;
    }

    /**
     * @param array<string, string> $env
     * @param list<string> $options extra PHPUnit options
     * @param (callable(): bool)|null $watch kills the run when it returns true
     */
    public function run(string $testFile, array $env = [], array $options = [], ?callable $watch = null,
                        float $timeout = 600): RunResult
    {
        $tmp = Process::tempDir();
        try {
            $junit = "$tmp/junit.xml";
            $events = "$tmp/events.txt";
            $cmd = [
                PHP_BINARY,
                // PCOV only instruments src/ (or lib/, app/) by default: measure the whole project but vendor/.
                '-d', "pcov.directory=$this->project", '-d', 'pcov.exclude=~/vendor/~',
                "$this->project/vendor/bin/phpunit", '--do-not-cache-result',
                // Never run the project's own coverage reports or logs: slow, and they write into the project.
                '--no-coverage', '--no-logging',
                '--log-junit', $junit, '--log-events-text', $events];
            $hasConfig = array_filter(self::CONFIG_FILES, fn (string $f) => is_file("$this->project/$f"));
            if (!$hasConfig) {
                $cmd = [...$cmd, '--bootstrap', "$this->project/vendor/autoload.php"];
            }
            $cmd = [...$cmd, ...$options, $testFile];

            [$code, $stdout, $stderr, $killed] = Process::run($cmd, $this->project, $env, watch: $watch, timeout: $timeout);
            [$failed, $passed] = $this->parseJunit($junit);
            return new RunResult($code, $stdout . $stderr, $failed, $passed, $this->parseEvents($events), $killed);
        } finally {
            Process::removeDir($tmp);
        }
    }

    /** @return array{list<string>, list<string>} */
    private function parseJunit(string $path): array
    {
        $failed = [];
        $passed = [];
        if (!is_file($path) || filesize($path) === 0) {
            return [$failed, $passed]; // killed before PHPUnit wrote the log
        }
        $doc = new \DOMDocument();
        $doc->load($path);
        foreach ($doc->getElementsByTagName('testcase') as $tc) {
            $broken = $tc->getElementsByTagName('failure')->length + $tc->getElementsByTagName('error')->length > 0;
            if ($broken) {
                $failed[] = $tc->getAttribute('name');
            } else {
                $passed[] = $tc->getAttribute('name');
            }
        }
        return [$failed, $passed];
    }

    /** @return list<string> */
    private function parseEvents(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        preg_match_all(self::EVENT_NOTICE, (string) file_get_contents($path), $matches, PREG_SET_ORDER);
        $noisy = [];
        foreach ($matches as $m) {
            if (!str_contains($m['extra'], 'suppressed')) {
                $parts = explode('::', $m['test']);
                $noisy[] = end($parts);
            }
        }
        return array_values(array_unique($noisy));
    }
}
