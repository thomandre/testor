<?php

declare(strict_types=1);

namespace Testor\Cases;

/** How the instance under test is built: `new` (factory null) or a static factory method. */
final readonly class Construction
{
    /** @param list<Arg> $args */
    public function __construct(public ?string $factory, public array $args)
    {
    }
}
