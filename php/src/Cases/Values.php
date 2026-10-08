<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * Deterministic candidate input values for PHP parameter types.
 *
 * The first candidate of each list is the "baseline" (a typical, valid value);
 * the others are edge cases explored one parameter at a time.
 */
final class Values
{
    private const BUILTIN = [
        'int' => ['1', '0', '-1', '100'],
        'float' => ['1.5', '0.0', '-2.5'],
        'string' => ["'testor'", "''", "'  Hello, World!  '"],
        'bool' => ['true', 'false'],
        'true' => ['true'],
        'false' => ['false'],
        'array' => ['[1, 2, 3]', '[]', "['key' => 'value']"],
        'iterable' => ['[1, 2, 3]', '[]'],
        'mixed' => ["'testor'", 'null', '1', '[]'],
        'null' => ['null'],
        'callable' => ['static fn (mixed ...$args) => null'],
    ];

    private const DATE_CLASSES = [
        'DateTimeInterface' => 'DateTimeImmutable',
        'DateTimeImmutable' => 'DateTimeImmutable',
        'DateTime' => 'DateTime',
    ];

    /** Which recorded value types each declared builtin type accepts (strict_types on). */
    private const ACCEPTS = [
        'string' => ['string'], 'int' => ['int'], 'float' => ['int', 'float'], 'bool' => ['bool'],
        'true' => ['bool'], 'false' => ['bool'], 'null' => ['null'],
        'mixed' => ['string', 'int', 'float', 'bool', 'null'],
    ];

    /**
     * Domain values first, then — unless the PHPDoc domain is exhaustive — one generic
     * "other" value (and null when allowed) to exercise the fallback branch.
     *
     * @return list<Val>|null null when no value strategy exists for the type
     */
    public static function forParam(array $param, Imports $imports): ?array
    {
        $type = $param['type'];
        $domain = $param['domain'] ?? null;
        $generic = self::candidates($type, $imports);
        $fromDomain = self::fromDomain($type, $domain, $imports);
        if ($fromDomain === []) {
            return $generic;
        }

        $nullAllowed = $type === null || ($type['nullable'] ?? false);
        $null = new Val('null');
        if ($domain['exhaustive']) {
            $extra = $type !== null && ($type['nullable'] ?? false) ? [$null] : [];
        } else {
            $extra = array_filter(
                [...array_slice($generic ?? [], 0, 1), $null],
                fn (Val $v) => !$v->in($fromDomain) && ($v->expr !== 'null' || $nullAllowed),
            );
        }
        foreach ($extra as $v) {
            if (!$v->in($fromDomain)) {
                $fromDomain[] = $v;
            }
        }
        return $fromDomain;
    }

    /** @return list<Val> */
    private static function fromDomain(?array $type, ?array $domain, Imports $imports): array
    {
        $vals = [];
        foreach ($domain['values'] ?? [] as $item) {
            if (!self::accepts($type, $item['type'])) {
                continue;
            }
            $v = new Val(isset($item['class']) ? $imports->ref($item['class']) . '::' . $item['const'] : $item['expr']);
            if (!$v->in($vals)) {
                $vals[] = $v;
            }
        }
        return $vals;
    }

    private static function accepts(?array $type, string $valueType): bool
    {
        if ($type === null) {
            return true;
        }
        if ($valueType === 'null' && ($type['nullable'] ?? false)) {
            return true;
        }
        if ($type['kind'] === 'union') {
            foreach ($type['types'] as $member) {
                if (self::accepts($member, $valueType)) {
                    return true;
                }
            }
            return false;
        }
        return $type['kind'] === 'named' && $type['builtin']
            && in_array($valueType, self::ACCEPTS[$type['name']] ?? [], true);
    }

    /** @return list<Val>|null */
    public static function candidates(?array $type, Imports $imports): ?array
    {
        if ($type === null) {
            return self::literals(self::BUILTIN['mixed']);
        }
        if ($type['kind'] === 'union') {
            $vals = [];
            foreach ($type['types'] as $member) {
                foreach (self::candidates($member, $imports) ?? [] as $v) {
                    if (!$v->in($vals)) {
                        $vals[] = $v;
                    }
                }
            }
            if ($type['nullable']) {
                $vals[] = new Val('null');
            }
            return $vals ?: null;
        }
        if ($type['kind'] !== 'named') {
            return null; // intersection types: not supported yet
        }
        $vals = self::named($type, $imports);
        if ($vals !== null && $type['nullable'] && !(new Val('null'))->in($vals)) {
            $vals[] = new Val('null');
        }
        return $vals;
    }

    /** @return list<Val>|null */
    private static function named(array $type, Imports $imports): ?array
    {
        $name = $type['name'];
        if ($type['builtin']) {
            if ($name === 'object') {
                return [new Val('new ' . $imports->ref('stdClass') . '()', hoist: true)];
            }
            return isset(self::BUILTIN[$name]) ? self::literals(self::BUILTIN[$name]) : null;
        }
        if (in_array($name, ['self', 'static', 'parent'], true)) {
            return null;
        }
        if ($name === 'Closure') {
            return [new Val(self::BUILTIN['callable'][0])];
        }
        if (isset(self::DATE_CLASSES[$name])) {
            $cls = $imports->ref(self::DATE_CLASSES[$name]);
            return [new Val("new $cls('2024-01-15 10:30:00')", hoist: true)];
        }

        $info = $type['class'] ?? [];
        if (!($info['exists'] ?? false)) {
            return null;
        }
        $alias = $imports->ref($name);
        if ($info['isEnum']) {
            return array_map(fn (string $case) => new Val("$alias::$case"), $info['enumCases']) ?: null;
        }
        if (!$info['isFinal']) {
            // PHPUnit stubs return type-appropriate defaults for every method.
            return [new Val("\$this->createStub($alias::class)", hoist: true)];
        }
        if (!$info['isAbstract'] && $info['requiredCtorParams'] === 0) {
            return [new Val("new $alias()", hoist: true)];
        }
        return null;
    }

    /** @return list<Val> */
    private static function literals(array $exprs): array
    {
        return array_map(fn (string $e) => new Val($e), $exprs);
    }
}
