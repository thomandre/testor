<?php

declare(strict_types=1);

namespace Testor;

use Testor\Cases\CaseBuilder;
use Testor\Cases\Imports;
use Testor\Cases\Scenario;
use Testor\PhpUnit\Runner;
use Testor\Render\Renderer;

/**
 * Test generation pipeline:
 * introspect → build input scenarios → recording run (observe real outcomes)
 * → one test per distinct outcome → render PHPUnit tests → keep only tests that pass
 *   without warnings twice in a row.
 */
final class Generator
{
    /** Outcomes caused by our own inputs rather than by the code under test. */
    private const NOISE_EXCEPTIONS = ['TypeError', 'ArgumentCountError'];
    private const MAX_GATE_ROUNDS = 5;

    private readonly Runner $runner;
    private readonly Renderer $renderer;
    private readonly CaseBuilder $builder;

    public function __construct(
        private readonly string $project,
        private readonly string $outDir,
        int $maxCases = 20,
        bool $allowSideEffects = false,
        private readonly bool $verbose = false,
    ) {
        $this->runner = new Runner($project);
        $this->renderer = new Renderer();
        $this->builder = new CaseBuilder($maxCases, $allowSideEffects);
    }

    /**
     * @param list<string> $paths source files or directories
     * @return list<ClassReport>
     */
    public function generate(array $paths): array
    {
        $problem = $this->runner->check();
        if ($problem !== null) {
            throw new \RuntimeException($problem);
        }

        $data = (new Introspector())->describe($this->project, $this->phpFiles($paths));
        $reports = array_map(fn (array $e) => new ClassReport($e['class'], error: $e['error']), $data['errors']);
        foreach ($data['classes'] as $class) {
            $reports[] = $this->generateClass($class);
        }
        return $reports;
    }

    private function generateClass(array $class): ClassReport
    {
        $report = new ClassReport($class['name']);
        if ($class['kind'] !== 'class') {
            $report->error = "{$class['kind']}: skipped";
            return $report;
        }

        $imports = new Imports(); // filled by the value strategies (stubs, enums, constants)
        [$cases, $report->skipped] = $this->builder->build($class, $imports);
        $report->explored = count($cases);
        if ($cases === []) {
            $report->error = 'no testable public method';
            return $report;
        }

        // 1. Recording pass: observe what the code actually does for each input.
        [$outcomes, $output] = $this->record($class, $cases, $imports);
        if ($outcomes === []) {
            $report->error = "recording pass produced nothing:\n" . substr($output, -2000);
            return $report;
        }

        // 2. Keep one case per distinct (method, outcome).
        $seen = [];
        $selected = [];
        foreach ($cases as $case) {
            $outcome = $outcomes[$case->id] ?? null;
            if ($outcome === null || $this->isNoise($outcome)) {
                continue;
            }
            $signature = $case->method['name'] . json_encode(self::sortKeys($outcome));
            if (!isset($seen[$signature])) {
                $seen[$signature] = true;
                $selected[] = [$case, $outcome];
            }
        }

        // 3. Gate: drop tests that fail or raise warnings; require two clean runs in a row.
        $nsParts = array_slice(explode('\\', $class['name']), 0, -1);
        $namespace = implode('\\', ['Tests', 'Testor', ...$nsParts]);
        $target = implode('/', [$this->outDir, ...$nsParts, Imports::short($class['name']) . 'Test.php']);
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        $cleanRuns = 0;
        for ($round = 0; $round < self::MAX_GATE_ROUNDS && $selected !== []; $round++) {
            [$source, $names] = $this->renderer->final($class, $selected, $imports, $namespace);
            file_put_contents($target, $source);
            $result = $this->runner->run($target);
            if ($this->verbose && $result->failed !== []) {
                fwrite(STDERR, $result->output);
            }
            if ($result->failed === [] && $result->passed === []) {
                $report->error = "PHPUnit did not run the generated file:\n" . $result->output;
                break;
            }
            $rejected = array_unique([...$result->failed, ...$result->noisy]);
            if ($rejected !== []) {
                $cleanRuns = 0;
                $report->unstable += count($rejected);
                $selected = array_values(array_filter(
                    $selected,
                    fn (array $sc, int $i) => !in_array($names[$i], $rejected, true),
                    ARRAY_FILTER_USE_BOTH,
                ));
                continue;
            }
            if (++$cleanRuns === 2) {
                break;
            }
        }

        if ($selected !== [] && $cleanRuns === 2) {
            $report->output = $target;
            $report->kept = count($selected);
        } elseif (is_file($target)) {
            unlink($target);
        }
        return $report;
    }

    /**
     * Runs every scenario once and returns the observed outcome per scenario id, plus PHPUnit's output.
     *
     * @param list<Scenario> $cases
     * @return array{array<string, array>, string}
     */
    private function record(array $class, array $cases, Imports $imports): array
    {
        $tmp = Process::tempDir();
        try {
            $testFile = "$tmp/" . Imports::short($class['name']) . 'Test.php';
            $recordFile = "$tmp/records.json";
            file_put_contents($testFile, $this->renderer->recording($class, $cases, $imports, __DIR__ . '/Runtime/Recorder.php'));
            $result = $this->runner->run($testFile, ['TESTOR_RECORD_FILE' => $recordFile]);
            $outcomes = is_file($recordFile) ? json_decode((string) file_get_contents($recordFile), true) : [];
            return [$outcomes ?: [], $result->output];
        } finally {
            Process::removeDir($tmp);
        }
    }

    private function isNoise(array $outcome): bool
    {
        return match ($outcome['kind']) {
            'other', 'notice' => true,
            'throws' => in_array($outcome['class'], self::NOISE_EXCEPTIONS, true) || str_starts_with($outcome['class'], 'PHPUnit\\'),
            default => false,
        };
    }

    private static function sortKeys(array $value): array
    {
        ksort($value);
        return array_map(fn ($v) => is_array($v) ? self::sortKeys($v) : $v, $value);
    }

    /** @return list<string> */
    private function phpFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $found = [];
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $file) {
                    if ($file->isFile() && $file->getExtension() === 'php') {
                        $found[] = $file->getPathname();
                    }
                }
                sort($found, SORT_STRING);
                $files = [...$files, ...$found];
            } else {
                $files[] = $path;
            }
        }
        return $files;
    }
}
