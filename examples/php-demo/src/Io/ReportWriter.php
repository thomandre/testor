<?php

declare(strict_types=1);

namespace App\Io;

final class ReportWriter
{
    public function format(array $rows): string
    {
        return implode("\n", array_map(fn ($row) => implode(';', (array) $row), $rows));
    }

    public function save(string $path, array $rows): int
    {
        return $this->write($path, $this->format($rows));
    }

    private function write(string $path, string $content): int
    {
        return (int) file_put_contents($path, $content);
    }
}
