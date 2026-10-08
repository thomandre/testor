<?php

declare(strict_types=1);

namespace App\Identity;

final class Email
{
    private function __construct(private readonly string $value)
    {
    }

    public static function from(string $value): self
    {
        return new self(strtolower(trim($value)));
    }

    public function domain(): string
    {
        $at = strrpos($this->value, '@');

        return $at === false ? '' : substr($this->value, $at + 1);
    }

    public function isValid(): bool
    {
        return filter_var($this->value, FILTER_VALIDATE_EMAIL) !== false;
    }
}
