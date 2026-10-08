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
        // 1. Reflection: signatures, plus where parent classes and traits live.
        $data = $this->worker($project, $files, []);

        // 2. Static analysis of the classes and their ancestors (to follow inherited calls).
        $ancestors = array_merge([], ...array_column($data['classes'], 'ancestorFiles'));
        $analysis = (new SourceAnalyzer())->analyse([...$files, ...$ancestors]);

        // 3. Resolve the class constants the domains refer to.
        $refs = [];
        foreach ($analysis as $methods) {
            foreach ($methods as $method) {
                foreach ($method['domains'] as $domain) {
                    foreach ($domain['values'] ?? [] as $item) {
                        if (isset($item['ref'])) {
                            $refs[self::refKey($item['ref'])] = $item['ref'];
                        }
                    }
                }
            }
        }
        $constants = $refs === [] ? [] : $this->worker($project, [], array_values($refs))['constants'];

        foreach ($data['classes'] as &$class) {
            $methods = $analysis[$class['name']] ?? [];
            foreach ($class['methods'] as &$method) {
                $info = $methods[strtolower($method['name'])] ?? ['sideEffects' => [], 'domains' => []];
                $method['sideEffects'] = $info['sideEffects'];
                foreach ($method['params'] as &$param) {
                    $param['domain'] = $this->resolve($info['domains'][$param['name']] ?? null, $constants);
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

    private function worker(string $project, array $files, array $constants): array
    {
        $request = json_encode(['files' => $files, 'constants' => $constants], JSON_THROW_ON_ERROR);
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
