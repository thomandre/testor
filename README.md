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
`--max-cases=N` (default 20), `--allow-side-effects`, `-v`.

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
   no dependency on testor's packages. It provides exact signatures and resolves class constant values.
3. **Inputs:** for each public method, a baseline call, then one variation per parameter.
   Interfaces and non-final classes are replaced with PHPUnit stubs. If the constructor is private,
   a static factory (`from(): static`) is used instead.
4. **Recording:** a temporary test file runs every case, and `Runtime/Recorder.php` captures the outcome
   (value, exception, object…, or PHP warnings).
5. **Selection:** one test per distinct (method, outcome) pair. Discarded: `TypeError` results caused by
   testor's own inputs, and cases that raise warnings, notices or deprecations.
6. **Gate:** tests that fail or raise warnings (read from PHPUnit's event log) are removed.
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
vendor/bin/phpunit                       # unit + integration (~4 s)
vendor/bin/phpunit --testsuite unit      # unit only, no external process
```

- **Unit tests** (`tests/Unit/`): static analysis on inline sources, value strategies, case building,
  rendering and the Recorder.
- **Integration tests** (`tests/Integration/`): Reflection worker and the full pipeline on
  `examples/php-demo`. They are skipped if that project's `vendor/` is missing.
