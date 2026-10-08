<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Cases;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Testor\Cases\Imports;

#[CoversClass(Imports::class)]
final class ImportsTest extends TestCase
{
    public function testReturnsTheShortNameAndRemembersIt(): void
    {
        $imports = new Imports();

        $this->assertSame('Clock', $imports->ref('\App\Time\Clock'));
        $this->assertSame('Clock', $imports->ref('App\Time\Clock'));
        $this->assertSame(['use App\Time\Clock;'], $imports->lines());
    }

    public function testAliasesCollidingShortNames(): void
    {
        $imports = new Imports();

        $this->assertSame('Status', $imports->ref('App\Http\Status'));
        $this->assertSame('Status2', $imports->ref('App\Order\Status'));
        $this->assertSame('Status3', $imports->ref('Vendor\Status'));
        $this->assertSame(
            ['use App\Http\Status;', 'use App\Order\Status as Status2;', 'use Vendor\Status as Status3;'],
            $imports->lines(),
        );
    }

    public function testLinesAreSortedAndGlobalClassesImported(): void
    {
        $imports = new Imports();
        $imports->ref('Zeta\Thing');
        $imports->ref('InvalidArgumentException');

        $this->assertSame(['use InvalidArgumentException;', 'use Zeta\Thing;'], $imports->lines());
    }

    public function testCloneIsIndependent(): void
    {
        $imports = new Imports();
        $imports->ref('App\A');
        $fork = clone $imports;
        $fork->ref('App\B');

        $this->assertSame(['use App\A;'], $imports->lines());
        $this->assertSame(['use App\A;', 'use App\B;'], $fork->lines());
    }
}
