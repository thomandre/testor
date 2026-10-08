<?php

declare(strict_types=1);

namespace Testor;

final class Process
{
    /**
     * Runs a command and returns [exit code, stdout, stderr].
     * Output goes through temp files so large outputs can't dead-lock the pipes.
     *
     * @param list<string> $command
     * @param array<string, string> $env merged into the current environment
     * @return array{int, string, string}
     */
    public static function run(array $command, string $cwd, array $env = [], ?string $stdin = null): array
    {
        $out = tmpfile();
        $err = tmpfile();
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, $cwd, [...getenv(), ...$env]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot run: ' . implode(' ', $command));
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);
        $code = proc_close($proc);

        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/testor-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        return $dir;
    }

    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
