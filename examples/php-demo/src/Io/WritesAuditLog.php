<?php

declare(strict_types=1);

namespace App\Io;

trait WritesAuditLog
{
    private function audit(string $message): void
    {
        error_log('[audit] ' . $message);
    }
}
