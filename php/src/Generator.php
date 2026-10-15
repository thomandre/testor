<?php

declare(strict_types=1);

namespace Testor;

use Testor\Cases\CaseBuilder;
use Testor\Cases\Imports;
use Testor\Cases\Scenario;
use Testor\Cases\Values;
use Testor\PhpUnit\Runner;
use Testor\Runtime\Recorder;
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
    private Values $values;

    public function __construct(
        private readonly string $project,
        private readonly string $outDir,
        private readonly int $maxCases = 20,
        private readonly bool $allowSideEffects = false,
        private readonly bool $verbose = false,
        private readonly string $select = 'both',
        private readonly int $searchRounds = 3,
        private readonly float $caseTimeout = 2.0,
        private readonly int $jobs = 1,
    ) {
        if (!in_array($select, ['outcome', 'coverage', 'both'], true)) {
            throw new \InvalidArgumentException("Unknown selection strategy: $select");
        }
        $this->runner = new Runner($project);
        $this->renderer = new Renderer();
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
        $this->values = new Values($data['types']);
        $reports = array_map(fn (array $e) => new ClassReport($e['class'], error: $e['error']), $data['errors']);
        $classes = $data['classes'];
        if ($this->jobs > 1 && count($classes) > 1 && function_exists('pcntl_fork')) {
            return [...$reports, ...$this->generateInParallel($classes)];
        }
        foreach ($classes as $class) {
            $reports[] = $this->generateClass($class);
        }
        return $reports;
    }

    /**
     * Classes are independent: each one is generated in a forked child process, at most $jobs at a
     * time, the most expensive first (so a big class does not end up running alone at the end).
     * Reports come back in the classes' order, so the result is the same as a sequential run.
     *
     * @param list<array> $classes
     * @return list<ClassReport>
     */
    private function generateInParallel(array $classes): array
    {
        $cost = fn (array $class) => array_sum(array_map(fn (array $m) => 1 + count($m['params']), $class['methods']));
        $queue = array_keys($classes);
        usort($queue, fn (int $a, int $b) => $cost($classes[$b]) <=> $cost($classes[$a]));

        $tmp = Process::tempDir();
        $running = [];
        $reports = [];
        try {
            while ($queue !== [] || $running !== []) {
                while ($queue !== [] && count($running) < $this->jobs) {
                    $i = array_shift($queue);
                    $pid = pcntl_fork();
                    if ($pid === -1) {
                        $reports[$i] = $this->generateClass($classes[$i]); // cannot fork: do it here
                    } elseif ($pid === 0) {
                        try {
                            $report = $this->generateClass($classes[$i]);
                        } catch (\Throwable $e) {
                            $report = new ClassReport($classes[$i]['name'], error: $e->getMessage());
                        }
                        file_put_contents("$tmp/$i", serialize($report));
                        exit(0);
                    } else {
                        $running[$pid] = $i;
                    }
                }
                if ($running === []) {
                    continue;
                }
                $pid = pcntl_wait($status);
                if (isset($running[$pid])) {
                    $i = $running[$pid];
                    unset($running[$pid]);
                    $report = is_file("$tmp/$i") ? unserialize((string) file_get_contents("$tmp/$i")) : false;
                    $reports[$i] = $report instanceof ClassReport
                        ? $report
                        : new ClassReport($classes[$i]['name'], error: 'the generation process for this class crashed');
                }
            }
        } finally {
            Process::removeDir($tmp);
        }
        ksort($reports);
        return array_values($reports);
    }

    private function generateClass(array $class): ClassReport
    {
        $report = new ClassReport($class['name']);
        if ($class['kind'] !== 'class') {
            $report->error = "{$class['kind']}: skipped";
            return $report;
        }

        $imports = new Imports(); // filled by the value strategies (stubs, enums, constants)
        $builder = new CaseBuilder($this->maxCases, $this->allowSideEffects, $this->values->withHints($class['readProperties']));
        [$cases, $report->skipped] = $builder->build($class, $imports);
        $report->explored = count($cases);
        if ($cases === []) {
            $report->error = 'no testable public method';
            return $report;
        }

        // 1. Recording pass: observe what the code actually does for each input (and the lines it runs),
        //    then coverage-guided search around the cases that reached the most lines.
        [$records, $output] = $this->record($class, $cases, $imports);
        if (isset($records['executable'])) {
            [$cases, $records] = $this->search($class, $builder, $cases, $records, $imports);
            $report->explored = count($cases);
        }
        $outcomes = $records['cases'] ?? [];
        if ($outcomes === []) {
            $report->error = "recording pass produced nothing:\n" . substr($output, -2000);
            return $report;
        }

        $candidates = [];
        foreach ($cases as $case) {
            $outcome = $outcomes[$case->id] ?? null;
            if ($outcome === null || $this->isNoise($outcome)) {
                continue;
            }
            $lines = self::lineKeys($outcome['covered'] ?? []);
            unset($outcome['covered']);
            $candidates[] = [$case, $outcome, $lines];
        }

        // 2. Selection: one test per distinct (method, outcome), and/or the smallest set covering the same lines.
        $measured = isset($records['executable']);
        $selected = match ($measured ? $this->select : 'outcome') {
            'outcome' => $this->byOutcome($candidates),
            'coverage' => $this->byCoverage($candidates),
            'both' => self::union($candidates, $this->byOutcome($candidates), $this->byCoverage($candidates)),
        };

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
            // Every test passed recording, so a hang here is unexpected: bounded anyway.
            $result = $this->runner->run($target, timeout: max(60.0, count($selected) * $this->caseTimeout));
            if ($result->killed) {
                $report->error = 'the generated tests timed out';
                $selected = [];
                break;
            }
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
            if ($measured) {
                [$report->executableLines, $report->coveredLines] = $this->classCoverage($class, $records['executable'], $selected);
            }
        } elseif (is_file($target)) {
            unlink($target);
        }
        return $report;
    }

    /**
     * Runs every scenario once and returns the records ({cases: id => outcome, executable: file => lines}),
     * plus PHPUnit's output. Cases run in a fixed order; a case still running after the case timeout
     * (an infinite loop in the code under test) gets a "timeout" outcome and the run resumes after it.
     *
     * @param list<Scenario> $cases
     * @return array{array, string}
     */
    private function record(array $class, array $cases, Imports $imports): array
    {
        $records = ['cases' => []];
        $output = '';
        $pending = $cases;
        while ($pending !== []) {
            $tmp = Process::tempDir();
            try {
                $testFile = "$tmp/" . Imports::short($class['name']) . 'Test.php';
                $recordFile = "$tmp/records.json";
                file_put_contents($testFile, $this->renderer->recording($class, $pending, $imports, __DIR__ . '/Runtime/Recorder.php'));
                $running = "$recordFile.running";
                $watch = function () use ($running): bool {
                    clearstatcache(true, $running);
                    return is_file($running) && microtime(true) - (float) @filemtime($running) > $this->caseTimeout;
                };
                $result = $this->runner->run($testFile, ['TESTOR_RECORD_FILE' => $recordFile], ['--order-by=default'], $watch);
                $output .= $result->output;

                $run = Recorder::read($recordFile);
                $records['cases'] = [...$records['cases'], ...$run['cases'] ?? []];
                if (isset($run['executable'])) {
                    $records['executable'] = array_replace($records['executable'] ?? [], $run['executable']);
                }
                if (!$result->killed) {
                    break; // cases without a record failed before recording (e.g. while building the object)
                }
                $stuck = is_file($running) ? trim((string) file_get_contents($running)) : null;
                if ($stuck === null || $stuck === '') {
                    break;
                }
                $records['cases'][$stuck] = ['kind' => 'timeout'];
                $pending = array_values(array_filter($pending, fn (Scenario $c) => !isset($records['cases'][$c->id])));
            } finally {
                Process::removeDir($tmp);
            }
        }
        return [$records, $output];
    }

    /**
     * Coverage-guided search: for each method still gaining lines, take the case that covered the most
     * lines (not used as a center yet) as the new baseline, record the cases varying one input at a time
     * around it, and repeat. Combines inputs the first round only varied separately.
     *
     * @param list<Scenario> $cases
     * @return array{list<Scenario>, array} all cases and merged records
     */
    private function search(array $class, CaseBuilder $builder, array $cases, array $records, Imports $imports): array
    {
        $centers = [];
        $gaining = null; // methods whose coverage grew in the last round (null: all)
        for ($round = 0; $round < $this->searchRounds; $round++) {
            $byMethod = [];
            foreach ($cases as $case) {
                $outcome = $records['cases'][$case->id] ?? null;
                if ($outcome !== null && !$this->isNoise($outcome)) {
                    $byMethod[$case->method['name']][] = [$case, self::lineKeys($outcome['covered'] ?? [])];
                }
            }

            $new = [];
            $coveredBefore = [];
            foreach ($byMethod as $method => $entries) {
                $coveredBefore[$method] = array_flip(array_merge([], ...array_column($entries, 1)));
                if ($gaining !== null && !isset($gaining[$method])) {
                    continue;
                }
                usort($entries, fn (array $a, array $b) => count($b[1]) <=> count($a[1]));
                foreach ($entries as [$case]) {
                    $key = $method . ':' . implode(',', $case->choice);
                    if (!isset($centers[$key])) {
                        $centers[$key] = true;
                        array_push($new, ...$builder->explore($case));
                        break;
                    }
                }
            }
            if ($new === []) {
                break;
            }

            [$more] = $this->record($class, $new, $imports);
            $records['cases'] = [...$records['cases'], ...$more['cases'] ?? []];
            $records['executable'] = array_replace($records['executable'], $more['executable'] ?? []);
            $cases = [...$cases, ...$new];

            $gaining = [];
            foreach ($new as $case) {
                $outcome = $more['cases'][$case->id] ?? null;
                if ($outcome === null || $this->isNoise($outcome)) {
                    continue;
                }
                $method = $case->method['name'];
                if (array_diff_key(array_flip(self::lineKeys($outcome['covered'] ?? [])), $coveredBefore[$method] ?? []) !== []) {
                    $gaining[$method] = true;
                }
            }
            if ($gaining === []) {
                break;
            }
        }
        return [$cases, $records];
    }

    /** Candidates present in any of the selections, in their original order. */
    private static function union(array $candidates, array ...$selections): array
    {
        $ids = [];
        foreach ($selections as $selection) {
            foreach ($selection as [$case]) {
                $ids[$case->id] = true;
            }
        }
        return array_values(array_filter($candidates, fn (array $c) => isset($ids[$c[0]->id])));
    }

    /** @param list<array{Scenario, array, list<string>}> $candidates */
    private function byOutcome(array $candidates): array
    {
        $seen = [];
        $selected = [];
        foreach ($candidates as $candidate) {
            [$case, $outcome] = $candidate;
            // Interactions count by shape (which methods, how many calls), not by arguments: otherwise
            // every input passed on to a dependency would look like a new behaviour.
            $shape = array_map(fn (array $call) => [$call[0], $call[1]], $outcome['calls'] ?? []);
            $signature = $case->method['name'] . json_encode(self::sortKeys(['calls' => $shape] + $outcome));
            if (!isset($seen[$signature])) {
                $seen[$signature] = true;
                $selected[] = $candidate;
            }
        }
        return $selected;
    }

    /**
     * Greedy set cover: repeatedly take the case adding the most uncovered lines (earliest on ties),
     * until no case adds any. Selected cases keep their original order.
     *
     * @param list<array{Scenario, array, list<string>}> $candidates
     */
    private function byCoverage(array $candidates): array
    {
        $covered = [];
        $picked = [];
        while (true) {
            $best = null;
            $bestGain = 0;
            foreach ($candidates as $i => [, , $lines]) {
                if (isset($picked[$i])) {
                    continue;
                }
                $gain = count(array_diff_key(array_flip($lines), $covered));
                if ($gain > $bestGain) {
                    [$best, $bestGain] = [$i, $gain];
                }
            }
            if ($best === null) {
                break;
            }
            $picked[$best] = true;
            $covered += array_flip($candidates[$best][2]);
        }
        ksort($picked);
        return array_values(array_intersect_key($candidates, $picked));
    }

    /**
     * Executable and covered lines of the class body (its own file and line range).
     *
     * @return array{int, int}
     */
    private function classCoverage(array $class, array $executable, array $selected): array
    {
        $inClass = fn (int $line) => $line >= $class['startLine'] && $line <= $class['endLine'];
        $lines = array_filter($executable[$class['file']] ?? [], $inClass);
        $covered = [];
        foreach ($selected as [, , $keys]) {
            $covered += array_flip($keys);
        }
        $hit = array_filter($lines, fn (int $line) => isset($covered["{$class['file']}:$line"]));
        return [count($lines), count($hit)];
    }

    /** @param array<string, list<int>> $covered file => lines */
    private static function lineKeys(array $covered): array
    {
        $keys = [];
        foreach ($covered as $file => $lines) {
            foreach ($lines as $line) {
                $keys[] = "$file:$line";
            }
        }
        return $keys;
    }

    private function isNoise(array $outcome): bool
    {
        return match ($outcome['kind']) {
            'other', 'notice', 'timeout' => true,
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
