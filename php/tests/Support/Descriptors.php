<?php

declare(strict_types=1);

namespace Testor\Tests\Support;

/** Builders for the class/method/param/type descriptors produced by the introspection. */
trait Descriptors
{
    private static function builtin(string $name, bool $nullable = false): array
    {
        return ['kind' => 'named', 'name' => $name, 'builtin' => true, 'nullable' => $nullable];
    }

    private static function classType(string $name, array $info = [], bool $nullable = false): array
    {
        return [
            'kind' => 'named', 'name' => $name, 'builtin' => false, 'nullable' => $nullable,
            'class' => [
                'exists' => true, 'isInterface' => false, 'isAbstract' => false, 'isFinal' => false,
                'isEnum' => false, 'enumCases' => [], 'requiredCtorParams' => 0, ...$info,
            ],
        ];
    }

    private static function union(array $types, bool $nullable = false): array
    {
        return ['kind' => 'union', 'types' => $types, 'nullable' => $nullable];
    }

    private static function param(string $name, ?array $type, array $extra = []): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => false, 'variadic' => false,
            'byRef' => false, 'domain' => null, ...$extra];
    }

    private static function method(string $name, array $params = [], array $extra = []): array
    {
        return ['name' => $name, 'static' => false, 'params' => $params,
            'returnType' => self::builtin('string'), 'factory' => false, 'sideEffects' => [], ...$extra];
    }

    private static function classDescriptor(string $name, array $methods, ?array $constructor = []): array
    {
        return ['name' => $name, 'file' => '/src/' . $name . '.php', 'kind' => 'class',
            'constructor' => $constructor, 'methods' => $methods, 'ancestorFiles' => []];
    }

    /** @param list<array{expr: string, type: string}|array> $values */
    private static function domain(array $values, bool $exhaustive = false): array
    {
        return ['exhaustive' => $exhaustive, 'source' => 'test', 'values' => $values];
    }

    private static function literal(string $expr, string $type): array
    {
        return ['expr' => $expr, 'type' => $type];
    }
}
