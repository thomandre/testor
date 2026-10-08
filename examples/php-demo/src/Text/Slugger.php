<?php

declare(strict_types=1);

namespace App\Text;

final class Slugger
{
    public function slugify(string $text, string $separator = '-'): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', $separator, $slug);

        return trim((string) $slug, $separator);
    }

    public function truncate(string $text, int $length, ?string $suffix = '…'): string
    {
        if ($length < 0) {
            throw new \InvalidArgumentException('Length must be positive');
        }
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . ($suffix ?? '');
    }
}
