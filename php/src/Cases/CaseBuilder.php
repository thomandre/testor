<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * Turns introspected classes into concrete scenarios (inputs only, no outcome yet).
 *
 * Every input is a "dimension" with candidate values: the method's parameters, and the return
 * value of each dependency method the tested method calls (stubs of constructor or method
 * parameters). Scenarios are the baseline plus one dimension varied at a time.
 */
final class CaseBuilder
{
    public function __construct(
        private readonly int $maxCases,
        private readonly bool $allowSideEffects = false,
        private readonly Values $values = new Values(),
    ) {
    }

    /**
     * @return array{list<Scenario>, list<array{string, string}>} [scenarios, skipped [method, reason]]
     */
    public function build(array $class, Imports $imports): array
    {
        $scenarios = [];
        $skipped = [];
        $construction = $this->construction($class, $imports);

        foreach ($class['methods'] as $method) {
            if ($method['sideEffects'] !== [] && !$this->allowSideEffects) {
                $skipped[] = [$method['name'], 'side effects: ' . implode(', ', $method['sideEffects'])];
                continue;
            }
            if (!$method['static'] && is_string($construction)) {
                $skipped[] = [$method['name'], $construction];
                continue;
            }
            $params = $this->methodCandidates($method, $imports);
            if (is_string($params)) {
                $skipped[] = [$method['name'], $params];
                continue;
            }

            // Dimensions: [target ('ctor'|'arg'), name, candidates].
            $dims = [];
            if (!$method['static']) {
                foreach ($construction[1] as [$name, $vals, $type]) {
                    // Methods called on this dependency by the tested method (through the property it
                    // is stored in) and by the constructor itself (on the parameter or the property).
                    $property = $class['ctorProperties'][$name] ?? null;
                    $ctorCalls = $class['constructorCalls'] ?? ['property' => [], 'variable' => []];
                    $called = array_values(array_unique([
                        ...($property === null ? [] : $method['calls']['property'][$property] ?? []),
                        ...($property === null ? [] : $ctorCalls['property'][$property] ?? []),
                        ...$ctorCalls['variable'][$name] ?? [],
                    ]));
                    $domains = $property === null ? [] : $method['returnDomains']['property'][$property] ?? [];
                    $dims[] = ['ctor', $name, $this->withStubReturns($vals, $type, $called, $imports, $domains)];
                }
            }
            foreach ($params as [$name, $vals, $type]) {
                $called = $method['calls']['variable'][$name] ?? [];
                $domains = $method['returnDomains']['variable'][$name] ?? [];
                $dims[] = ['arg', $name, $this->withStubReturns($vals, $type, $called, $imports, $domains)];
            }

            foreach (array_slice($this->combinations($dims), 0, $this->maxCases) as $combo) {
                $ctorArgs = [];
                $args = [];
                foreach ($dims as $i => [$target, $name]) {
                    if ($target === 'ctor') {
                        $ctorArgs[] = new Arg($name, $combo[$i]);
                    } else {
                        $args[] = new Arg($name, $combo[$i]);
                    }
                }
                $scenarios[] = new Scenario(
                    'c' . count($scenarios),
                    $method,
                    $method['static'] ? null : new Construction($construction[0], $ctorArgs),
                    $args,
                );
            }
        }
        return [$scenarios, $skipped];
    }

    /** @return list<list<Val>> baseline, then each alternative of each dimension */
    private function combinations(array $dims): array
    {
        $baseline = array_map(fn (array $d) => $d[2][0], $dims);
        $combos = [$baseline];
        foreach ($dims as $i => [, , $vals]) {
            foreach (array_slice($vals, 1) as $alt) {
                $combo = $baseline;
                $combo[$i] = $alt;
                $combos[] = $combo;
            }
        }
        return $combos;
    }

    /**
     * For a stub, one candidate per return value to explore: the baseline configures every
     * called method with its first value, each variant changes one method's return value.
     * Non-stub candidates are returned unchanged.
     *
     * @param list<Val> $vals
     * @param list<string> $called lowercase names of the methods called on this dependency
     * @param array<string, array> $domains lowercase method => domain of its return value
     * @return list<Val>
     */
    private function withStubReturns(array $vals, ?array $paramType, array $called, Imports $imports, array $domains = []): array
    {
        if (!$this->values->isStubbable($paramType)) {
            return $vals;
        }
        $stub = $vals[0];
        $type = $this->values->type($paramType['name']);
        if ($called === []) {
            return [$stub];
        }

        $options = []; // method name => list<Val|null>
        foreach ($called as $lower) {
            $method = $type['methods'][$lower] ?? null;
            if ($method !== null) {
                $returns = $this->values->forReturn($method['returnType'], $imports, $domains[$lower] ?? null);
                if ($returns !== []) {
                    $options[$method['name']] = $returns;
                }
            }
        }
        $baseline = [];
        foreach ($options as $name => $returns) {
            if ($returns[0] !== null) {
                $baseline[$name] = $returns[0]->expr;
            }
        }
        $variants = [$stub->withReturns($baseline)];
        foreach ($options as $name => $returns) {
            foreach (array_slice($returns, 1) as $alt) {
                $configured = $baseline;
                if ($alt === null) {
                    unset($configured[$name]);
                } else {
                    $configured[$name] = $alt->expr;
                }
                $variants[] = $stub->withReturns($configured);
            }
        }
        return $variants;
    }

    /**
     * How to build the instance under test: constructor (or static factory) and the candidates of
     * each required argument, or the reason it can't be built.
     *
     * @return array{?string, list<array{string, list<Val>, ?array}>}|string [factory, [name, candidates, type]]
     */
    private function construction(array $class, Imports $imports): array|string
    {
        if ($class['constructor'] === null) {
            $reason = 'constructor is not public';
        } else {
            $args = $this->requiredCandidates($class['constructor'], 'constructor', $imports);
            if (!is_string($args)) {
                return [null, $args];
            }
            $reason = $args;
        }
        foreach ($class['methods'] as $method) {
            if ($method['factory'] && $method['sideEffects'] === []) {
                $args = $this->requiredCandidates($method['params'], "{$method['name']}()", $imports);
                if (!is_string($args)) {
                    return [$method['name'], $args];
                }
            }
        }
        return $reason;
    }

    /**
     * Required parameters only (optional ones keep their default); scalars stay at their baseline,
     * stubs keep their single candidate (return values are added per method).
     *
     * @return list<array{string, list<Val>, ?array}>|string
     */
    private function requiredCandidates(array $params, string $where, Imports $imports): array|string
    {
        $args = [];
        foreach ($params as $p) {
            if ($p['optional'] || $p['variadic']) {
                break;
            }
            if ($p['byRef']) {
                return "$where parameter \${$p['name']} is passed by reference";
            }
            $vals = $this->values->forParam($p, $imports);
            if (!$vals) {
                return "no value strategy for $where parameter \${$p['name']}";
            }
            $args[] = [$p['name'], [$vals[0]], $p['type']];
        }
        return $args;
    }

    /** @return list<array{string, list<Val>, ?array}>|string [name, candidates, type] */
    private function methodCandidates(array $method, Imports $imports): array|string
    {
        $params = [];
        foreach ($method['params'] as $p) {
            if ($p['variadic']) {
                break;
            }
            if ($p['byRef']) {
                return "parameter \${$p['name']} is passed by reference";
            }
            $vals = $this->values->forParam($p, $imports);
            if (!$vals) {
                if ($p['optional']) {
                    break; // omit it and the following ones: defaults apply
                }
                return "no value strategy for parameter \${$p['name']}";
            }
            $params[] = [$p['name'], $vals, $p['type']];
        }
        return $params;
    }
}
