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
`--max-cases=N` (default 20), `--select=both|outcome|coverage`, `--search-rounds=N` (default 3),
`--case-timeout=SECONDS` (default 2), `--jobs=N` (default: CPU count, at most 8), `--allow-side-effects`, `-v`.

**Speed:** about 95 % of the time is spent running PHPUnit (recording, search rounds, gate). Classes are
independent, so they are generated in parallel (forked processes, biggest classes first; sequential
without ext-pcntl), with exactly the same output as a sequential run. On 16 phpMyAdmin classes:
14.8 s → 4.7 s for 279 tests (2,370 lines of test code).

**Against a project's own tests:**

| project | project's tests | testor (time) | both |
|---|---|---|---|
| phpMyAdmin, 16 classes | 74.9 % | 67.1 % (4.7 s, 279 tests) | 88.8 % (+174 lines) |
| [dompdf](https://github.com/dompdf/dompdf), all of `src/` (79 files) | 57.8 % | 30.4 % (16.6 s, 1,432 tests) | 66.3 % (+898 lines) |

dompdf is harder (nested frames, styles, canvas): 19 of its 79 classes get no test yet. 10 of them declare
no method of their own; the others have methods that all need a rendering context, or static state that
depends on test order. On dompdf, testor alone went from 13.8 % to 30.4 % through: abstract classes and
protected methods (+8.6 points), destructured arrays and real DOM nodes (+3.2), real objects instead of
stubs (+2.9), calls preparing the state (+1.8).

Where the lines testor misses are (dompdf, 10,588 executable lines): 1,042 in classes without tests, 2,933
in methods never run (their cases fail on an unrealistic state, or they have side effects), 3,397 in
branches not reached in methods that do run.

**Legacy code:** when a parameter or return value has no native type, its PHPDoc type (`@param`, `@return`)
is used. PHPUnit doubles cannot derive a return value from a PHPDoc type, so such methods return a real
object when it can be built without arguments, else an explicit stub.

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
3. **Inputs:** for each public or protected method, a baseline call, then one input varied at a time.
   - **Protected methods** are called through a closure bound to the instance:
     `(fn () => $this->capped(1.5))->call($sut)`.
   - **Abstract classes** are instantiated as a PHPUnit partial mock that implements only their abstract
     methods: `$this->getMockBuilder(Discount::class)->setConstructorArgs([1.5])->onlyMethods(['rate'])->getMock()`.
   - **Objects:** interfaces and non-final classes are replaced with PHPUnit stubs; like EvoSuite, concrete
     classes also get a real object as another option, since a stub has no state at all. Final classes are
     built with their public constructor or a static factory (`from(): static`), using baseline values for
     the required arguments, recursively (real objects all the way down, up to four levels). The class under
     test is built the same way. DOM nodes are created from a document (`(new DOMDocument())->createElement('div')`):
     a double of a DOM class has no underlying node.
   - **Calls preparing the state:** EvoSuite builds call sequences; testor targets them. A method of the
     class that writes a property the tested method reads (`$sut->setWidth(10)` before `$sut->area()`,
     found by static analysis, also through private methods) is an extra input: no call, or that call with
     a few values.
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

   - **Values inferred from usage:** how the code uses a parameter, or the property it is stored in, tells
     its format: `filter_var(…, FILTER_VALIDATE_EMAIL)` gives an e-mail address, `preg_match('/^[A-Z]{2}\d+$/', …)`
     gives a matching string (generated, then checked with `preg_match`), and so on for `json_decode`,
     `strtotime`/`new DateTime`, `explode`, `str_starts_with`, `ctype_*`… `strlen($x) > 10` and `count($x) >= 3`
     give the boundary lengths.
   - **Array shapes:** the keys the code reads on an array (`$p['name']`, `isset($p['x'])`, `array_key_exists`)
     give an array with those keys. The value of each key comes from how the code uses it: comparisons
     and formats as above, casts (`(bool) $p['x']`: `true`), or fallbacks (`$p['n'] ?? 0`: `1`; `?? $this->x`:
     a value of the property's type), arithmetic (a number). Destructuring (`[$top, $right] = $widths`) gives
     positional keys, and `$$side` gets the names of the destructured variables. Nested reads (`$p['a']['b']`) give nested arrays, and
     `foreach ($rows as $row)` gives a list of shapes. Keys are also collected from the private methods
     the value is passed to (`$this->setFormat($settings)`), so a constructor taking `array $settings = []`
     gets one array with every key. Optional parameters with such an inferred domain are explored instead
     of being left out. Arrays longer than 100 characters go in a variable, one entry per line. On the 25
     phpMyAdmin files that read the most keys on their parameters, line coverage goes from 45.5 % to 53.1 %
     (`Config\Settings\Schema`: 80 % → 100 %).
   - **Exceptions from dependencies:** when the tested method catches exceptions, each called dependency
     method also gets a `->willThrowException(…)` option, to cover the `catch` blocks.

   On the demo, `OrderService` goes from 56 % to 100 % line coverage. On 7 phpMyAdmin classes with injected
   dependencies, covered lines go from 30 to 100 (`AccountLocking`: 33 % → 89 %).
4. **Coverage-guided search** (`--search-rounds=N`, default 3): the first round varies one input at a time.
   Each further round takes, for each method still gaining lines, the case that covered the most lines
   as a new baseline and varies one input at a time around it. This combines inputs: `AccountLocking`
   needs `isMariaDB()` → `false` *and* `getVersion()` → `50706`, which no single variation reaches
   (89 % → 100 %). On phpMyAdmin (16 classes), line coverage goes from 61.3 % to 64.0 % with the inferred
   values, then to 66.1 % with the search, for about 1.5 s more.
5. **Recording:** a temporary test file runs every case in a fixed order, and `Runtime/Recorder.php`
   captures the outcome (value, exception, object…, or PHP warnings) and, through spies on the stubs, every
   call made to dependencies with its arguments. Returned values are asserted in full: arrays up to 20 kB,
   printed one item per line when long; objects by their public properties (and their date for
   `DateTimeInterface`), not only their class. A case still running after `--case-timeout` seconds
   (default 2: an infinite loop in the code under test, e.g. a negative step) is dropped and the run
   resumes with the next case.
6. **Selection:** discarded cases are the `TypeError` results caused by testor's own inputs, the cases
   that raise warnings, notices or deprecations, and those that time out. Among the remaining cases, a case
   is kept if (the outcome includes which dependency methods were called, not their arguments):
   - `both` (default): it produces a new outcome for its method **or** it covers new lines;
   - `outcome`: it produces a new (method, outcome) pair;
   - `coverage`: it belongs to the smallest set covering the same lines (greedy set cover).

   On phpMyAdmin (16 classes), `outcome` keeps 218 tests (59.5 % line coverage), `coverage` keeps 121 tests
   (60.6 %) and `both` keeps 232 tests (60.9 %). Different inputs can return the same value through different
   branches, and different values can come from a single line (`return $a ? 'x' : 'y';`): each strategy
   misses what the other one catches.
7. **Interaction assertions:** stubs whose calls were recorded become mocks expecting them:
   `->expects($this->once())->method('charge')->with(3.0)`, `exactly(n)`, or `never()` when the method
   was not called (when `find()` returns `null`, nothing is charged). Arguments that are test doubles are
   asserted by type (`$this->isInstanceOf(ResultInterface::class)`).
8. **Gate:** tests that fail, raise warnings or are risky (read from PHPUnit's event log) are removed.
   PHPUnit always runs with `--no-coverage --no-logging`, so the project's own coverage reports and logs
   are never produced (they are slow, and they would write into the project).
   Two consecutive clean runs are required. Generated classes use `#[BackupGlobals]` and
   `#[BackupStaticProperties]` so results don't depend on test order.

Test names say what the test checks and which input differs from the baseline call:
`testPayThrowsDomainExceptionWhenFindReturnsNull`, `testGradeReturnsAWithScore90`,
`testItemsOrZeroReturns0WhenItemCountThrows`.

### Measuring test strength

Line coverage says what the tests run, not what they check. Mutation testing does: [Infection](https://infection.github.io)
changes the code (`+` into `-`, `>=` into `>`…) and counts the changes the tests detect (MSI). With
`.tools/infection.phar`, a phpunit.xml whose test suite is the generated directory, and an infection.json5
pointing at it (keep both outside the project). Covered code MSI:

| | interaction assertions | + full arrays and object state |
|---|---|---|
| 7 phpMyAdmin classes with injected dependencies | 63 % → 73 % | 73 % |
| 16 phpMyAdmin classes (mostly static helpers) | 52 % → 52 % | **64 %** |
| demo | 82 % → 83 % | 83 % |

Tried and dropped because they did not pay off on these benchmarks: seeding integer parameters with the
integer constants of the method (+4 killed mutants for +27 tests), sizes from `substr`/`array_slice`
arguments, calling with optional arguments left out, round-robin ordering of variations, varying what
the abstract methods called by a template method return (+1 line on phpMyAdmin's 37 abstract classes),
allowing side effects (no gain on dompdf: those methods fail on the object's state anyway).

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

## License

testor is released under the [MIT License](LICENSE).

The tests testor generates are yours: the license covers the tool, not what it produces from your code.
You can use, modify and license the generated tests however you like, without attribution.
