<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Cases;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Testor\Cases\Labels;

#[CoversClass(Labels::class)]
final class LabelsTest extends TestCase
{
    /** @return iterable<array{string, string}> */
    public static function expressions(): iterable
    {
        yield ['null', 'Null'];
        yield ['false', 'False'];
        yield ['-1', 'Minus1'];
        yield ['100402', '100402'];
        yield ['-2.5', 'Minus2Point5'];
        yield ["''", 'EmptyString'];
        yield ["'  Hello, World!  '", 'HelloWorld'];
        yield ["'user@example.com'", 'UserExampleCom'];
        yield ["'!!!'", 'String'];
        yield ['[]', 'EmptyArray'];
        yield ['[1, 2, 3]', 'Array'];
        yield ['OrderStatus::Paid', 'Paid'];
        yield ['Formatter::LEVEL_ERROR', 'LevelError'];
        yield ['new Order(1, 1.5)', 'Order'];
        yield ['new Order(1, 1.5, status: OrderStatus::Paid)', 'OrderWithStatusPaid'];
        yield ["DatabaseName::from('x')", 'DatabaseName'];
        yield ['$this->createStub(Clock::class)', 'Clock'];
        yield ["'a string far too long to be part of a test name'", ''];
        yield ['static fn () => null', ''];
    }

    #[DataProvider('expressions')]
    public function testValueLabels(string $expr, string $label): void
    {
        $this->assertSame($label, Labels::value($expr));
    }
}
