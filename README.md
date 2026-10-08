# testor

Unit test generator that works **without an LLM**. It produces characterization tests:
it runs the code, observes what it does, and locks that behaviour in.

Each language is a native tool, written in that language and distributed through its
ecosystem. The tools share the same pipeline and CLI conventions, not code.

| Language | Directory | Status |
|---|---|---|
| PHP (PHPUnit) | [`php/`](php/) | working |
| Python, JS/TS, Java, C#, Go | — | planned |

## PHP

```bash
composer require --dev testor/testor      # once published; for now, see "Try it"
vendor/bin/testor src/
```

Options: `--project=DIR` (default: cwd), `--out=DIR` (default: `<project>/tests/Testor`),
`--max-cases=N` (default 20), `--select=both|outcome|coverage`, `--allow-side-effects`, `-v`.

**Coverage:** with PCOV (recommended, `pecl install pcov`) or Xdebug in coverage mode, testor measures
the lines each case executes and reports line coverage for every tested class. Without a coverage driver,
everything still works: selection falls back to `outcome` and no coverage is reported.

The target project needs `vendor/autoload.php` and `phpunit/phpunit` (10+).

### Pipeline

1. **Static analysis** (`Analysis/`, nikic/php-parser, no code loaded):
   - **Side effects:** calls to files, network, processes, session, headers, `exit`…, followed through
     `$this->x()` and `self::x()`. Methods with side effects are skipped unless `--allow-side-effects` is passed.
   - **Finite domains:** values are taken from PHPDoc types (`'asc'|'desc'`, `self::LEVEL_*`, `int<0, 100>`,
     `positive-int`…), treated as exhaustive, and from constants the method compares the parameter with
     (`=== 'x'`, `in_array`, `array_key_exists`, `match`/`switch`, including on expressions such as
     `match (strtolower($p))`). Integer thresholds (`$n >= 90`) yield the boundary values 89, 90 and 91.
2. **Reflection** (`Runtime/worker.php`): runs in a separate process with the *project's* autoloader and
   no dependency on testor's packages. It provides exact signatures, resolves class constant values, and
   builds a registry of the referenced types: how to construct them, and the return type of each method
   of the stubbable ones.
3. **Inputs:** for each public method, a baseline call, then one input varied at a time.
   - **Objects:** interfaces and non-final classes are replaced with PHPUnit stubs. Final classes are built
     with their public constructor or a static factory (`from(): static`), using baseline values for the
     required arguments, recursively (two levels). The class under test is built the same way.
   - **Stub return values:** the static analysis finds which methods of each dependency are called
     (`$this->repo->find()`, `$param->charge()`, also through private methods and in the constructor).
     Their return values are extra inputs, configured with `->method('find')->willReturn(…)` and varied like
     parameters: `null` for nullable returns, `true`/`false`, built objects, enum cases…
   - **Where return values go:** when a dependency's result is compared with a constant
     (`$this->db->query() === false`, `$v = …; if ($v >= 80000)`) or passed to another method
     (`Compatibility::hasAccountLocking($this->dbi->getVersion())`), the constants, or the domain of the
     receiving parameter (its class is analysed too), are added as return values to try.
   - **Optional constructor arguments:** for built objects, optional arguments named after a property the
     code under test reads (`$order->status`) are varied with named arguments
     (`new Order(1, 1.5, status: OrderStatus::Paid)`); the others keep their default.

   On the demo, `OrderService` goes from 56 % to 100 % line coverage. On 7 phpMyAdmin classes with injected
   dependencies, covered lines go from 30 to 100 (`AccountLocking`: 33 % → 89 %).
4. **Recording:** a temporary test file runs every case, and `Runtime/Recorder.php` captures the outcome
   (value, exception, object…, or PHP warnings).
5. **Selection:** discarded cases are the `TypeError` results caused by testor's own inputs and the cases
   that raise warnings, notices or deprecations. Among the remaining cases, a case is kept if:
   - `both` (default): it produces a new outcome for its method **or** it covers new lines;
   - `outcome`: it produces a new (method, outcome) pair;
   - `coverage`: it belongs to the smallest set covering the same lines (greedy set cover).

   On phpMyAdmin (16 classes), `outcome` keeps 218 tests (59.5 % line coverage), `coverage` keeps 121 tests
   (60.6 %) and `both` keeps 232 tests (60.9 %). Different inputs can return the same value through different
   branches, and different values can come from a single line (`return $a ? 'x' : 'y';`): each strategy
   misses what the other one catches.
6. **Gate:** tests that fail or raise warnings (read from PHPUnit's event log) are removed.
   PHPUnit always runs with `--no-coverage --no-logging`, so the project's own coverage reports and logs
   are never produced (they are slow, and they would write into the project).
   Two consecutive clean runs are required. Generated classes use `#[BackupGlobals]` and
   `#[BackupStaticProperties]` so results don't depend on test order.

These are **characterization tests**: they protect against regressions, but they cannot tell
whether the current behaviour is correct.

### Try it

```bash
php .tools/composer.phar install -d php
php .tools/composer.phar install -d examples/php-demo
php/bin/testor --project=examples/php-demo examples/php-demo/src
```

### Developing testor

```bash
cd php
php ../.tools/composer.phar install
composer test                            # unit + integration (~5 s); sets pcov.directory
vendor/bin/phpunit --testsuite unit      # unit only, no external process
```

- **Unit tests** (`tests/Unit/`): static analysis on inline sources, value strategies, case building,
  rendering and the Recorder.
- **Integration tests** (`tests/Integration/`): Reflection worker and the full pipeline on
  `examples/php-demo`. They are skipped if that project's `vendor/` is missing.
