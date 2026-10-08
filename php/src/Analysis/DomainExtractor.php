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
    private const REFINED = [
        'positive-int' => [1, 100], 'negative-int' => [-1, -100], 'non-negative-int' => [0, 1],
        'non-positive-int' => [0, -1], 'non-empty-string' => ['testor'], 'numeric-string' => ['42', '3.14'],
        'non-falsy-string' => ['testor'], 'lowercase-string' => ['testor'],
    ];

    private readonly NodeFinder $finder;

    public function __construct(
        private readonly string $className,
        private readonly ?string $parentName,
        private readonly NameContext $names,
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

    /** @param Node[] $stmts */
    private function codeDomain(array $stmts, string $param): array
    {
        $items = [];
        $nodes = $this->finder->find($stmts, fn (Node $n) => $n instanceof BinaryOp
            || $n instanceof Expr\FuncCall || $n instanceof Expr\Match_ || $n instanceof Stmt\Switch_);

        foreach ($nodes as $node) {
            if ($node instanceof BinaryOp) {
                $relational = in_array($node::class, self::RELATIONAL, true);
                if (!$relational && !in_array($node::class, self::EQUALITY, true)) {
                    continue;
                }
                $other = match (true) {
                    $this->isParam($node->left, $param) => $node->right,
                    $this->isParam($node->right, $param) => $node->left,
                    default => null,
                };
                if ($other !== null) {
                    $found = $this->literal($other);
                    array_push($items, ...($relational ? $this->boundaries($found) : $found));
                }
            } elseif ($node instanceof Expr\FuncCall && $node->name instanceof Name && !$node->isFirstClassCallable()) {
                $fn = $node->name->toLowerString();
                $args = $node->getArgs();
                if (in_array($fn, ['in_array', 'array_key_exists'], true) && count($args) >= 2
                    && $this->isParam($args[0]->value, $param)) {
                    array_push($items, ...$this->arrayItems($args[1]->value, keys: $fn === 'array_key_exists'));
                }
            } elseif ($node instanceof Expr\Match_ && $this->mentions($node->cond, $param)) {
                foreach ($node->arms as $arm) {
                    foreach ($arm->conds ?? [] as $cond) {
                        array_push($items, ...$this->literal($cond));
                    }
                }
            } elseif ($node instanceof Stmt\Switch_ && $this->mentions($node->cond, $param)) {
                foreach ($node->cases as $case) {
                    if ($case->cond !== null) {
                        array_push($items, ...$this->literal($case->cond));
                    }
                }
            }
        }
        return $items;
    }

    private function isParam(Node $node, string $param): bool
    {
        return $node instanceof Expr\Variable && $node->name === $param;
    }

    private function mentions(Node $node, string $param): bool
    {
        return $this->isParam($node, $param)
            || $this->finder->findFirst($node, fn (Node $n) => $this->isParam($n, $param)) !== null;
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
