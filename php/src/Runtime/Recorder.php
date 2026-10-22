<?php

declare(strict_types=1);

namespace Testor\Runtime;

/**
 * Captures the observed behaviour of a call during the recording pass, and the lines it
 * executed when a coverage driver (PCOV or Xdebug) is available.
 * Results are appended, one JSON line per case, to the file named by TESTOR_RECORD_FILE; read() turns
 * them into {"cases": {id: outcome + "covered": {file: [lines]}}, "executable": {file: [lines]}}.
 */
final class Recorder
{
    private const MAX_EXPORT_LENGTH = 300;
    /** Values up to this size (single-line export) are asserted in full, pretty-printed. */
    private const MAX_ASSERTED_LENGTH = 20_000;
    /** Arrays longer than this on one line are printed one item per line. */
    private const LINE_WIDTH = 100;
    private const MAX_ASSERTED_PROPERTIES = 10;

    /** @var list<string> files whose lines are measured, null when not measuring */
    private static ?array $files = null;

    /** @var list<array{string, string, list<array>}> calls made by the tested call: [stub key, method, arguments] */
    private static array $calls = [];

    /** False while the object under test is being built: calls made then are not asserted. */
    private static bool $calling = false;

    /** @var list<string> PHP warnings, notices and deprecations since start() */
    private static array $notices = [];
    /** error_reporting() level to restore, null when not watching */
    private static ?int $level = null;

    /**
     * Collects PHP warnings/notices/deprecations. Test runners may lower error_reporting (PHPUnit 12
     * does): E_ALL is forced so that only errors silenced with @ (masked down to fatal errors) are ignored.
     */
    private static function watch(): void
    {
        if (self::$level !== null) {
            return;
        }
        self::$notices = [];
        self::$level = error_reporting(E_ALL);
        set_error_handler(static function (int $errno, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $errno)) {
                return false; // silenced with @
            }
            self::$notices[] = "$message ($file:$line)";
            return true;
        });
    }

    private static function unwatch(): void
    {
        if (self::$level === null) {
            return;
        }
        restore_error_handler();
        error_reporting(self::$level);
        self::$level = null;
    }

    /**
     * Callback for a stubbed method (->willReturnCallback()): records the call and its arguments,
     * then returns (or throws) what $value() does.
     */
    public static function spy(string $stub, string $method, \Closure $value): \Closure
    {
        return static function (mixed ...$args) use ($stub, $method, $value): mixed {
            if (self::$calling) {
                self::$calls[] = [$stub, $method, array_map(self::argument(...), array_values($args))];
            }
            return $value();
        };
    }

    /** An argument as a value to assert (PHP code) or, for objects, its class. */
    private static function argument(mixed $value): array
    {
        if (self::isExportable($value)) {
            $export = self::export($value);
            if (strlen($export) <= self::MAX_EXPORT_LENGTH * 4) {
                return ['kind' => 'value', 'export' => $export];
            }
        }
        if (is_object($value) && !str_contains(get_class($value), '@anonymous')) {
            $class = self::doubledType($value) ?? get_class($value);
            return ['kind' => 'object', 'class' => $class];
        }
        return ['kind' => 'any'];
    }

    /**
     * For a PHPUnit test double, whose class name is random (MockObject_…, TestStub_…), the class or
     * interface it doubles. Doubles are recognised by the PHPUnit interfaces they implement.
     */
    private static function doubledType(object $value): ?string
    {
        $interfaces = class_implements($value);
        $isDouble = array_filter($interfaces, fn (string $i) => str_starts_with($i, 'PHPUnit\\Framework\\MockObject\\')) !== [];
        if (!$isDouble) {
            return null;
        }
        $parent = get_parent_class($value);
        if ($parent !== false) {
            return $parent;
        }
        foreach ($interfaces as $interface) {
            if (!str_starts_with($interface, 'PHPUnit\\')) {
                return $interface;
            }
        }
        return null;
    }

    /**
     * Marks case $id as running (a watchdog kills runs stuck on the same case) and starts measuring
     * coverage of the given files. Call before building the object under test.
     */
    public static function start(string $id, array $files): void
    {
        file_put_contents(getenv('TESTOR_RECORD_FILE') . '.running', $id);
        self::$files = null;
        self::$calls = [];
        self::$calling = false;
        // Warnings while building the object count too: PHPUnit reports them for the final test.
        self::unwatch();
        self::watch();
        if (self::driver() === 'pcov') {
            \pcov\clear();
            \pcov\start();
            self::$files = $files;
        } elseif (self::driver() === 'xdebug') {
            xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
            self::$files = $files;
        }
    }

    public static function driver(): ?string
    {
        if (extension_loaded('pcov') && ini_get('pcov.enabled')) {
            return 'pcov';
        }
        if (function_exists('xdebug_start_code_coverage') && in_array('coverage', explode(',', (string) ini_get('xdebug.mode')), true)) {
            return 'xdebug';
        }
        return null;
    }

    public static function record(string $id, callable $call): void
    {
        self::watch(); // already watching since start(), unless called without it
        self::$calling = true;
        try {
            $outcome = self::describe($call());
        } catch (\Throwable $e) {
            $outcome = ['kind' => 'throws', 'class' => get_class($e), 'message' => $e->getMessage()];
        } finally {
            self::unwatch();
            self::$calling = false;
            $lines = self::stop();
        }
        $notices = self::$notices;
        if ($notices !== []) {
            // Warnings/notices/deprecations usually mean the code depends on missing global state.
            $outcome = ['kind' => 'notice', 'notices' => $notices];
        }

        $file = (string) getenv('TESTOR_RECORD_FILE');
        @unlink($file . '.running');
        $record = ['id' => $id, 'outcome' => $outcome + ['calls' => self::$calls]];
        self::$calls = [];
        if ($lines !== null) {
            $record['outcome']['covered'] = $lines['covered'];
            $record['executable'] = $lines['executable'];
        }
        // One JSON line per case, appended: rewriting a growing file for every case is quadratic.
        file_put_contents($file, json_encode($record, JSON_PRESERVE_ZERO_FRACTION) . "\n", FILE_APPEND);
    }

    /**
     * Reads a record file written by record(): {cases: id => outcome, executable: file => lines}.
     *
     * @return array{cases?: array<string, array>, executable?: array<string, list<int>>}
     */
    public static function read(string $file): array
    {
        $data = [];
        foreach (is_file($file) ? file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue; // a line cut short by a killed run
            }
            $data['cases'][$record['id']] = $record['outcome'];
            if (isset($record['executable'])) {
                $data['executable'] = array_replace($data['executable'] ?? [], $record['executable']);
            }
        }
        return $data;
    }

    /** @return array{covered: array<string, list<int>>, executable: array<string, list<int>>}|null */
    private static function stop(): ?array
    {
        if (self::$files === null) {
            return null;
        }
        if (self::driver() === 'pcov') {
            \pcov\stop();
            $raw = \pcov\collect(\pcov\inclusive, self::$files);
        } else {
            $raw = array_intersect_key(xdebug_get_code_coverage(), array_flip(self::$files));
            xdebug_stop_code_coverage();
        }
        self::$files = null;

        // Both drivers: 1 = executed, -1 = executable but not executed (Xdebug: -2 = dead code).
        $covered = [];
        $executable = [];
        foreach ($raw as $file => $lines) {
            $lines = array_filter($lines, fn (int $hit) => $hit !== -2);
            ksort($lines);
            $executable[$file] = array_keys($lines);
            $covered[$file] = array_keys(array_filter($lines, fn (int $hit) => $hit > 0));
        }
        return ['covered' => $covered, 'executable' => $executable];
    }

    private static function describe(mixed $value): array
    {
        if (is_float($value) && is_nan($value)) {
            return ['kind' => 'nan'];
        }
        if (self::isExportable($value) && strlen(self::export($value)) <= self::MAX_ASSERTED_LENGTH) {
            return ['kind' => 'value', 'export' => self::pretty($value), 'type' => get_debug_type($value)];
        }
        if (is_array($value)) {
            return ['kind' => 'array', 'count' => count($value)];
        }
        if (is_object($value)) {
            $class = self::doubledType($value) ?? get_class($value);
            return [
                'kind' => 'object',
                'class' => str_contains($class, '@anonymous') ? null : $class,
                'properties' => self::properties($value),
                'datetime' => $value instanceof \DateTimeInterface ? $value->format(\DateTimeInterface::ATOM) : null,
            ];
        }

        return ['kind' => 'other', 'type' => get_debug_type($value)];
    }

    /** Public, initialized properties whose values can be asserted: name => PHP code. */
    private static function properties(object $value): array
    {
        if (self::doubledType($value) !== null) {
            return [];
        }
        $properties = [];
        foreach (get_object_vars($value) as $name => $property) {
            if (self::isExportable($property) && strlen(self::export($property)) <= self::MAX_EXPORT_LENGTH) {
                $properties[$name] = self::pretty($property);
                if (count($properties) === self::MAX_ASSERTED_PROPERTIES) {
                    break;
                }
            }
        }
        return $properties;
    }

    /** Export on one line, or one array item per line (4-space indents) when too wide. */
    private static function pretty(mixed $value, int $indent = 0): string
    {
        $flat = self::export($value);
        if (!is_array($value) || $value === [] || strlen($flat) + $indent <= self::LINE_WIDTH) {
            return $flat;
        }
        $isList = array_is_list($value);
        $pad = str_repeat(' ', $indent + 4);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $pad . ($isList ? '' : var_export($key, true) . ' => ') . self::pretty($item, $indent + 4) . ',';
        }
        return "[\n" . implode("\n", $lines) . "\n" . str_repeat(' ', $indent) . ']';
    }

    private static function isExportable(mixed $value): bool
    {
        if ($value === null || is_scalar($value) || $value instanceof \UnitEnum) {
            return !(is_float($value) && is_nan($value));
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isExportable($item)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    private static function export(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) && is_infinite($value) => $value > 0 ? 'INF' : '-INF',
            $value instanceof \UnitEnum => '\\' . get_class($value) . '::' . $value->name,
            is_array($value) => self::exportArray($value),
            is_string($value) && preg_match('/[\x00-\x1F\x7F]/', $value) === 1 => self::doubleQuoted($value),
            default => var_export($value, true),
        };
    }

    /** "line\n" rather than a literal spanning lines: exports always fit on one line. */
    private static function doubleQuoted(string $value): string
    {
        $escaped = addcslashes($value, "\\\"\$\n\r\t\v\f\e");
        $escaped = preg_replace_callback('/[\x00-\x1F\x7F]/', fn (array $m) => sprintf('\\x%02X', ord($m[0])), $escaped);
        return '"' . $escaped . '"';
    }

    private static function exportArray(array $value): string
    {
        $isList = array_is_list($value);
        $parts = [];
        foreach ($value as $key => $item) {
            $parts[] = ($isList ? '' : var_export($key, true) . ' => ') . self::export($item);
        }

        return '[' . implode(', ', $parts) . ']';
    }
}
