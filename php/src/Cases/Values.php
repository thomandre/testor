<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * Deterministic candidate input values for PHP types.
 *
 * The first candidate of each list is the "baseline" (a typical, valid value);
 * the others are edge cases explored one at a time.
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

    /**
     * DOM nodes: a PHPUnit double of a DOM class has no underlying node ("Invalid State Error" on first
     * use), and `new DOMElement()` gives a read-only node: they are created from a document instead.
     */
    private const DOM_CLASSES = [
        'DOMNode' => "createElement('div')",
        'DOMElement' => "createElement('div')",
        'DOMText' => "createTextNode('testor')",
        'DOMCharacterData' => "createTextNode('testor')",
        'DOMComment' => "createComment('testor')",
        'DOMAttr' => "createAttribute('testor')",
        'DOMDocumentFragment' => 'createDocumentFragment()',
    ];

    /** Which recorded value types each declared builtin type accepts (strict_types on). */
    private const ACCEPTS = [
        'string' => ['string'], 'int' => ['int'], 'float' => ['int', 'float'], 'bool' => ['bool'],
        'true' => ['bool'], 'false' => ['bool'], 'null' => ['null'], 'array' => ['array'], 'iterable' => ['array'],
        'mixed' => ['string', 'int', 'float', 'bool', 'null', 'array'],
    ];

    /** Objects are built from their constructor or factory arguments, recursively up to this depth. */
    private const MAX_BUILD_DEPTH = 4;
    /** Variants of a built object (optional arguments set) beyond its baseline construction. */
    private const MAX_OBJECT_VARIANTS = 4;

    /** @var list<string> names of the properties the code under test reads on objects */
    private array $hints = [];

    /** @param array<string, array> $types class registry from the reflection worker */
    public function __construct(private readonly array $types = [])
    {
    }

    /**
     * Optional constructor arguments named after properties the code under test reads
     * (`$order->status`) get varied when objects are built; the others keep their default.
     *
     * @param list<string> $propertyNames
     */
    public function withHints(array $propertyNames): self
    {
        $clone = clone $this;
        $clone->hints = $propertyNames;
        return $clone;
    }

    public function type(string $class): ?array
    {
        $info = $this->types[$class] ?? null;
        return ($info['exists'] ?? false) ? $info : null;
    }

    /** True when values of this type are PHPUnit stubs (interfaces, abstract and non-final classes). */
    public function isStubbable(?array $type): bool
    {
        if ($type === null || $type['kind'] !== 'named' || $type['builtin'] || isset(self::DATE_CLASSES[$type['name']])
            || isset(self::DOM_CLASSES[$type['name']]) || $type['name'] === 'DOMDocument') {
            return false;
        }
        $info = $this->type($type['name']);
        return $info !== null && !$info['isEnum'] && !$info['isFinal'];
    }

    /**
     * Domain values first, then — unless the PHPDoc domain is exhaustive — one generic
     * "other" value (and null when allowed) to exercise the fallback branch.
     *
     * @return list<Val>|null null when no value strategy exists for the type
     */
    public function forParam(array $param, Imports $imports): ?array
    {
        $type = $param['type'];
        $domain = $param['domain'] ?? null;
        $generic = $this->candidates($type, $imports);
        $fromDomain = $this->fromDomain($type, $domain, $imports);
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

    /**
     * Values a stub may return for a method's return type. A null entry means "leave PHPUnit's
     * default" (an automatic stub, for non-final object types).
     *
     * @return list<Val|null>
     */
    public function forReturn(?array $type, Imports $imports, ?array $domain = null): array
    {
        if ($type === null || ($type['kind'] === 'named' && in_array($type['name'], ['void', 'never', 'self', 'static'], true))) {
            return [];
        }
        if ($this->isStubbable($type)) {
            if ($type['doc'] ?? false) {
                // Typed in PHPDoc only: PHPUnit would return null, so return an object explicitly — a real
                // one when it can be built (no chain of null-returning doubles), else a stub.
                $alias = $imports->ref($type['name']);
                $built = $this->build($this->type($type['name']), $alias, $imports, 1);
                $object = new Val($built[0] ?? "\$this->createStub($alias::class)");
                return $type['nullable'] ? [$object, new Val('null')] : [$object];
            }
            return $type['nullable'] ? [null, new Val('null')] : [];
        }
        // The generic baseline first: domain values are often failure checks (`=== false`) that end the
        // method early, a poor baseline for the variations of the other inputs. Then the values the code
        // compares the result with (or passes on to a method that does).
        // Inline expressions only: a return value can't be hoisted into its own variable.
        $generic = array_values(array_filter($this->candidates($type, $imports, depth: 1) ?? [], fn (Val $v) => !$v->hoist));
        $vals = array_slice($generic, 0, 1);
        foreach ([...$this->fromDomain($type, $domain, $imports), ...array_slice($generic, 1)] as $v) {
            if (!$v->in($vals)) {
                $vals[] = $v;
            }
        }
        return $vals;
    }

    /** @return list<Val> */
    private function fromDomain(?array $type, ?array $domain, Imports $imports): array
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

    /**
     * @param int $depth 0 for arguments of the call under test; deeper values are built inline
     * @return list<Val>|null
     */
    public function candidates(?array $type, Imports $imports, int $depth = 0): ?array
    {
        if ($type === null) {
            return self::literals(self::BUILTIN['mixed']);
        }
        if ($type['kind'] === 'union') {
            $vals = [];
            foreach ($type['types'] as $member) {
                foreach ($this->candidates($member, $imports, $depth) ?? [] as $v) {
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
        $vals = $this->named($type, $imports, $depth);
        if ($vals !== null && $type['nullable'] && !(new Val('null'))->in($vals)) {
            $vals[] = new Val('null');
        }
        return $vals;
    }

    /** @return list<Val>|null */
    private function named(array $type, Imports $imports, int $depth): ?array
    {
        $name = $type['name'];
        $hoist = $depth === 0;
        if ($type['builtin']) {
            if ($name === 'object') {
                return [new Val('new ' . $imports->ref('stdClass') . '()', $hoist)];
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
            return [new Val("new $cls('2024-01-15 10:30:00')", $hoist)];
        }
        if (isset(self::DOM_CLASSES[$name]) || $name === 'DOMDocument') {
            $document = '(new ' . $imports->ref('DOMDocument') . '())';
            return [new Val(isset(self::DOM_CLASSES[$name]) ? "$document->" . self::DOM_CLASSES[$name] : $document, $hoist)];
        }

        $info = $this->type($name);
        if ($info === null) {
            return null;
        }
        $alias = $imports->ref($name);
        if ($info['isEnum']) {
            return array_map(fn (string $case) => new Val("$alias::$case"), $info['enumCases']) ?: null;
        }
        if (!$info['isFinal']) {
            // Nested in another object, a stub is never configured: a real object behaves more like the
            // code expects, when the class can be built without arguments.
            if ($depth > 0 && !$info['isInterface'] && !$info['isAbstract']) {
                $built = $this->build($info, $alias, $imports, $depth);
                if ($built !== []) {
                    return [new Val($built[0])];
                }
            }
            // PHPUnit stubs; return values are configured by the case builder. Then, like EvoSuite, real
            // objects of the class: their state is consistent, where a stub's is empty.
            $stub = new Val("\$this->createStub($alias::class)", $hoist);
            $real = $depth === 0 && !$info['isInterface'] && !$info['isAbstract']
                ? array_slice($this->build($info, $alias, $imports, $depth), 0, 1)
                : [];
            return [$stub, ...array_map(fn (string $expr) => new Val($expr, $hoist), $real)];
        }
        $built = $this->build($info, $alias, $imports, $depth);
        return $built === [] ? null : array_map(fn (string $expr) => new Val($expr, $hoist), $built);
    }

    /**
     * `new X(args)` or `X::factory(args)` with baseline values for the required parameters, then
     * variants setting one hinted optional parameter (named argument) to a non-default value.
     *
     * @return list<string> PHP expressions, baseline first; empty if the class can't be built
     */
    private function build(array $info, string $alias, Imports $imports, int $depth): array
    {
        if ($depth >= self::MAX_BUILD_DEPTH) {
            return [];
        }
        $ways = [];
        if ($info['constructor'] !== null) {
            $ways[] = ["new $alias", $info['constructor']];
        }
        foreach ($info['factories'] as $factory) {
            $ways[] = ["$alias::{$factory['name']}", $factory['params']];
        }
        foreach ($ways as [$callee, $params]) {
            $args = $this->baselineArgs($params, $imports, $depth + 1);
            if ($args === null) {
                continue;
            }
            $built = [$callee . '(' . implode(', ', $args) . ')'];
            foreach ($params as $p) {
                if (!$p['optional'] || $p['variadic'] || !in_array($p['name'], $this->hints, true)) {
                    continue;
                }
                foreach ($this->candidates($p['type'], $imports, $depth + 1) ?? [] as $v) {
                    if (count($built) > self::MAX_OBJECT_VARIANTS) {
                        break 2;
                    }
                    if (!self::isDefault($v, $p['default'] ?? null)) {
                        $built[] = $callee . '(' . implode(', ', [...$args, "{$p['name']}: {$v->expr}"]) . ')';
                    }
                }
            }
            return $built;
        }
        return [];
    }

    private static function isDefault(Val $v, ?array $default): bool
    {
        return match ($default['kind'] ?? null) {
            'null' => $v->expr === 'null',
            'scalar' => $v->expr === $default['expr'],
            'enum' => str_ends_with($v->expr, '::' . $default['case']),
            default => false,
        };
    }

    /** @return list<string>|null */
    private function baselineArgs(array $params, Imports $imports, int $depth): ?array
    {
        $args = [];
        foreach ($params as $p) {
            if ($p['optional'] || $p['variadic']) {
                break;
            }
            if ($p['byRef']) {
                return null;
            }
            $vals = $this->candidates($p['type'], $imports, $depth);
            if (!$vals) {
                return null;
            }
            $args[] = $vals[0]->expr;
        }
        return $args;
    }

    /** @return list<Val> */
    private static function literals(array $exprs): array
    {
        return array_map(fn (string $e) => new Val($e), $exprs);
    }
}
