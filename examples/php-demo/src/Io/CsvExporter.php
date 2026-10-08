<?php

declare(strict_types=1);

namespace App\Io;

final class CsvExporter extends AbstractExporter
{
    /** @param list<list<scalar>> $rows */
    public function toCsv(array $rows, string $delimiter = ';'): string
    {
        return implode("\n", array_map(fn (array $row) => implode($delimiter, $row), $rows));
    }

    public function export(string $path, array $rows): int
    {
        return $this->writeFile($path, $this->toCsv($rows));
    }
}
