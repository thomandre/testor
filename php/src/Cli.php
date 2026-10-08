<?php

declare(strict_types=1);

namespace Testor;

final class Cli
{
    private const USAGE = <<<'TXT'
        Usage: testor [options] <source files or directories>...

        Generates PHPUnit characterization tests without an LLM.

        Options:
          --project=DIR          project root containing vendor/ (default: current directory)
          --out=DIR              output directory (default: <project>/tests/Testor)
          --max-cases=N          input combinations explored per method (default: 20)
          --select=STRATEGY      both: keep a case if it has a new outcome or covers new lines (default)
                                 outcome: one test per distinct outcome
                                 coverage: smallest set of tests covering the same lines
                                 (coverage and both need PCOV or Xdebug, else outcome is used)
          --allow-side-effects   also test methods that touch files, network, processes, sessions or headers
          -v, --verbose          show PHPUnit output for rejected tests
          -h, --help             show this help

        TXT;

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        $options = ['project' => getcwd(), 'out' => null, 'max-cases' => '20', 'select' => 'both'];
        $flags = ['allow-side-effects' => false, 'verbose' => false];
        $paths = [];

        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                echo self::USAGE;
                return 0;
            }
            if ($arg === '-v' || $arg === '--verbose') {
                $flags['verbose'] = true;
            } elseif ($arg === '--allow-side-effects') {
                $flags['allow-side-effects'] = true;
            } elseif (preg_match('/^--(project|out|max-cases|select)=(.+)$/', $arg, $m)) {
                $options[$m[1]] = $m[2];
            } elseif (str_starts_with($arg, '-')) {
                fwrite(STDERR, "Unknown option: $arg\n\n" . self::USAGE);
                return 2;
            } else {
                $paths[] = $arg;
            }
        }
        if ($paths === []) {
            fwrite(STDERR, self::USAGE);
            return 2;
        }

        $project = realpath($options['project']);
        if ($project === false) {
            fwrite(STDERR, "error: project directory not found: {$options['project']}\n");
            return 1;
        }
        $out = $options['out'] ?? "$project/tests/Testor";
        if (!is_dir($out)) {
            mkdir($out, 0777, true);
        }
        $resolved = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real === false) {
                fwrite(STDERR, "error: path not found: $path\n");
                return 1;
            }
            $resolved[] = $real;
        }

        try {
            $generator = new Generator($project, (string) realpath($out), (int) $options['max-cases'],
                $flags['allow-side-effects'], $flags['verbose'], $options['select']);
            $reports = $generator->generate($resolved);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            fwrite(STDERR, "error: {$e->getMessage()}\n");
            return 1;
        }

        $total = 0;
        [$executable, $covered] = [0, 0];
        foreach ($reports as $r) {
            if ($r->output !== null) {
                $total += $r->kept;
                $executable += (int) $r->executableLines;
                $covered += (int) $r->coveredLines;
                $coverage = $r->coverage() === null ? '' : sprintf(', %.0f%% lines', $r->coverage());
                echo "✓ {$r->name}: {$r->kept} tests$coverage ({$r->explored} cases explored"
                    . ($r->unstable ? ", {$r->unstable} unstable dropped" : '') . ") → {$r->output}\n";
            } else {
                echo "- {$r->name}: " . ($r->error ?? 'no stable test produced') . "\n";
            }
            foreach ($r->skipped as [$method, $reason]) {
                echo "    skipped $method(): $reason\n";
            }
        }
        echo "\n$total tests generated.";
        if ($executable > 0) {
            printf(' Line coverage of the tested classes: %.1f%% (%d/%d).', 100 * $covered / $executable, $covered, $executable);
        }
        echo "\n";
        return 0;
    }
}
