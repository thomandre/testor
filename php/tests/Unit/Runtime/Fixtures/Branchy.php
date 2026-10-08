<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Runtime\Fixtures;

final class Branchy
{
    public function answer(bool $yes): string
    {
        if ($yes) {
            return 'yes'; // line 12
        }
        return 'no'; // line 14
    }
}
