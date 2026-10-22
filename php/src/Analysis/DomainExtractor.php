<?php

declare(strict_types=1);

namespace Testor\Analysis;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Finite-domain inference for method parameters.
 *
 * Two sources:
 *  - PHPDoc / PHPStan / Psalm types ('asc'|'desc', self::TYPE_*, int<0, 100>, positive-int…):
 *    the declared contract, exhaustive when made only of literals/constants.
 *  - Constants the body compares the parameter with ($p === 'x', in_array($p, [...]),
 *    match (…$p…), switch (…$p…), $p < 10…): the values that drive branches. Not exhaustive.
 *
 * Items are either literals ['expr' => PHP code, 'type' => debug type] or unresolved class
 * constant references ['ref' => ['class' => FQCN, 'name' => 'X'|'PREFIX_*', 'keys' => bool]],
 * resolved later by the reflection worker.
 */
final class DomainExtractor
{
    private const EQUALITY = [BinaryOp\Identical::class, BinaryOp\Equal::class, BinaryOp\NotIdentical::class, BinaryOp\NotEqual::class];
    private const RELATIONAL = [BinaryOp\Smaller::class, BinaryOp\SmallerOrEqual::class, BinaryOp\Greater::class,
        BinaryOp\GreaterOrEqual::class, BinaryOp\Spaceship::class];
    /** Nesting of the array shapes inferred from key reads ($p['a']['b'] is depth 2). */
    private const MAX_SHAPE_DEPTH = 2;
    private const MAX_SHAPE_KEYS = 128;
    /** Shapes per value: all keys at their first value, then variants changing one key. */
    private const MAX_SHAPES = 5;
    private const REFINED = [
        'positive-int' => [1, 100], 'negative-int' => [-1, -100], 'non-negative-int' => [0, 1],
        'non-positive-int' => [0, -1], 'non-empty-string' => ['testor'], 'numeric-string' => ['42', '3.14'],
        'non-falsy-string' => ['testor'], 'lowercase-string' => ['testor'],
    ];

    private readonly NodeFinder $finder;
    /** @var array<string, true> methods whose parameter domain is being computed (cycle guard) */
    private array $following = [];

    /** @param Stmt\ClassLike|null $class its methods' parameter domains extend to the values passed to them */
    public function __construct(
        private readonly string $className,
        private readonly ?string $parentName,
        private readonly NameContext $names,
        private readonly ?Stmt\ClassLike $class = null,
    ) {
        $this->finder = new NodeFinder();
    }

    /** @return array{exhaustive: bool, source: string, values: list<array>}|null */
    public function domain(Stmt\ClassMethod $method, string $param): ?array
    {
        $doc = $this->docDomain((string) $method->getDocComment()?->getText(), $param);
        if ($doc !== null && $doc['exhaustive']) {
            return ['exhaustive' => true, 'source' => 'phpdoc', 'values' => $doc['values']];
        }
        $values = [...$doc['values'] ?? [], ...$this->codeDomain($method->stmts ?? [], $param)];
        if ($values === []) {
            return null;
        }
        return ['exhaustive' => false, 'source' => $doc !== null ? 'phpdoc+code' : 'code', 'values' => $values];
    }

    /**
     * Where the values returned by dependency calls go: compared with constants (directly, or through
     * the local variable they are assigned to), or passed as an argument to another method.
     *
     * @return list<array{dep: array{string, string, string}, items?: list<array>, call?: array{string, string, int}}>
     *         dep = [property|variable, name, lowercase method]; call = [class, lowercase method, argument index]
     */
    public function dependencyFlows(Stmt\ClassMethod $method): array
    {
        $stmts = $method->stmts ?? [];
        $flows = [];
        $nodes = $this->finder->find($stmts, fn (Node $n) => $n instanceof BinaryOp || $n instanceof Expr\Assign
            || $n instanceof Expr\StaticCall || $n instanceof Expr\MethodCall);

        foreach ($nodes as $node) {
            if ($node instanceof BinaryOp) {
                $relational = in_array($node::class, self::RELATIONAL, true);
                if (!$relational && !in_array($node::class, self::EQUALITY, true)) {
                    continue;
                }
                foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$side, $other]) {
                    $dep = $this->dependencyCall($side);
                    $items = $dep === null ? [] : $this->literal($other);
                    if ($items !== []) {
                        $flows[] = ['dep' => $dep, 'items' => $relational ? $this->boundaries($items) : $items];
                    }
                }
            } elseif ($node instanceof Expr\Assign) {
                $dep = $this->dependencyCall($node->expr);
                if ($dep !== null && $node->var instanceof Expr\Variable && is_string($node->var->name)) {
                    $items = $this->codeDomain($stmts, $node->var->name);
                    if ($items !== []) {
                        $flows[] = ['dep' => $dep, 'items' => $items];
                    }
                }
            } elseif ($node->name instanceof Node\Identifier && !$node->isFirstClassCallable()) {
                // Calls whose target we can analyse: Class::m(...) and $this->m(...).
                $class = match (true) {
                    $node instanceof Expr\StaticCall && $node->class instanceof Name => $this->resolveClass($node->class),
                    $node instanceof Expr\MethodCall && $node->var instanceof Expr\Variable && $node->var->name === 'this' => $this->className,
                    default => null,
                };
                if ($class === null) {
                    continue;
                }
                foreach ($node->getArgs() as $i => $arg) {
                    $dep = $this->dependencyCall($arg->value);
                    if ($dep !== null && $arg->name === null) {
                        $flows[] = ['dep' => $dep, 'call' => [$class, $node->name->toLowerString(), $i]];
                    }
                }
            }
        }
        return $flows;
    }

    /** [property|variable, name, lowercase method] for `$this->prop->m()` / `$var->m()`, casts unwrapped. */
    private function dependencyCall(Node $node): ?array
    {
        while ($node instanceof Expr\Cast) {
            $node = $node->expr;
        }
        if (!($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall) || !$node->name instanceof Node\Identifier) {
            return null;
        }
        $target = $node->var;
        if (($target instanceof Expr\PropertyFetch || $target instanceof Expr\NullsafePropertyFetch)
            && $target->var instanceof Expr\Variable && $target->var->name === 'this' && $target->name instanceof Node\Identifier) {
            return ['property', $target->name->toString(), $node->name->toLowerString()];
        }
        if ($target instanceof Expr\Variable && is_string($target->name) && $target->name !== 'this') {
            return ['variable', $target->name, $node->name->toLowerString()];
        }
        return null;
    }

    /**
     * Domain of a value (a variable, or a property with propertyDomain()) from how the code uses it:
     * the keys it reads when it is an array, comparisons, in_array/array_key_exists, match/switch arms,
     * length checks, and functions that expect a given format (filter_var, preg_match, json_decode…).
     *
     * @param Node[] $stmts
     * @param string|callable(Node): bool $target variable name, or a matcher
     */
    private function codeDomain(array $stmts, string|callable $target, int $depth = 0): array
    {
        return self::expand($this->rawDomain($stmts, $target, $depth));
    }

    /** codeDomain() with array shapes still as structures (['shape' => …] / ['list' => …]), to be merged. */
    private function rawDomain(array $stmts, string|callable $target, int $depth): array
    {
        $is = is_string($target) ? fn (Node $n) => $n instanceof Expr\Variable && $n->name === $target : $target;
        $items = $depth < self::MAX_SHAPE_DEPTH ? $this->shapes($stmts, $is, $depth) : [];
        $nodes = $this->finder->find($stmts, fn (Node $n) => $n instanceof BinaryOp || $n instanceof Expr\FuncCall
            || $n instanceof Expr\New_ || $n instanceof Expr\Match_ || $n instanceof Stmt\Switch_
            || $n instanceof Expr\MethodCall || $n instanceof Expr\StaticCall
            || ($n instanceof Expr\Variable && $n->name instanceof Expr));

        foreach ($nodes as $node) {
            if ($node instanceof Expr\Variable && $node->name instanceof Expr && $is($node->name)) {
                // $$side: one of the variables the method destructures.
                array_push($items, ...array_map(self::item(...), $this->variableNames($stmts)));
            } elseif ($node instanceof Expr\MethodCall || $node instanceof Expr\StaticCall) {
                array_push($items, ...$this->passedOn($node, $is, $depth));
            } elseif ($node instanceof BinaryOp) {
                $relational = in_array($node::class, self::RELATIONAL, true);
                if (!$relational && !in_array($node::class, self::EQUALITY, true)) {
                    continue;
                }
                foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$side, $other]) {
                    if ($is($side)) {
                        $found = $this->literal($other);
                        array_push($items, ...($relational ? $this->boundaries($found) : $found));
                    } elseif (($measure = $this->measured($side, $is)) !== null) {
                        // strlen($p) > 10, count($p) >= 3: values of the boundary lengths.
                        array_push($items, ...$this->sized($measure, $this->literal($other), $relational));
                    }
                }
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
                $fn = $node->name->toLowerString();
                $args = $node->getArgs();
                if (in_array($fn, ['in_array', 'array_key_exists'], true) && count($args) >= 2 && $is($args[0]->value)) {
                    array_push($items, ...$this->arrayItems($args[1]->value, keys: $fn === 'array_key_exists'));
                } else {
                    array_push($items, ...Usage::forFunction($fn, $args, $is));
                }
            } elseif ($node instanceof Expr\New_ && $node->class instanceof Name && !$node->isFirstClassCallable()) {
                array_push($items, ...Usage::forConstructor($node->class->toLowerString(), $node->getArgs(), $is));
            } elseif ($node instanceof Expr\Match_ && $this->mentions($node->cond, $is)) {
                foreach ($node->arms as $arm) {
                    foreach ($arm->conds ?? [] as $cond) {
                        array_push($items, ...$this->literal($cond));
                    }
                }
            } elseif ($node instanceof Stmt\Switch_ && $this->mentions($node->cond, $is)) {
                foreach ($node->cases as $case) {
                    if ($case->cond !== null) {
                        array_push($items, ...$this->literal($case->cond));
                    }
                }
            }
        }
        return $items;
    }

    /** @return list<string> names of the variables assigned by destructuring ([$top, $right] = …) in $stmts */
    private function variableNames(array $stmts): array
    {
        $names = [];
        foreach ($this->finder->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            if (!$assign->var instanceof Expr\List_ && !$assign->var instanceof Expr\Array_) {
                continue;
            }
            foreach (array_map(fn ($item) => $item?->value, $assign->var->items) as $target) {
                if ($target instanceof Expr\Variable && is_string($target->name)) {
                    $names[$target->name] = true;
                }
            }
        }
        return array_keys($names);
    }

    /**
     * Domain of a value passed to one of the class's own methods ($this->setFormat($settings)): the
     * domain of the parameter it is passed to, from that method's body.
     */
    private function passedOn(Expr\MethodCall|Expr\StaticCall $call, callable $is, int $depth): array
    {
        $onThis = $call instanceof Expr\MethodCall
            ? $call->var instanceof Expr\Variable && $call->var->name === 'this'
            : $call->class instanceof Name && in_array($call->class->toLowerString(), ['self', 'static'], true);
        if (!$onThis || !$call->name instanceof Node\Identifier || $call->isFirstClassCallable()) {
            return [];
        }
        $method = $this->class?->getMethod($call->name->toString());
        $key = $call->name->toLowerString();
        if ($method === null || isset($this->following[$key])) {
            return [];
        }
        $items = [];
        $this->following[$key] = true;
        try {
            foreach ($call->getArgs() as $i => $arg) {
                $param = $method->params[$i] ?? null;
                if ($arg->name === null && !$arg->unpack && $param !== null && !$param->variadic && $is($arg->value)
                    && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                    array_push($items, ...$this->rawDomain($method->stmts ?? [], $param->var->name, $depth));
                }
            }
        } finally {
            unset($this->following[$key]);
        }
        return $items;
    }

    /**
     * Arrays holding the keys the code reads on a value ($p['key'], isset($p['key']), array_key_exists),
     * as ['shape' => [key => values suggested by how the code uses that key]]; and lists of such arrays
     * when the code iterates over the value (foreach ($rows as $row) … $row['key']), as ['list' => …].
     *
     * @param callable(Node): bool $is
     */
    private function shapes(array $stmts, callable $is, int $depth): array
    {
        $keys = [];
        $lists = [];
        $destructured = []; // key => variable it is assigned to
        $nodes = $this->finder->find($stmts, fn (Node $n) => $n instanceof Expr\ArrayDimFetch
            || $n instanceof Expr\FuncCall || $n instanceof Stmt\Foreach_ || $n instanceof Expr\Assign);
        foreach ($nodes as $node) {
            $key = null;
            if ($node instanceof Expr\Assign) {
                // [$top, $right] = $p; list('a' => $a) = $p;
                if (($node->var instanceof Expr\List_ || $node->var instanceof Expr\Array_) && $is($node->expr)) {
                    foreach ($node->var->items as $i => $item) {
                        $k = $item?->key === null ? $i : $item->key;
                        if ($item !== null && ($k = is_int($k) ? $k : (($k instanceof Scalar\String_ || $k instanceof Scalar\Int_) ? $k->value : null)) !== null
                            && $item->value instanceof Expr\Variable && is_string($item->value->name)) {
                            $keys[$k] = true;
                            $destructured[$k] = $item->value->name;
                        }
                    }
                }
                continue;
            }
            if ($node instanceof Expr\ArrayDimFetch && $node->dim !== null && $is($node->var)) {
                $key = $node->dim;
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name && $node->name->toLowerString() === 'array_key_exists'
                && !$node->isFirstClassCallable() && count($node->getArgs()) >= 2 && $is($node->getArgs()[1]->value)) {
                $key = $node->getArgs()[0]->value;
            } elseif ($node instanceof Stmt\Foreach_ && $is($node->expr) && $node->valueVar instanceof Expr\Variable
                && is_string($node->valueVar->name) && !$is($node->valueVar)) {
                $lists[] = $node->valueVar->name;
            }
            if ($key instanceof Scalar\String_ || $key instanceof Scalar\Int_) {
                $keys[$key->value] = true;
            }
        }

        $items = [];
        if ($keys !== []) {
            $entries = [];
            foreach (array_keys($keys) as $key) {
                $isKey = fn (Node $n) => $n instanceof Expr\ArrayDimFetch && ($n->dim instanceof Scalar\String_ || $n->dim instanceof Scalar\Int_)
                    && $n->dim->value === $key && $is($n->var);
                // A destructured key is used through its variable.
                $isUse = isset($destructured[$key])
                    ? fn (Node $n) => $n instanceof Expr\Variable && $n->name === $destructured[$key]
                    : $isKey;
                $values = array_values(array_unique([
                    ...array_column(array_filter($this->codeDomain($stmts, $isUse, $depth + 1), fn (array $i) => isset($i['expr'])), 'expr'),
                    ...$this->typeHints($stmts, $isUse),
                ]));
                // Unknown value with a fallback ($p['x'] ?? $this->x): leaving the key out is type-safe.
                if ($values === [] && $this->finder->findFirst($stmts, fn (Node $n) => $n instanceof BinaryOp\Coalesce && $isKey($n->left)) !== null) {
                    continue;
                }
                $entries[$key] = $values;
            }
            if ($entries !== []) {
                $items[] = ['shape' => $entries];
            }
        }
        foreach (array_unique($lists) as $var) {
            // The elements are at the same nesting level: the list adds no key reads of its own.
            $element = self::merge($this->rawDomain($stmts, $var, $depth));
            if ($element !== []) {
                $items[] = ['list' => $element];
            }
        }
        return $items;
    }

    /**
     * Values of the type a value is used as, when nothing else is known: from its cast ((bool) $p['x'])
     * or its fallback ($p['x'] ?? false: true, a value of the same type that is not the default).
     *
     * @return list<string> PHP expressions
     */
    private function typeHints(array $stmts, callable $is): array
    {
        $hints = [];
        $arithmetic = [BinaryOp\Plus::class, BinaryOp\Minus::class, BinaryOp\Mul::class, BinaryOp\Div::class,
            BinaryOp\Mod::class, BinaryOp\Pow::class];
        $nodes = $this->finder->find($stmts, fn (Node $n) => ($n instanceof Expr\Cast && $is($n->expr))
            || ($n instanceof BinaryOp\Coalesce && $is($n->left))
            || (in_array($n::class, $arithmetic, true) && ($is($n->left) || $is($n->right))));
        foreach ($nodes as $node) {
            $hint = match (true) {
                in_array($node::class, $arithmetic, true) => '1',
                $node instanceof Expr\Cast\Bool_ => 'true',
                $node instanceof Expr\Cast\Int_ => '1',
                $node instanceof Expr\Cast\Double => '1.5',
                $node instanceof Expr\Cast\String_ => "'testor'",
                $node instanceof Expr\Cast\Array_ => "['testor']",
                $node instanceof BinaryOp\Coalesce => $this->otherThan($node->right),
                default => null,
            };
            if ($hint !== null) {
                $hints[] = $hint;
            }
        }
        return $hints;
    }

    /** A literal of the same type as $default but different from it (the constant itself for Class::X). */
    private function otherThan(Node $default): ?string
    {
        return match (true) {
            $default instanceof Expr\ConstFetch && in_array($default->name->toLowerString(), ['true', 'false'], true)
                => $default->name->toLowerString() === 'true' ? 'false' : 'true',
            $default instanceof Scalar\Int_ => (string) ($default->value + 1),
            $default instanceof Scalar\Float_ => var_export($default->value + 1.5, true),
            $default instanceof Scalar\String_ => $default->value === 'testor' ? "'other'" : "'testor'",
            $default instanceof Expr\Array_ => "['testor']",
            $default instanceof Expr\ClassConstFetch && $default->class instanceof Name && $default->name instanceof Node\Identifier
                && ($class = $this->resolveClass($default->class)) !== null => '\\' . $class . '::' . $default->name->toString(),
            $default instanceof Expr\PropertyFetch && $default->var instanceof Expr\Variable && $default->var->name === 'this'
                && $default->name instanceof Node\Identifier => $this->ofPropertyType($default->name->toString()),
            default => null,
        };
    }

    /** A value of the declared scalar type of one of the class's properties (promoted ones included). */
    private function ofPropertyType(string $property): ?string
    {
        $type = null;
        foreach ($this->class?->getProperties() ?? [] as $declaration) {
            foreach ($declaration->props as $prop) {
                if ($prop->name->toString() === $property) {
                    $type = $declaration->type;
                }
            }
        }
        foreach ($this->class?->getMethod('__construct')?->params ?? [] as $param) {
            if ($param->flags !== 0 && $param->var instanceof Expr\Variable && $param->var->name === $property) {
                $type = $param->type;
            }
        }
        if ($type instanceof Node\NullableType) {
            $type = $type->type;
        }
        return $type instanceof Node\Identifier ? match ($type->toLowerString()) {
            'bool' => 'true',
            'int' => '1',
            'float' => '1.5',
            'string' => "'testor'",
            'array' => "['testor']",
            default => null,
        } : null;
    }

    /** All the keys of the shapes among $items, with their values. */
    private static function merge(array $items): array
    {
        $entries = [];
        foreach ($items as $item) {
            foreach ($item['shape'] ?? [] as $key => $values) {
                if (isset($entries[$key]) || count($entries) < self::MAX_SHAPE_KEYS) {
                    $entries[$key] = array_values(array_unique([...$entries[$key] ?? [], ...$values]));
                }
            }
        }
        return $entries;
    }

    /**
     * Domain items from raw ones: a value read through several methods gets one array with all the keys
     * they read (and variants), lists come next, then the other items.
     */
    private static function expand(array $items): array
    {
        $arrays = array_map(fn (string $expr) => ['expr' => $expr, 'type' => 'array'], self::variants(self::merge($items)));
        $others = [];
        foreach ($items as $item) {
            if (isset($item['list'])) {
                foreach (self::variants($item['list']) as $element) {
                    $arrays[] = ['expr' => "[$element]", 'type' => 'array'];
                }
            } elseif (!isset($item['shape'])) {
                $others[] = $item;
            }
        }
        return [...$arrays, ...$others];
    }

    /**
     * Array literals for a shape: every key at its first value (1 or 'testor' when none is known), then
     * variants changing one key to another of its values.
     *
     * @param array<int|string, list<string>> $entries
     * @return list<string>
     */
    private static function variants(array $entries): array
    {
        if ($entries === []) {
            return [];
        }
        // Unknown values: positional entries are usually numbers (coordinates, widths, colours).
        $base = [];
        foreach ($entries as $key => $values) {
            $base[$key] = $values[0] ?? (is_int($key) ? '1' : "'testor'");
        }
        $shapes = [$base];
        foreach ($entries as $key => $values) {
            foreach (array_slice($values, 1) as $value) {
                if (count($shapes) < self::MAX_SHAPES) {
                    $shapes[] = [...$base, $key => $value];
                }
            }
        }
        return array_map(self::arrayExpr(...), $shapes);
    }

    /** @param array<int|string, string> $entries key => PHP expression */
    private static function arrayExpr(array $entries): string
    {
        $parts = [];
        foreach ($entries as $key => $expr) {
            $parts[] = var_export($key, true) . ' => ' . $expr;
        }
        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * Domain of a property from how the class uses it (filter_var($this->email, …)), for the
     * constructor or factory parameter it is built from.
     */
    public function propertyDomain(Stmt\ClassLike $class, string $property): array
    {
        $is = fn (Node $n) => ($n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch)
            && $n->var instanceof Expr\Variable && $n->var->name === 'this'
            && $n->name instanceof Node\Identifier && $n->name->toString() === $property;
        return $this->codeDomain($class->stmts, $is);
    }

    /** 'string' for strlen($p)/mb_strlen($p), 'array' for count($p), else null. */
    private function measured(Node $node, callable $is): ?string
    {
        if (!$node instanceof Expr\FuncCall || !$node->name instanceof Name || $node->isFirstClassCallable()) {
            return null;
        }
        $args = $node->getArgs();
        if ($args === [] || !$is($args[0]->value)) {
            return null;
        }
        return match ($node->name->toLowerString()) {
            'strlen', 'mb_strlen', 'iconv_strlen', 'grapheme_strlen' => 'string',
            'count', 'sizeof' => 'array',
            default => null,
        };
    }

    /** Strings or arrays of the compared length (and n-1, n+1 for relational comparisons). */
    private function sized(string $kind, array $lengths, bool $relational): array
    {
        $items = [];
        foreach ($lengths as $length) {
            if (($length['type'] ?? null) !== 'int') {
                continue;
            }
            $n = (int) $length['expr'];
            foreach ($relational ? [$n - 1, $n, $n + 1] : [$n] as $k) {
                if ($k >= 0 && $k <= 10_000) {
                    $items[] = $kind === 'string' ? Usage::stringOfLength($k) : Usage::arrayOfSize($k);
                }
            }
        }
        return $items;
    }

    private function mentions(Node $node, callable $is): bool
    {
        return $is($node) || $this->finder->findFirst($node, $is) !== null;
    }

    /** Domain items for a literal expression (string, number, true/false/null, Class::CONST). */
    private function literal(Node $node): array
    {
        return match (true) {
            $node instanceof Scalar\String_, $node instanceof Scalar\Int_, $node instanceof Scalar\Float_
                => [self::item($node->value)],
            $node instanceof Expr\UnaryMinus && ($node->expr instanceof Scalar\Int_ || $node->expr instanceof Scalar\Float_)
                => [self::item(-$node->expr->value)],
            $node instanceof Expr\ConstFetch && in_array($node->name->toLowerString(), ['true', 'false', 'null'], true)
                => [self::item(constant($node->name->toLowerString()))],
            $node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Node\Identifier
                => $this->constRef($node->class, $node->name->toString()),
            default => [],
        };
    }

    /** Values (or keys) of an array literal, or of a constant holding an array. */
    private function arrayItems(Node $node, bool $keys): array
    {
        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Node\Identifier) {
            return $this->constRef($node->class, $node->name->toString(), $keys);
        }
        if (!$node instanceof Expr\Array_) {
            return [];
        }
        $items = [];
        foreach ($node->items as $item) {
            $source = $keys ? $item?->key : $item?->value;
            if ($source !== null) {
                array_push($items, ...$this->literal($source));
            }
        }
        return $items;
    }

    private function constRef(Name $class, string $name, bool $keys = false): array
    {
        $fqcn = $this->resolveClass($class);
        return $fqcn === null ? [] : [['ref' => ['class' => $fqcn, 'name' => $name, 'keys' => $keys]]];
    }

    private function resolveClass(Name $name): ?string
    {
        return match ($name->toLowerString()) {
            'self', 'static' => $this->className,
            'parent' => $this->parentName,
            // NameResolver has already turned class names into fully qualified ones.
            default => $name->toString(),
        };
    }

    /** n-1, n, n+1 around integer thresholds. */
    private function boundaries(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (($item['type'] ?? null) === 'int') {
                $n = (int) $item['expr'];
                array_push($out, self::item($n - 1), $item, self::item($n + 1));
            } else {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** Domain from `@param` / `@phpstan-param` / `@psalm-param`, or null if not a finite/refined type. */
    private function docDomain(string $doc, string $param): ?array
    {
        $pattern = '/@(?:phpstan-|psalm-)?param\s+(.+?)\s+(?:\.\.\.)?\$' . preg_quote($param, '/') . '\b/';
        if (!preg_match_all($pattern, $doc, $all)) {
            return null;
        }
        // Prefer the most precise annotation (phpstan/psalm ones come last by convention).
        foreach (array_reverse($all[1]) as $type) {
            $items = [];
            $exhaustive = true;
            foreach (preg_split('/\|(?![^<]*>)/', $type) as $member) {
                $member = trim($member);
                if (preg_match('/^([\'"])(.*)\1$/s', $member, $s)) {
                    $items[] = self::item($s[2]);
                } elseif (is_numeric($member)) {
                    $items[] = self::item(0 + $member);
                } elseif (preg_match('/^([\\\\\w]+)::([A-Z0-9_]+\*?)$/i', $member, $c)) {
                    array_push($items, ...$this->constRef($this->docName($c[1]), $c[2]));
                } elseif (preg_match('/^int<\s*(-?\d+|min)\s*,\s*(-?\d+|max)\s*>$/', $member, $r)) {
                    $exhaustive = false;
                    foreach ([$r[1], $r[2]] as $bound) {
                        if (is_numeric($bound)) {
                            $items[] = self::item((int) $bound);
                        }
                    }
                } elseif (isset(self::REFINED[$member])) {
                    $exhaustive = false;
                    array_push($items, ...array_map(self::item(...), self::REFINED[$member]));
                } elseif ($member === 'null') {
                    $items[] = self::item(null);
                } else {
                    $items = [];
                    break; // not a finite/refined type: ignore this annotation
                }
            }
            if ($items !== []) {
                return ['exhaustive' => $exhaustive, 'values' => $items];
            }
        }
        return null;
    }

    /** Resolves a class name written in a docblock against the file's namespace and imports. */
    private function docName(string $name): Name
    {
        $node = $name[0] === '\\' ? new Name\FullyQualified(ltrim($name, '\\')) : new Name($name);
        if ($node->isSpecialClassName() || $node instanceof Name\FullyQualified) {
            return $node;
        }
        return $this->names->getResolvedClassName($node);
    }

    public static function item(mixed $value): array
    {
        $expr = match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            default => var_export($value, true),
        };
        return ['expr' => $expr, 'type' => get_debug_type($value)];
    }
}
