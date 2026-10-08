<?php

declare(strict_types=1);

namespace Testor\Cases;

final readonly class Arg
{
    public function __construct(public string $name, public Val $val)
    {
    }
}
