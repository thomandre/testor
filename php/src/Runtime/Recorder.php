<?php

declare(strict_types=1);

namespace Testor\Runtime;

/**
 * Captures the observed behaviour of a call during the recording pass, and the lines it
 * executed when a coverage driver (PCOV or Xdebug) is available.
 * Results are appended to the JSON file named by TESTOR_RECORD_FILE:
 * {"cases": {id: outcome + "covered": {file: [lines]}}, "executable": {file: [lines]}}.
 */
final class Recorder
{
    private const MAX_EXPORT_LENGTH = 300;

    /** @var list<string> files whose lines are measured, null when not measuring */
    private static ?array $files = null;

    /** @var list<array{string, string, list<array>}> calls seen by spies: [stub key, method, arguments] */
    private static array $calls = [];

    /**
     * Callback for a stubbed method (->willReturnCallback()): records the call and its arguments,
     * then returns (or throws) what $value() does.
     */
    public static function spy(string $stub, string $method, \Closure $value): \Closure
    {
        return static function (mixed ...$args) use ($stub, $method, $value): mixed {
            self::$calls[] = [$stub, $method, array_map(self::argument(...), array_values($args))];
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
        $notices = [];
        // Test runners may lower error_reporting (PHPUnit 12 does): force E_ALL so that only
        // errors silenced with @ (which masks it down to fatal errors) are ignored.
        $level = error_reporting(E_ALL);
        set_error_handler(static function (int $errno, string $message, string $file, int $line) use (&$notices): bool {
            if (!(error_reporting() & $errno)) {
                return false; // silenced with @
            }
            $notices[] = "$message ($file:$line)";
            return true;
        });
        try {
            $outcome = self::describe($call());
        } catch (\Throwable $e) {
            $outcome = ['kind' => 'throws', 'class' => get_class($e), 'message' => $e->getMessage()];
        } finally {
            restore_error_handler();
            error_reporting($level);
            $lines = self::stop();
        }
        if ($notices !== []) {
            // Warnings/notices/deprecations usually mean the code depends on missing global state.
            $outcome = ['kind' => 'notice', 'notices' => $notices];
        }

        $file = (string) getenv('TESTOR_RECORD_FILE');
        @unlink($file . '.running');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $data['cases'][$id] = $outcome + ['calls' => self::$calls];
        self::$calls = [];
        if ($lines !== null) {
            $data['cases'][$id]['covered'] = $lines['covered'];
            $data['executable'] = array_replace($data['executable'] ?? [], $lines['executable']);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
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
        if (self::isExportable($value)) {
            $export = self::export($value);
            if (strlen($export) <= self::MAX_EXPORT_LENGTH) {
                return ['kind' => 'value', 'export' => $export, 'type' => get_debug_type($value)];
            }
        }
        if (is_array($value)) {
            return ['kind' => 'array', 'count' => count($value)];
        }
        if (is_object($value)) {
            $class = get_class($value);
            return ['kind' => 'object', 'class' => str_contains($class, '@anonymous') ? null : $class];
        }

        return ['kind' => 'other', 'type' => get_debug_type($value)];
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
            default => var_export($value, true),
        };
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
