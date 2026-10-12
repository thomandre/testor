<?php

declare(strict_types=1);

namespace Testor\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;

/**
 * Realistic values inferred from how the code uses a value: the format a function expects
 * (an e-mail for filter_var(…, FILTER_VALIDATE_EMAIL), a string matching preg_match()'s pattern,
 * JSON for json_decode()…). Items are domain items: ['expr' => PHP code, 'type' => debug type].
 */
final class Usage
{
    private const FILTERS = [
        'FILTER_VALIDATE_EMAIL' => ['user@example.com'],
        'FILTER_VALIDATE_URL' => ['https://example.com/path?query=1'],
        'FILTER_VALIDATE_IP' => ['192.168.1.10', '2001:db8::1'],
        'FILTER_VALIDATE_INT' => ['42'],
        'FILTER_VALIDATE_FLOAT' => ['3.14'],
        'FILTER_VALIDATE_BOOLEAN' => ['yes', 'off'],
        'FILTER_VALIDATE_BOOL' => ['yes', 'off'],
        'FILTER_VALIDATE_DOMAIN' => ['example.com'],
        'FILTER_VALIDATE_MAC' => ['00:1A:2B:3C:4D:5E'],
    ];

    /** Functions whose first argument has a known format. */
    private const FIRST_ARGUMENT = [
        'json_decode' => ['{"key":"value"}', '[1,2,3]'],
        'strtotime' => ['2024-01-15 10:30:00', '+1 day'],
        'date_create' => ['2024-01-15 10:30:00'],
        'date_create_immutable' => ['2024-01-15 10:30:00'],
        'is_numeric' => ['42', '3.14'],
        'ctype_digit' => ['123'],
        'ctype_alpha' => ['abc'],
        'ctype_alnum' => ['abc123'],
        'ctype_upper' => ['ABC'],
        'ctype_lower' => ['abc'],
        'ctype_xdigit' => ['ff00'],
        'ctype_space' => [' '],
        'base64_decode' => ['dGVzdG9y'],
        'hex2bin' => ['746573746f72'],
        'parse_url' => ['https://user@example.com:8080/path?x=1#top'],
        'parse_str' => ['a=1&b=2'],
        'unserialize' => ['a:1:{i:0;s:6:"testor";}'],
        'urldecode' => ['a%20b'],
        'rawurldecode' => ['a%20b'],
    ];

    /** DateTime-like constructors whose first argument is a date string. */
    private const DATE_CLASSES = ['datetime', 'datetimeimmutable'];

    /**
     * @param list<Node\Arg> $args
     * @param callable(Node): bool $is matches the value whose domain is inferred
     */
    public static function forFunction(string $fn, array $args, callable $is): array
    {
        $at = fn (int $i) => isset($args[$i]) && $is($args[$i]->value);
        $string = fn (int $i) => isset($args[$i]) && $args[$i]->value instanceof Scalar\String_ ? $args[$i]->value->value : null;

        if ($fn === 'filter_var' && $at(0) && ($args[1] ?? null)?->value instanceof Expr\ConstFetch) {
            return self::strings(self::FILTERS[$args[1]->value->name->toString()] ?? []);
        }
        if (in_array($fn, ['preg_match', 'preg_match_all'], true) && $at(1) && ($pattern = $string(0)) !== null) {
            $sample = RegexSampler::sample($pattern);
            return $sample === null ? [] : self::strings([$sample]);
        }
        if ($fn === 'explode' && $at(1) && ($separator = $string(0)) !== null && $separator !== '') {
            return self::strings(["a{$separator}b{$separator}c"]);
        }
        if (in_array($fn, ['str_starts_with', 'str_ends_with', 'str_contains', 'strpos', 'stripos', 'mb_strpos', 'strstr'], true)
            && $at(0) && ($needle = $string(1)) !== null && $needle !== '') {
            return self::strings([match ($fn) {
                'str_starts_with' => $needle . 'testor',
                'str_ends_with' => 'testor' . $needle,
                default => 'testor' . $needle . 'testor',
            }]);
        }
        if (isset(self::FIRST_ARGUMENT[$fn]) && $at(0)) {
            return self::strings(self::FIRST_ARGUMENT[$fn]);
        }
        return [];
    }

    /** @param list<Node\Arg> $args */
    public static function forConstructor(string $class, array $args, callable $is): array
    {
        $short = ltrim(substr($class, (int) strrpos('\\' . $class, '\\')), '\\');
        return in_array($short, self::DATE_CLASSES, true) && isset($args[0]) && $is($args[0]->value)
            ? self::strings(['2024-01-15 10:30:00'])
            : [];
    }

    public static function stringOfLength(int $length): array
    {
        return ['expr' => $length <= 40 ? var_export(str_repeat('a', $length), true) : "str_repeat('a', $length)", 'type' => 'string'];
    }

    public static function arrayOfSize(int $size): array
    {
        return ['expr' => $size <= 10 ? '[' . implode(', ', array_fill(0, $size, 1)) . ']' : "array_fill(0, $size, 1)", 'type' => 'array'];
    }

    /** @param list<string> $values */
    private static function strings(array $values): array
    {
        return array_map(fn (string $v) => ['expr' => var_export($v, true), 'type' => 'string'], $values);
    }
}
