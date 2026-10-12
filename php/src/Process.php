<?php

declare(strict_types=1);

namespace Testor;

final class Process
{
    /**
     * Runs a command and returns [exit code, stdout, stderr, killed].
     * Output goes through temp files so large outputs can't dead-lock the pipes.
     * The process is killed when $timeout seconds have elapsed or when $watch() returns true
     * (polled every 50 ms).
     *
     * @param list<string> $command
     * @param array<string, string> $env merged into the current environment
     * @param (callable(): bool)|null $watch
     * @return array{int, string, string, bool}
     */
    public static function run(array $command, string $cwd, array $env = [], ?string $stdin = null,
                               ?callable $watch = null, ?float $timeout = null): array
    {
        $out = tmpfile();
        $err = tmpfile();
        $proc = proc_open($command, [0 => ['pipe', 'r'], 1 => $out, 2 => $err], $pipes, $cwd, [...getenv(), ...$env]);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Cannot run: ' . implode(' ', $command));
        }
        fwrite($pipes[0], $stdin ?? '');
        fclose($pipes[0]);

        $deadline = $timeout === null ? null : microtime(true) + $timeout;
        $killed = false;
        while (($status = proc_get_status($proc))['running']) {
            if (($deadline !== null && microtime(true) > $deadline) || ($watch !== null && $watch())) {
                proc_terminate($proc, 9);
                $killed = true;
                break;
            }
            usleep(50_000);
        }
        $code = proc_close($proc);
        // proc_close() returns -1 once proc_get_status() has seen the exit: use the status' code.
        $code = $killed ? -1 : ($status['running'] ? $code : $status['exitcode']);

        rewind($out);
        rewind($err);
        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err), $killed];
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
