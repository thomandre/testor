<?php

declare(strict_types=1);

namespace App\Report;

final class Formatter
{
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    private const SUCCESS_CODES = [200, 201, 204];

    public function sortLabel(string $order): string
    {
        return match (strtolower($order) === $order ? $order : '') {
            'asc' => 'Ascending',
            'desc' => 'Descending',
            default => throw new \InvalidArgumentException("Unknown order: $order"),
        };
    }

    public function orderArrow(string $order): string
    {
        return match ($order) {
            'asc' => '↑',
            'desc' => '↓',
            default => '',
        };
    }

    /**
     * @param 'csv'|'json'|'xml' $format
     */
    public function mimeType(string $format): string
    {
        return $format === 'csv' ? 'text/csv' : "application/$format";
    }

    public function grade(int $score): string
    {
        if ($score >= 90) {
            return 'A';
        }
        if ($score >= 75) {
            return 'B';
        }
        return 'C';
    }

    public function isSuccess(int $status): bool
    {
        return in_array($status, self::SUCCESS_CODES, true);
    }

    /**
     * @phpstan-param self::LEVEL_* $level
     */
    public function cssClass(string $level): string
    {
        switch ($level) {
            case self::LEVEL_ERROR:
                return 'alert-danger';
            case self::LEVEL_WARNING:
                return 'alert-warning';
            default:
                return 'alert-info';
        }
    }
}
