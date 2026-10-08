<?php

/**
 * Reflection worker, run in a separate process with the *target project's* autoloader.
 * It must not depend on testor's own vendor packages (they could conflict with the project's).
 *
 * Usage: php worker.php <autoload.php>   (request JSON on stdin)
 * Request:  {"files": [...], "constants": [{"class": FQCN, "name": "X" or "PREFIX_*", "keys": bool}]}
 * Response: {"classes": [...], "errors": [...], "constants": {"<class>::<name>|<keys>": [item, ...]}}
 */

declare(strict_types=1);

require_once $argv[1];

$request = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

function classesInFile(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $namespace = '';
    $classes = [];
    $prev = null;

    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        $token = $tokens[$i];
        if (!is_array($token)) {
            $prev = $token;
            continue;
        }
        [$id] = $token;
        if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($id === T_NAMESPACE) {
            $namespace = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && in_array($t[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $t[1];
                    break;
                }
                if (!is_array($t)) {
                    break;
                }
            }
        }
        $isDecl = in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true);
        $prevId = is_array($prev) ? $prev[0] : $prev;
        if ($isDecl && $prevId !== T_DOUBLE_COLON && $prevId !== T_NEW) {
            for ($j = $i + 1; $j < $n; $j++) {
                $t = $tokens[$j];
                if (is_array($t) && $t[0] === T_STRING) {
                    $classes[] = ltrim($namespace . '\\' . $t[1], '\\');
                    break;
                }
                if (!is_array($t) || $t[0] !== T_WHITESPACE) {
                    break;
                }
            }
        }
        $prev = $token;
    }

    return $classes;
}

function describeClassRef(string $name): array
{
    if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
        return ['exists' => false];
    }
    $ref = new ReflectionClass($name);
    $ctor = $ref->getConstructor();

    return [
        'exists' => true,
        'isInterface' => $ref->isInterface(),
        'isAbstract' => $ref->isAbstract(),
        'isFinal' => $ref->isFinal(),
        'isEnum' => $ref->isEnum(),
        'enumCases' => $ref->isEnum() ? array_map(fn ($c) => $c->name, $name::cases()) : [],
        'requiredCtorParams' => $ctor ? $ctor->getNumberOfRequiredParameters() : 0,
    ];
}

function describeType(?ReflectionType $type): ?array
{
    if ($type === null) {
        return null;
    }
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        $desc = [
            'kind' => 'named',
            'name' => $name,
            'builtin' => $type->isBuiltin(),
            'nullable' => $type->allowsNull() && $name !== 'null' && $name !== 'mixed',
        ];
        if (!$type->isBuiltin() && !in_array($name, ['self', 'static', 'parent'], true)) {
            $desc['class'] = describeClassRef($name);
        }
        return $desc;
    }
    if ($type instanceof ReflectionUnionType) {
        $members = array_values(array_filter(
            array_map('describeType', $type->getTypes()),
            fn ($t) => !($t['kind'] === 'named' && $t['name'] === 'null'),
        ));
        return ['kind' => 'union', 'types' => $members, 'nullable' => $type->allowsNull()];
    }

    return ['kind' => 'intersection'];
}

function describeParams(ReflectionFunctionAbstract $fn): array
{
    return array_map(fn (ReflectionParameter $p) => [
        'name' => $p->getName(),
        'type' => describeType($p->getType()),
        'optional' => $p->isOptional(),
        'variadic' => $p->isVariadic(),
        'byRef' => $p->isPassedByReference(),
    ], $fn->getParameters());
}

/** Files declaring the parent classes and traits (recursively) of a class, for static analysis. */
function ancestorFiles(ReflectionClass $ref): array
{
    $files = [];
    $queue = [$ref];
    while ($queue) {
        $current = array_shift($queue);
        foreach ([$current->getParentClass() ?: null, ...array_values($current->getTraits())] as $related) {
            if ($related !== null && $related->getFileName() !== false && !in_array($related->getFileName(), $files, true)) {
                $files[] = $related->getFileName();
                $queue[] = $related;
            }
        }
    }
    return $files;
}

function returnsSelf(ReflectionMethod $m, ReflectionClass $ref): bool
{
    $type = $m->getReturnType();
    return $type instanceof ReflectionNamedType && !$type->allowsNull()
        && in_array($type->getName(), ['self', 'static', $ref->getName()], true);
}

function valueItems(mixed $value): array
{
    if (is_array($value)) {
        return array_merge([], ...array_map('valueItems', array_values($value)));
    }
    if ($value !== null && !is_scalar($value)) {
        return [];
    }
    $expr = match (true) {
        $value === null => 'null',
        is_bool($value) => $value ? 'true' : 'false',
        default => var_export($value, true),
    };
    return [['expr' => $expr, 'type' => get_debug_type($value)]];
}

/** Values of class constant(s) matching `name` (may end with `*`); array keys when `keys` is set. */
function resolveConstant(array $ref): array
{
    if (!class_exists($ref['class']) && !interface_exists($ref['class']) && !enum_exists($ref['class'])) {
        return [];
    }
    $items = [];
    foreach ((new ReflectionClass($ref['class']))->getReflectionConstants() as $c) {
        $name = $c->getName();
        $pattern = $ref['name'];
        $matches = str_ends_with($pattern, '*') ? str_starts_with($name, rtrim($pattern, '*')) : $name === $pattern;
        if (!$matches) {
            continue;
        }
        $value = $c->getValue();
        if ($value instanceof UnitEnum) {
            continue; // enum parameters already get every case
        }
        if ($ref['keys']) {
            array_push($items, ...(is_array($value) ? valueItems(array_keys($value)) : []));
            continue;
        }
        foreach (valueItems($value) as $item) {
            // Private constants can't be referenced from a test: use the literal value.
            $items[] = $c->isPublic() && !is_array($value) ? $item + ['class' => $c->getDeclaringClass()->getName(), 'const' => $name] : $item;
        }
    }
    return $items;
}

$result = ['classes' => [], 'errors' => [], 'constants' => []];

foreach ($request['files'] as $file) {
    $file = realpath($file) ?: $file;
    foreach (classesInFile($file) as $className) {
        try {
            if (!class_exists($className) && !interface_exists($className)
                && !trait_exists($className) && !enum_exists($className)) {
                require_once $file;
            }
            $ref = new ReflectionClass($className);
            $ctor = $ref->getConstructor();
            $methods = [];
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                if ($m->getDeclaringClass()->getName() !== $ref->getName()
                    || $m->isAbstract() || str_starts_with($m->getName(), '__')) {
                    continue;
                }
                $methods[] = [
                    'name' => $m->getName(),
                    'static' => $m->isStatic(),
                    'params' => describeParams($m),
                    'returnType' => describeType($m->getReturnType()),
                    'factory' => $m->isStatic() && returnsSelf($m, $ref),
                ];
            }
            $result['classes'][] = [
                'name' => $ref->getName(),
                'file' => $file,
                'kind' => match (true) {
                    $ref->isInterface() => 'interface',
                    $ref->isTrait() => 'trait',
                    $ref->isEnum() => 'enum',
                    $ref->isAbstract() => 'abstract',
                    default => 'class',
                },
                'constructor' => $ctor && $ctor->isPublic() ? describeParams($ctor) : ($ctor ? null : []),
                'methods' => $methods,
                'ancestorFiles' => ancestorFiles($ref),
                'startLine' => $ref->getStartLine(),
                'endLine' => $ref->getEndLine(),
            ];
        } catch (Throwable $e) {
            $result['errors'][] = ['class' => $className, 'file' => $file, 'error' => $e->getMessage()];
        }
    }
}

foreach ($request['constants'] as $ref) {
    try {
        $result['constants'][$ref['class'] . '::' . $ref['name'] . '|' . (int) $ref['keys']] = resolveConstant($ref);
    } catch (Throwable) {
        // unresolvable constant: no values
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
