<?php

declare(strict_types=1);

namespace Testor\Cases;

/** Turns introspected classes into concrete scenarios (inputs only, no outcome yet). */
final class CaseBuilder
{
    public function __construct(private readonly int $maxCases, private readonly bool $allowSideEffects = false)
    {
    }

    /**
     * Baseline call + one-parameter-at-a-time variations for every public method.
     *
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

            $baseline = array_map(fn (array $p) => $p[1][0], $params);
            $combos = [$baseline];
            foreach ($params as $i => [, $vals]) {
                foreach (array_slice($vals, 1) as $alt) {
                    $combo = $baseline;
                    $combo[$i] = $alt;
                    $combos[] = $combo;
                }
            }

            foreach (array_slice($combos, 0, $this->maxCases) as $combo) {
                $args = [];
                foreach ($params as $i => [$name]) {
                    $args[] = new Arg($name, $combo[$i]);
                }
                $scenarios[] = new Scenario('c' . count($scenarios), $method, $method['static'] ? null : $construction, $args);
            }
        }
        return [$scenarios, $skipped];
    }

    /**
     * How to build the instance under test: the constructor, else a static factory
     * (`public static function from(...): static`), else the reason it can't be built.
     */
    private function construction(array $class, Imports $imports): Construction|string
    {
        if ($class['constructor'] === null) {
            $reason = 'constructor is not public';
        } else {
            $args = $this->baselineArgs($class['constructor'], 'constructor', $imports);
            if (!is_string($args)) {
                return new Construction(null, $args);
            }
            $reason = $args;
        }
        foreach ($class['methods'] as $method) {
            if ($method['factory'] && $method['sideEffects'] === []) {
                $args = $this->baselineArgs($method['params'], "{$method['name']}()", $imports);
                if (!is_string($args)) {
                    return new Construction($method['name'], $args);
                }
            }
        }
        return $reason;
    }

    /** @return list<Arg>|string */
    private function baselineArgs(array $params, string $where, Imports $imports): array|string
    {
        $args = [];
        foreach ($params as $p) {
            if ($p['optional'] || $p['variadic']) {
                break; // let PHP apply the defaults
            }
            if ($p['byRef']) {
                return "$where parameter \${$p['name']} is passed by reference";
            }
            $vals = Values::forParam($p, $imports);
            if (!$vals) {
                return "no value strategy for $where parameter \${$p['name']}";
            }
            $args[] = new Arg($p['name'], $vals[0]);
        }
        return $args;
    }

    /** @return list<array{string, list<Val>}>|string */
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
            $vals = Values::forParam($p, $imports);
            if (!$vals) {
                if ($p['optional']) {
                    break; // omit it and the following ones: defaults apply
                }
                return "no value strategy for parameter \${$p['name']}";
            }
            $params[] = [$p['name'], $vals];
        }
        return $params;
    }
}
