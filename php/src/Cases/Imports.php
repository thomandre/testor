<?php

declare(strict_types=1);

namespace Testor\Cases;

/** Collects `use` statements and hands out short class names. Clone it to fork. */
final class Imports
{
    /** @var array<string, string> FQCN => alias */
    private array $aliases = [];

    public function ref(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');
        if (isset($this->aliases[$fqcn])) {
            return $this->aliases[$fqcn];
        }
        $short = self::short($fqcn);
        $alias = $short;
        for ($n = 2; in_array($alias, $this->aliases, true); $n++) {
            $alias = $short . $n;
        }
        return $this->aliases[$fqcn] = $alias;
    }

    /** @return list<string> */
    public function lines(): array
    {
        $aliases = $this->aliases;
        ksort($aliases, SORT_STRING);
        $lines = [];
        foreach ($aliases as $fqcn => $alias) {
            $lines[] = $alias === self::short($fqcn) ? "use $fqcn;" : "use $fqcn as $alias;";
        }
        return $lines;
    }

    public static function short(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
