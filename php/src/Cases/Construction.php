<?php

declare(strict_types=1);

namespace Testor\Cases;

/**
 * How the instance under test is built: `new` (factory null) or a static factory method; for an
 * abstract class, a partial mock implementing its abstract methods.
 */
final readonly class Construction
{
    /**
     * @param list<Arg> $args
     * @param list<string>|null $abstract abstract methods to implement (null: the class is concrete)
     */
    public function __construct(public ?string $factory, public array $args, public ?array $abstract = null)
    {
    }
}
