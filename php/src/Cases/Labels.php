<?php

declare(strict_types=1);

namespace Testor\Cases;

/** Turns PHP value expressions into short CamelCase fragments for test names. */
final class Labels
{
    private const MAX_LENGTH = 24;

    public static function value(string $expr): string
    {
        $label = match (true) {
            in_array($expr, ['null', 'true', 'false'], true) => ucfirst($expr),
            (bool) preg_match('/^-?\d+$/', $expr) => str_replace('-', 'Minus', $expr),
            (bool) preg_match('/^-?\d+\.\d+$/', $expr) => str_replace(['-', '.'], ['Minus', 'Point'], $expr),
            $expr === "''" => 'EmptyString',
            (bool) preg_match("/^'(.*)'$/s", $expr, $m) => self::words($m[1]) ?: 'String',
            $expr === '[]' => 'EmptyArray',
            str_starts_with($expr, '[') => 'Array',
            (bool) preg_match('/^\$this->createStub\((\w+)::class\)$/', $expr, $m) => $m[1],
            // new Order(1, status: OrderStatus::Paid) => OrderWithStatusPaid
            (bool) preg_match('/^new (\w+)\(.*?(\w+): \w+::(\w+)\)$/', $expr, $m) => "{$m[1]}With" . ucfirst($m[2]) . $m[3],
            (bool) preg_match('/^new (\w+)\(/', $expr, $m) => $m[1],
            (bool) preg_match('/^(\w+)::\w+\(/', $expr, $m) => $m[1],   // factory
            (bool) preg_match('/^\w+::(\w+)$/', $expr, $m) => self::words($m[1]), // enum case, constant
            default => '',
        };
        return strlen($label) <= self::MAX_LENGTH ? $label : '';
    }

    /** "  hello, big_world " => "HelloBigWorld" */
    private static function words(string $text): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        return implode('', array_map(fn (string $p) => ucfirst(strtolower($p) === $p || strtoupper($p) === $p ? strtolower($p) : $p), $parts));
    }
}
