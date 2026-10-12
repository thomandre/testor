<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * Turns introspected classes into concrete scenarios (inputs only, no outcome yet).
 *
 * Each method has an input space made of dimensions, each with a list of options (first = baseline):
 *  - "value" dimensions: the method's parameters (the constructor's are fixed at their baseline);
 *  - "return" dimensions: what each dependency method the tested method calls returns (or throws),
 *    one dimension per method so that several of them can be combined.
 * A scenario is a choice of one option per dimension. The first round is the baseline plus one
 * dimension varied at a time; explore() does the same around another scenario (coverage-guided search).
 */
final class CaseBuilder
{
    /** @var array<string, array> method name => input space */
    private array $spaces = [];
    /** @var array<string, true> "method:choice" already generated */
    private array $tried = [];
    private int $nextId = 0;

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

            $space = $this->space($class, $method, $method['static'] ? null : $construction, $params, $imports);
            $this->spaces[$method['name']] = $space;
            $baseline = array_fill(0, count($space['dims']), 0);
            array_push($scenarios, ...$this->around($space, $baseline));
        }
        return [$scenarios, $skipped];
    }

    /**
     * New scenarios varying one dimension at a time around $center (skipping those already tried).
     *
     * @return list<Scenario>
     */
    public function explore(Scenario $center): array
    {
        $space = $this->spaces[$center->method['name']] ?? null;
        return $space === null ? [] : $this->around($space, $center->choice);
    }

    /** @return list<Scenario> */
    private function around(array $space, array $center): array
    {
        $choices = [$center];
        foreach ($space['dims'] as $i => $dim) {
            foreach (array_keys($dim['options']) as $j) {
                if ($j !== $center[$i]) {
                    $choice = $center;
                    $choice[$i] = $j;
                    $choices[] = $choice;
                }
            }
        }
        $scenarios = [];
        foreach ($choices as $choice) {
            $key = $space['method']['name'] . ':' . implode(',', $choice);
            if (isset($this->tried[$key])) {
                continue;
            }
            $this->tried[$key] = true;
            $scenarios[] = $this->scenario($space, $choice);
            if (count($scenarios) >= $this->maxCases) {
                break;
            }
        }
        return $scenarios;
    }

    private function scenario(array $space, array $choice): Scenario
    {
        // Values of each parameter slot, then the stub configuration from the return dimensions.
        $vals = [];
        $variations = [];
        foreach ($space['dims'] as $i => $dim) {
            $option = $dim['options'][$choice[$i]];
            if ($choice[$i] !== 0) {
                $variations[] = $dim['labels'][$choice[$i]];
            }
            $slot = $dim['target'] . ':' . $dim['name'];
            if ($dim['kind'] === 'value') {
                $vals[$slot] = ($dim['spied'] ?? []) === [] ? $option : $option->withSpied($dim['spied']);
            } else {
                $vals[$slot] = $vals[$slot]->configured($dim['method'], $option['returns'], $option['throws']);
            }
        }
        $ctorArgs = [];
        $args = [];
        foreach ($space['slots'] as [$target, $name]) {
            $arg = new Arg($name, $vals["$target:$name"]);
            if ($target === 'ctor') {
                $ctorArgs[] = $arg;
            } else {
                $args[] = $arg;
            }
        }
        return new Scenario(
            'c' . $this->nextId++,
            $space['method'],
            $space['factory'] === false ? null : new Construction($space['factory'], $ctorArgs),
            $args,
            $choice,
            array_values(array_filter($variations)),
        );
    }

    /**
     * The input space of a method: parameter slots (constructor then method) and dimensions.
     *
     * @param array{?string, list<array{string, list<Val>, ?array}>}|null $construction null for static methods
     */
    private function space(array $class, array $method, ?array $construction, array $params, Imports $imports): array
    {
        $slots = [];
        $dims = [];
        foreach ($construction[1] ?? [] as [$name, $vals, $type]) {
            // Methods called on this dependency by the tested method (through the property it is
            // stored in) and by the constructor itself (on the parameter or the property).
            $property = $class['ctorProperties'][$name] ?? null;
            $ctorCalls = $class['constructorCalls'] ?? ['property' => [], 'variable' => []];
            $called = array_values(array_unique([
                ...($property === null ? [] : $method['calls']['property'][$property] ?? []),
                ...($property === null ? [] : $ctorCalls['property'][$property] ?? []),
                ...$ctorCalls['variable'][$name] ?? [],
            ]));
            $domains = $property === null ? [] : $method['returnDomains']['property'][$property] ?? [];
            $slots[] = ['ctor', $name];
            array_push($dims, ...$this->slotDims('ctor', $name, $vals, $type, $called, $domains, $method, $imports));
        }
        foreach ($params as [$name, $vals, $type]) {
            $called = $method['calls']['variable'][$name] ?? [];
            $domains = $method['returnDomains']['variable'][$name] ?? [];
            $slots[] = ['arg', $name];
            array_push($dims, ...$this->slotDims('arg', $name, $vals, $type, $called, $domains, $method, $imports));
        }
        return [
            'method' => $method,
            'factory' => $construction === null ? false : $construction[0],
            'slots' => $slots,
            'dims' => $dims,
        ];
    }

    /**
     * The value dimension of a parameter, plus — for a stub — one return dimension per called method:
     * its candidate return values and, when the tested method catches exceptions, a throwing option.
     *
     * @param list<string> $called lowercase names of the methods called on this dependency
     * @param array<string, array> $domains lowercase method => domain of its return value
     */
    private function slotDims(string $target, string $name, array $vals, ?array $type, array $called,
                              array $domains, array $method, Imports $imports): array
    {
        $dims = [[
            'kind' => 'value', 'target' => $target, 'name' => $name, 'options' => $vals,
            'labels' => array_map(fn (Val $v) => 'With' . self::withoutPrefix(Labels::value($v->expr), ucfirst($name)), $vals),
        ]];
        if (!$this->values->isStubbable($type)) {
            return $dims;
        }
        $stubbed = $this->values->type($type['name']);
        $throwable = $this->throwable($method['catches'] ?? [], $imports);
        // Called void methods are spied on too: their calls are the only trace of what they do.
        $dims[0]['spied'] = array_values(array_filter(array_map(
            fn (string $lower) => in_array($stubbed['methods'][$lower]['returnType']['name'] ?? null, ['void'], true)
                ? $stubbed['methods'][$lower]['name'] : null,
            $called,
        )));
        foreach ($called as $lower) {
            $m = $stubbed['methods'][$lower] ?? null;
            if ($m === null) {
                continue;
            }
            $options = [];
            $labels = [];
            foreach ($this->values->forReturn($m['returnType'], $imports, $domains[$lower] ?? null) as $v) {
                $options[] = ['returns' => $v?->expr, 'throws' => null];
                $labels[] = 'When' . ucfirst($m['name']) . ($v === null ? 'ReturnsAStub' : 'Returns' . Labels::value($v->expr));
            }
            if ($throwable !== null) {
                if ($options === []) {
                    $options[] = ['returns' => null, 'throws' => null]; // void: PHPUnit default
                    $labels[] = '';
                }
                $options[] = ['returns' => null, 'throws' => "new {$throwable}('testor')"];
                $labels[] = 'When' . ucfirst($m['name']) . 'Throws';
            }
            if ($options !== []) {
                $dims[] = ['kind' => 'return', 'target' => $target, 'name' => $name, 'method' => $m['name'],
                    'options' => $options, 'labels' => $labels];
            }
        }
        return $dims;
    }

    /** "Level" + "LevelWarning" => "LevelWarning", not "LevelLevelWarning". */
    private static function withoutPrefix(string $label, string $name): string
    {
        return str_starts_with($label, $name) && $label !== $name ? $label : $name . $label;
    }

    /** Exception class to make dependencies throw, from the types the tested method catches. */
    private function throwable(array $caught, Imports $imports): ?string
    {
        foreach ($caught as $class) {
            // Catching an interface (Throwable…) or a base class: a RuntimeException is caught too.
            $concrete = in_array(ltrim($class, '\\'), ['Throwable', 'Exception', 'RuntimeException'], true) ? 'RuntimeException' : $class;
            return $imports->ref($concrete);
        }
        return null;
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
     * Required parameters only (optional ones keep their default), with all their candidates.
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
            $args[] = [$p['name'], $vals, $p['type']];
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
