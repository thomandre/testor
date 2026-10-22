<?php

declare(strict_types=1);

namespace Testor\Render;

use Testor\Cases\Arg;
use Testor\Cases\Imports;
use Testor\Cases\Labels;
use Testor\Cases\Scenario;
use Testor\Cases\Val;

/** Renders PHPUnit test files: a throwaway recording file and the final test file. */
final class Renderer
{
    private const INDENT = '        ';
    /** Array arguments longer than this go in a variable, one entry per line. */
    private const MAX_INLINE_ARRAY = 100;
    private const RECORDER = 'Testor\\Runtime\\Recorder';

    public static function phpString(string $s): string
    {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
    }

    /**
     * Setup lines and the call expression of a scenario. Stubs are configured three ways:
     *  - recording: spied methods go through Recorder::spy() to record their calls;
     *  - final, with the recorded $calls: stubs with spied methods become mocks expecting those calls;
     *  - otherwise: plain willReturn()/willThrowException().
     *
     * @param list<array{string, string, list<array>}>|null $calls recorded [stub key, method, arguments]
     * @return array{list<string>, string}
     */
    public function arrange(Scenario $case, string $sut, ?array $calls = null, bool $recording = false,
                            ?Imports $imports = null): array
    {
        $lines = [];
        $expectations = []; // declared after construction: calls made while building the object don't count
        $used = ['sut' => true, 'this' => true];
        // A protected method is called from a closure bound to the instance, where $this is not the test.
        $bound = $case->method['protected'] ?? false;
        $use = function (Arg $arg, string $key) use (&$lines, &$expectations, &$used, $calls, $recording, $imports, $bound): string {
            $long = str_starts_with($arg->val->expr, '[') && strlen($arg->val->expr) > self::MAX_INLINE_ARRAY;
            if (!$arg->val->hoist && !$long && !($bound && str_contains($arg->val->expr, '$this'))) {
                return $arg->val->expr;
            }
            $var = $arg->name;
            for ($n = 2; isset($used[$var]); $n++) {
                $var = $arg->name . $n;
            }
            $used[$var] = true;
            if ($long) {
                $formatted = self::formatArray($arg->val->expr);
                $formatted[0] = "\$$var = {$formatted[0]}";
                $formatted[count($formatted) - 1] .= ';';
                array_push($lines, ...$formatted);
                return "\$$var";
            }
            $val = $arg->val;
            $spied = $val->spiedMethods();
            $mock = !$recording && $calls !== null && $spied !== [];
            $expr = $mock ? str_replace('$this->createStub(', '$this->createMock(', $val->expr) : $val->expr;
            $lines[] = "\$$var = $expr;";
            foreach ($spied as $method) {
                $outcome = isset($val->throws[$method]) ? "throw {$val->throws[$method]}" : ($val->returns[$method] ?? 'null');
                if ($recording) {
                    $lines[] = '$' . $var . "->method('$method')->willReturnCallback(\\" . self::RECORDER
                        . "::spy('$key', '$method', fn () => $outcome));";
                } elseif ($mock) {
                    // Return values are configured before construction (the constructor may need them),
                    // expectations after it.
                    if (isset($val->throws[$method])) {
                        $lines[] = '$' . $var . "->method('$method')->willThrowException({$val->throws[$method]});";
                    } elseif (isset($val->returns[$method])) {
                        $lines[] = '$' . $var . "->method('$method')->willReturn({$val->returns[$method]});";
                    }
                    $expectations[] = '$' . $var . $this->expectation($method, $val, self::callsTo($calls, $key, $method), $imports);
                } elseif (isset($val->throws[$method])) {
                    $lines[] = '$' . $var . "->method('$method')->willThrowException({$val->throws[$method]});";
                } elseif (isset($val->returns[$method])) {
                    $lines[] = '$' . $var . "->method('$method')->willReturn({$val->returns[$method]});";
                }
            }
            return "\$$var";
        };

        // Arguments are rendered before $lines is returned: rendering them declares their stubs.
        $callArgs = fn (array $args) => implode(', ', array_map(fn (Arg $a) => $use($a, "arg:{$a->name}"), $args));
        $name = $case->method['name'];
        if ($case->construction === null) {
            $args = $callArgs($case->args);
            $call = $bound ? "\\Closure::bind(static fn () => $sut::$name($args), null, $sut::class)()" : "$sut::$name($args)";
            return [[...$lines, ...$expectations], $call];
        }

        $build = $case->construction;
        $ctorArgs = implode(', ', array_map(fn (Arg $a) => $use($a, "ctor:{$a->name}"), $build->args));
        $lines[] = match (true) {
            $build->abstract !== null => "\$sut = " . self::partialMock($sut, $ctorArgs, $build->abstract) . ';',
            $build->factory !== null => "\$sut = $sut::{$build->factory}($ctorArgs);",
            default => "\$sut = new $sut($ctorArgs);",
        };
        foreach ($case->before as $prepare) {
            $prepareName = $prepare['method']['name'];
            $prepareArgs = implode(', ', array_map(fn (Arg $a) => $use($a, "before:{$prepareName}:{$a->name}"), $prepare['args']));
            $lines[] = ($prepare['method']['protected'] ?? false)
                ? "(fn () => \$this->$prepareName($prepareArgs))->call(\$sut);"
                : "\$sut->$prepareName($prepareArgs);";
        }
        $args = $callArgs($case->args);
        $call = $bound ? "(fn () => \$this->$name($args))->call(\$sut)" : "\$sut->$name($args)";
        return [[...$lines, ...$expectations], $call];
    }

    /** An instance of an abstract class: PHPUnit implements its abstract methods with default return values. */
    private static function partialMock(string $sut, string $ctorArgs, array $abstract): string
    {
        $methods = implode(', ', array_map(self::phpString(...), $abstract));
        return "\$this->getMockBuilder($sut::class)"
            . ($ctorArgs === '' ? '' : "->setConstructorArgs([$ctorArgs])")
            . "->onlyMethods([$methods])->getMock()";
    }

    /**
     * An array literal on several lines when it is long: one entry per line, long nested arrays too.
     *
     * @return list<string>
     */
    public static function formatArray(string $expr, int $width = self::MAX_INLINE_ARRAY): array
    {
        $entries = strlen($expr) > $width && str_starts_with($expr, '[') && str_ends_with($expr, ']')
            ? self::split(substr($expr, 1, -1), ',')
            : null;
        if ($entries === null || $entries === []) {
            return [$expr];
        }
        $lines = ['['];
        foreach ($entries as $entry) {
            $parts = self::split($entry, '=>');
            $value = end($parts);
            $key = count($parts) === 2 ? $parts[0] . ' => ' : '';
            $sub = self::formatArray($value, $width - 4 - strlen($key));
            $sub[0] = $key . $sub[0];
            $sub[count($sub) - 1] .= ',';
            array_push($lines, ...array_map(fn (string $l) => '    ' . $l, $sub));
        }
        $lines[] = ']';
        return $lines;
    }

    /**
     * Parts of PHP code separated by $separator outside of strings and brackets (trimmed), or null if
     * the code can't be tokenized.
     *
     * @return list<string>|null
     */
    private static function split(string $code, string $separator): ?array
    {
        $tokens = @token_get_all('<?php ' . $code);
        array_shift($tokens);
        $parts = [''];
        $depth = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (in_array($text, ['(', '[', '{'], true) || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($depth === 0 && $text === $separator) {
                $parts[] = '';
            } else {
                $parts[count($parts) - 1] .= $text;
            }
        }
        $parts = array_map('trim', $parts);
        if ($depth !== 0) {
            return null;
        }
        return end($parts) === '' ? array_slice($parts, 0, -1) : $parts;
    }

    /** @param list<string> $setup */
    private static function expectsCalls(array $setup): bool
    {
        foreach ($setup as $line) {
            if (str_contains($line, '->expects(')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<list<array>> arguments of each recorded call of $method on the stub $key */
    private static function callsTo(array $calls, string $key, string $method): array
    {
        $args = [];
        foreach ($calls as [$stub, $called, $arguments]) {
            if ($stub === $key && $called === $method) {
                $args[] = $arguments;
            }
        }
        return $args;
    }

    /**
     * `->expects(...)->method(...)->with(...)->willReturn(...)` for a mocked method: never() when the
     * tested call did not call it, once() with its arguments, or exactly(n).
     *
     * @param list<list<array>> $calls
     */
    private function expectation(string $method, Val $val, array $calls, ?Imports $imports): string
    {
        $count = count($calls);
        $times = match ($count) {
            0 => '$this->never()',
            1 => '$this->once()',
            default => "\$this->exactly($count)",
        };
        $line = "->expects($times)->method('$method')";
        if ($count === 0) {
            return "$line;";
        }
        if ($count === 1 && $calls[0] !== []) {
            $line .= '->with(' . implode(', ', array_map(fn (array $a) => $this->argument($a, $imports), $calls[0])) . ')';
        }
        // PHPUnit answers later calls with the latest matching expectation: it must return the value too.
        if (isset($val->throws[$method])) {
            $line .= "->willThrowException({$val->throws[$method]})";
        } elseif (isset($val->returns[$method])) {
            $line .= "->willReturn({$val->returns[$method]})";
        }
        return "$line;";
    }

    private function argument(array $arg, ?Imports $imports): string
    {
        return match ($arg['kind']) {
            'value' => $arg['export'],
            'object' => '$this->isInstanceOf(' . ($imports?->ref($arg['class']) ?? '\\' . $arg['class']) . '::class)',
            default => '$this->anything()',
        };
    }

    /** @param list<Scenario> $cases */
    public function recording(array $class, array $cases, Imports $imports, string $recorderPath): string
    {
        $imports = clone $imports;
        $imports->ref('PHPUnit\\Framework\\TestCase');
        $sut = $imports->ref($class['name']);
        $body = [];
        $cover = array_map(self::phpString(...), [$class['file'], ...$class['ancestorFiles'] ?? []]);
        $body = ['    private const COVER = [' . implode(', ', $cover) . '];', ''];
        foreach ($cases as $case) {
            [$setup, $call] = $this->arrange($case, $sut, recording: true);
            $body = [...$body, ...$this->method("test_{$case->id}", [
                // Measure from construction on, so constructor lines count as covered.
                '\\' . self::RECORDER . "::start('{$case->id}', self::COVER);",
                ...$setup,
                '\\' . self::RECORDER . "::record('{$case->id}', fn () => $call);",
                '$this->addToAssertionCount(1);',
            ])];
        }
        $header = [
            'if (!class_exists(\\' . self::RECORDER . '::class)) {',
            '    require_once ' . self::phpString($recorderPath) . ';',
            '}',
            '',
        ];
        return $this->file('Testor\\Recording', $imports, Imports::short($class['name']) . 'Test', $body,
            $header, $this->isolate($imports));
    }

    /**
     * Final test file source, plus the test method name of each case (same order).
     *
     * @param list<array{Scenario, array}> $cases [scenario, recorded outcome]
     * @return array{string, list<string>}
     */
    public function final(array $class, array $cases, Imports $imports, string $namespace): array
    {
        $imports = clone $imports;
        $imports->ref('PHPUnit\\Framework\\Attributes\\CoversClass');
        $imports->ref('PHPUnit\\Framework\\TestCase');
        $sut = $imports->ref($class['name']);
        $body = [];
        $names = [];

        foreach ($cases as [$case, $outcome]) {
            [$setup, $call] = $this->arrange($case, $sut, $outcome['calls'] ?? null, imports: $imports);
            $returnsVoid = ($case->method['returnType']['name'] ?? null) === 'void';
            [$suffix, $act] = $this->assertion($outcome, $call, $returnsVoid, $imports);
            if ($suffix === 'Succeeds' && self::expectsCalls($setup)) {
                // Mock expectations are the assertions of a void method's test.
                $act = array_values(array_filter($act, fn (string $l) => $l !== '$this->expectNotToPerformAssertions();' && $l !== ''))
                    ?: $act;
            }
            // What differs from the baseline call, e.g. "WhenFindReturnsNull" (at most two parts).
            $base = 'test' . ucfirst($case->method['name']) . $suffix . implode('And', array_slice($case->variations, 0, 2));
            $name = $base;
            for ($n = 2; in_array($name, $names, true); $n++) {
                $name = $base . $n;
            }
            $names[] = $name;
            $body = [...$body, ...$this->method($name, [...$setup, ...($setup ? [''] : []), ...$act])];
        }

        $attributes = [
            '/**',
            ' * Generated by testor: characterization tests pinning the current behaviour.',
            ' * Review them: a test asserting a bug documents that bug.',
            ' */',
            "#[CoversClass($sut::class)]",
            ...$this->isolate($imports),
        ];
        $source = $this->file($namespace, $imports, Imports::short($class['name']) . 'Test', $body, [], $attributes);
        return [$source, $names];
    }

    /** @return array{string, list<string>} test name suffix, act/assert lines */
    private function assertion(array $outcome, string $call, bool $returnsVoid, Imports $imports): array
    {
        switch ($outcome['kind']) {
            case 'throws':
                $exc = $imports->ref($outcome['class']);
                $lines = ["\$this->expectException($exc::class);"];
                if ($outcome['message'] !== '') {
                    $lines[] = '$this->expectExceptionMessage(' . self::phpString($outcome['message']) . ');';
                }
                return ['Throws' . Imports::short($outcome['class']), [...$lines, '', "$call;"]];
            case 'value':
                if ($returnsVoid) {
                    return ['Succeeds', ['$this->expectNotToPerformAssertions();', '', "$call;"]];
                }
                $export = $outcome['export'];
                if (in_array($export, ['true', 'false', 'null'], true)) {
                    return ['Returns' . ucfirst($export), ['$this->assert' . ucfirst($export) . "($call);"]];
                }
                // Short values name the test ("ReturnsDeclined"), others their type ("ReturnsArray").
                $label = str_contains($export, "\n") ? '' : Labels::value(str_replace('\\', '', $export));
                $label = $label !== '' && !str_starts_with($label, 'Array') ? $label : ucfirst(Imports::short($outcome['type']));
                return ['Returns' . $label, self::assertSame($export, $call)];
            case 'nan':
                return ['ReturnsNan', ["\$this->assertNan($call);"]];
            case 'array':
                return ['ReturnsArray', [
                    "\$result = $call;", '', '$this->assertIsArray($result);', "\$this->assertCount({$outcome['count']}, \$result);",
                ]];
            case 'object':
                if ($outcome['class'] !== null) {
                    $cls = $imports->ref($outcome['class']);
                    $state = [];
                    foreach ($outcome['properties'] ?? [] as $property => $export) {
                        $state = [...$state, ...self::assertSame($export, "\$result->$property")];
                    }
                    if (($outcome['datetime'] ?? null) !== null) {
                        $state[] = "\$this->assertSame('{$outcome['datetime']}', \$result->format(DATE_ATOM));";
                    }
                    $name = 'Returns' . Imports::short($outcome['class']);
                    if ($state === []) {
                        return [$name, ["\$this->assertInstanceOf($cls::class, $call);"]];
                    }
                    return [$name, ["\$result = $call;", '', "\$this->assertInstanceOf($cls::class, \$result);", ...$state]];
                }
        }
        return ['ReturnsObject', ["\$this->assertIsObject($call);"]];
    }

    /**
     * `$this->assertSame(<expected>, <actual>);` on as many lines as the (pretty-printed) expected value.
     *
     * @return list<string>
     */
    private static function assertSame(string $export, string $actual): array
    {
        $lines = explode("\n", $export);
        $lines[0] = '$this->assertSame(' . $lines[0];
        $lines[count($lines) - 1] .= ", $actual);";
        return $lines;
    }

    /**
     * Restore superglobals and static properties after each test, so outcomes don't
     * depend on the order tests run in (singletons, $_REQUEST, $_SESSION…).
     */
    private function isolate(Imports $imports): array
    {
        $imports->ref('PHPUnit\\Framework\\Attributes\\BackupGlobals');
        $imports->ref('PHPUnit\\Framework\\Attributes\\BackupStaticProperties');
        return ['#[BackupGlobals(true)]', '#[BackupStaticProperties(true)]'];
    }

    private function method(string $name, array $lines): array
    {
        return [
            "    public function $name(): void",
            '    {',
            ...array_map(fn (string $l) => $l === '' ? '' : self::INDENT . $l, $lines),
            '    }',
            '',
        ];
    }

    private function file(string $namespace, Imports $imports, string $className, array $body,
                          array $header, array $attributes): string
    {
        $out = ['<?php', '', 'declare(strict_types=1);', '', "namespace $namespace;", '',
            ...$header, ...$imports->lines(), '', ...$attributes,
            "final class $className extends TestCase", '{', ...$body];
        if (end($out) === '') {
            array_pop($out);
        }
        return implode("\n", [...$out, '}', '']);
    }
}
