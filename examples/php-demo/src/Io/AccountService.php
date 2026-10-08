<?php

declare(strict_types=1);

namespace App\Io;

final class AccountService
{
    use WritesAuditLog;

    public function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }

    public function close(string $username): bool
    {
        $this->audit("closing $username");

        return true;
    }
}
