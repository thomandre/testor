<?php

declare(strict_types=1);

namespace Testor;

use Testor\Analysis\SourceAnalyzer;

/**
 * Describes the classes of the given files: exact signatures from Reflection (worker process,
 * target autoloader) enriched with static analysis (side effects, parameter domains).
 */
final class Introspector
{
    private const MAX_DOMAIN_VALUES = 8;

    /** @param list<string> $files */
    public function describe(string $project, array $files): array
    {
        // 1. Reflection: signatures, type registry, and where parent classes and traits live.
        $data = $this->worker($project, ['files' => $files]);

        // 2. Static analysis of the classes and their ancestors (to follow inherited calls).
        $ancestors = array_merge([], ...array_column($data['classes'], 'ancestorFiles'));
        $analysis = (new SourceAnalyzer())->analyse([...$files, ...$ancestors]);

        // 3. Classes that receive dependency return values (Compatibility::check($this->db->version())):
        //    analyse them too, their parameter domains become return value domains.
        $targets = [];
        foreach ($analysis as $classAnalysis) {
            foreach ($classAnalysis['methods'] as $method) {
                foreach ($method['flows'] as $flow) {
                    if (isset($flow['call']) && !isset($analysis[$flow['call'][0]])) {
                        $targets[$flow['call'][0]] = true;
                    }
                }
            }
        }
        if ($targets !== []) {
            $located = $this->worker($project, ['locate' => array_keys($targets)])['files'];
            $analysis += (new SourceAnalyzer())->analyse(array_values($located));
        }

        $none = ['sideEffects' => [], 'domains' => [], 'calls' => ['property' => [], 'variable' => []], 'flows' => []];
        foreach ($data['classes'] as &$class) {
            foreach ($class['methods'] as &$method) {
                $info = $analysis[$class['name']]['methods'][strtolower($method['name'])] ?? $none;
                $method['returnDomains'] = $this->returnDomains($info['flows'], $analysis);
            }
        }
        unset($class, $method);

        // 4. Resolve the class constants the domains refer to.
        $refs = [];
        $collect = function (array $items) use (&$refs): void {
            foreach ($items as $item) {
                if (isset($item['ref'])) {
                    $refs[self::refKey($item['ref'])] = $item['ref'];
                }
            }
        };
        foreach ($analysis as $classAnalysis) {
            foreach ($classAnalysis['methods'] as $method) {
                foreach ($method['domains'] as $domain) {
                    $collect($domain['values'] ?? []);
                }
            }
        }
        foreach ($data['classes'] as $class) {
            foreach ($class['methods'] as $method) {
                foreach ($method['returnDomains'] as $byName) {
                    foreach ($byName as $byMethod) {
                        foreach ($byMethod as $items) {
                            $collect($items);
                        }
                    }
                }
            }
        }
        $constants = $refs === [] ? [] : $this->worker($project, ['constants' => array_values($refs)])['constants'];

        foreach ($data['classes'] as &$class) {
            $classAnalysis = $analysis[$class['name']] ?? [];
            $methods = $classAnalysis['methods'] ?? [];
            $class['ctorProperties'] = $classAnalysis['ctorProperties'] ?? [];
            $class['readProperties'] = $classAnalysis['readProperties'] ?? [];
            $class['constructorCalls'] = ($methods['__construct'] ?? $none)['calls'];
            foreach ($class['methods'] as &$method) {
                $info = $methods[strtolower($method['name'])] ?? $none;
                $method['sideEffects'] = $info['sideEffects'];
                $method['calls'] = $info['calls'];
                $method['catches'] = $info['catches'] ?? [];
                foreach ($method['params'] as &$param) {
                    $param['domain'] = $this->resolve($info['domains'][$param['name']] ?? null, $constants);
                }
                foreach ($method['returnDomains'] as $kind => $byName) {
                    foreach ($byName as $name => $byMethod) {
                        foreach ($byMethod as $called => $items) {
                            $method['returnDomains'][$kind][$name][$called] =
                                $this->resolve(['exhaustive' => false, 'source' => 'flow', 'values' => $items], $constants);
                        }
                    }
                }
            }
            if (is_array($class['constructor'])) {
                $info = $methods['__construct'] ?? ['domains' => []];
                foreach ($class['constructor'] as &$param) {
                    $param['domain'] = $this->resolve($info['domains'][$param['name']] ?? null, $constants);
                }
            }
        }
        unset($class, $method, $param);

        return $data;
    }

    /**
     * Domain items of each dependency method's return value, from the method's flows:
     * [property|variable => [name => [lowercase method => list<item>]]].
     */
    private function returnDomains(array $flows, array $analysis): array
    {
        $domains = ['property' => [], 'variable' => []];
        foreach ($flows as $flow) {
            [$kind, $name, $called] = $flow['dep'];
            $items = $flow['items'] ?? [];
            if (isset($flow['call'])) {
                [$class, $target, $index] = $flow['call'];
                $targetMethod = $analysis[$class]['methods'][$target] ?? null;
                $param = $targetMethod['paramNames'][$index] ?? null;
                $items = $param === null ? [] : $targetMethod['domains'][$param]['values'] ?? [];
            }
            if ($items !== []) {
                $domains[$kind][$name][$called] = [...$domains[$kind][$name][$called] ?? [], ...$items];
            }
        }
        return $domains;
    }

    private function worker(string $project, array $request): array
    {
        $request = json_encode($request + ['files' => [], 'constants' => [], 'locate' => []], JSON_THROW_ON_ERROR);
        [$code, $stdout, $stderr] = Process::run(
            [PHP_BINARY, __DIR__ . '/Runtime/worker.php', $project . '/vendor/autoload.php'],
            $project,
            stdin: $request,
        );
        $data = json_decode($stdout, true);
        if ($code !== 0 || !is_array($data)) {
            throw new \RuntimeException("introspection failed:\n$stdout$stderr");
        }
        return $data;
    }

    /** Replaces constant references by their values, dedupes and caps the domain. */
    private function resolve(?array $domain, array $constants): ?array
    {
        if ($domain === null) {
            return null;
        }
        $seen = [];
        $values = [];
        foreach ($domain['values'] as $item) {
            foreach (isset($item['ref']) ? $constants[self::refKey($item['ref'])] ?? [] : [$item] as $value) {
                $key = $value['type'] . ':' . $value['expr'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $values[] = $value;
                }
            }
        }
        if ($values === []) {
            return null;
        }
        return [...$domain, 'values' => array_slice($values, 0, self::MAX_DOMAIN_VALUES)];
    }

    private static function refKey(array $ref): string
    {
        return $ref['class'] . '::' . $ref['name'] . '|' . (int) $ref['keys'];
    }
}
