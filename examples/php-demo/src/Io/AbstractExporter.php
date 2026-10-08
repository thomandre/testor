<?php

declare(strict_types=1);

namespace App\Io;

abstract class AbstractExporter
{
    protected function writeFile(string $path, string $content): int
    {
        return (int) file_put_contents($path, $content);
    }
}
