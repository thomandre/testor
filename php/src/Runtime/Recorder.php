<?php

declare(strict_types=1);

namespace Testor\Runtime;

/**
 * Captures the observed behaviour of a call during the recording pass.
 * Results are appended to the JSON file named by TESTOR_RECORD_FILE.
 */
final class Recorder
{
    private const MAX_EXPORT_LENGTH = 300;

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
        }
        if ($notices !== []) {
            // Warnings/notices/deprecations usually mean the code depends on missing global state.
            $outcome = ['kind' => 'notice', 'notices' => $notices];
        }

        $file = (string) getenv('TESTOR_RECORD_FILE');
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $data[$id] = $outcome;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION));
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
