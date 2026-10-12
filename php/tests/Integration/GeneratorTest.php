<?php

declare(strict_types=1);

namespace Testor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Testor\ClassReport;
use Testor\Generator;
use Testor\PhpUnit\Runner;
use Testor\Process;

/** End to end: generate tests for the demo project, then check what was produced. */
#[CoversClass(Generator::class)]
#[CoversClass(Runner::class)]
final class GeneratorTest extends DemoProjectTestCase
{
    private static ?string $out = null;
    /** @var array<string, ClassReport> */
    private static array $reports = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$out === null) {
            self::$out = Process::tempDir();
            $reports = (new Generator(self::demo(), self::$out))->generate([self::demo() . '/src']);
            foreach ($reports as $report) {
                self::$reports[$report->name] = $report;
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$out !== null) {
            Process::removeDir(self::$out);
        }
    }

    private function generated(string $class): string
    {
        $report = self::$reports[$class];
        $this->assertNotNull($report->output, "no test generated for $class: $report->error");
        return (string) file_get_contents($report->output);
    }

    public function testTestsAreWrittenUnderTheNamespacePath(): void
    {
        $this->assertSame(self::$out . '/App/Math/CalculatorTest.php', self::$reports['App\Math\Calculator']->output);
        $this->assertStringContainsString("namespace Tests\\Testor\\App\\Math;\n", $this->generated('App\Math\Calculator'));
    }

    public function testOneTestPerDistinctOutcome(): void
    {
        $source = $this->generated('App\Math\Calculator');

        $this->assertStringContainsString("\$this->expectException(InvalidArgumentException::class);\n"
            . "        \$this->expectExceptionMessage('Division by zero');\n\n        \$sut->divide(1, 0);", $source);
        $this->assertStringContainsString('$this->assertSame(1.0, $sut->divide(1, 1));', $source);
        $this->assertStringContainsString('$this->assertTrue(Calculator::isEven(0));', $source);
        $this->assertSame(1, substr_count($source, 'ThrowsInvalidArgumentException'), 'identical outcomes are deduplicated');
    }

    public function testDomainValuesDriveTheBranches(): void
    {
        $source = $this->generated('App\Report\Formatter');

        $this->assertStringContainsString("\$this->assertSame('B', \$sut->grade(89));", $source);
        $this->assertStringContainsString("\$this->assertSame('A', \$sut->grade(90));", $source);
        $this->assertStringContainsString("\$this->assertSame('alert-danger', \$sut->cssClass(Formatter::LEVEL_ERROR));", $source);
        $this->assertStringContainsString("\$this->assertSame('', \$sut->orderArrow('testor'));", $source, 'fallback branch');
        $this->assertStringNotContainsString("mimeType('testor')", $source, 'PHPDoc domain is exhaustive');
    }

    public function testStubsAndFactories(): void
    {
        $this->assertStringContainsString(
            "\$taxRates = \$this->createStub(TaxRateProvider::class);\n        \$sut = new PriceCalculator(\$taxRates);",
            $this->generated('App\Pricing\PriceCalculator'),
        );
        $this->assertStringContainsString("\$sut = Email::from('testor');", $this->generated('App\Identity\Email'));
    }

    public function testStubReturnValuesDriveTheBranchesOfDependentCode(): void
    {
        $source = $this->generated('App\Orders\OrderService');

        $this->assertStringContainsString("\$orders->method('find')->willReturn(null);", $source);
        $this->assertStringContainsString("\$this->expectExceptionMessage('Order 1 not found');", $source);
        $this->assertStringContainsString("\$payments->method('charge')->willReturn(false);", $source);
        $this->assertStringContainsString("\$this->assertSame('declined', \$sut->pay(1));", $source);
        $this->assertStringContainsString("\$orders->method('find')->willReturn(new Order(1, 1.5));", $source);
        if (\Testor\Runtime\Recorder::driver() !== null) {
            $this->assertGreaterThanOrEqual(90.0, self::$reports['App\Orders\OrderService']->coverage());
        }
    }

    public function testOptionalArgumentsAndCrossClassThresholds(): void
    {
        $this->assertStringContainsString(
            "\$orders->method('find')->willReturn(new Order(1, 1.5, status: OrderStatus::Paid));",
            $this->generated('App\Orders\OrderService'),
        );
        $this->assertStringContainsString("\$this->assertSame('already processed', \$sut->pay(1));", $this->generated('App\Orders\OrderService'));
        $this->assertStringContainsString(
            "\$basket->method('itemCount')->willReturn(3);",
            $this->generated('App\Orders\CheckoutService'),
            'threshold of ShippingPolicy::isFree()',
        );
        if (\Testor\Runtime\Recorder::driver() !== null) {
            $this->assertSame(100.0, self::$reports['App\Orders\OrderService']->coverage());
        }
    }

    public function testValuesInferredFromUsageAndDescriptiveNames(): void
    {
        $email = $this->generated('App\Identity\Email');

        $this->assertStringContainsString("public function testDomainReturnsExampleCom(): void", $email);
        $this->assertStringContainsString("\$sut = Email::from('user@example.com');", $email);
        $this->assertStringContainsString('public function testIsValidReturnsFalseWithValueTestor(): void', $email);
        $this->assertStringContainsString(
            'public function testPayThrowsDomainExceptionWhenFindReturnsNull(): void',
            $this->generated('App\Orders\OrderService'),
        );
    }

    public function testDependencyExceptionsCoverCatchBlocks(): void
    {
        $this->assertStringContainsString(
            "\$basket->method('itemCount')->willThrowException(new RuntimeException('testor'));",
            $this->generated('App\Orders\CheckoutService'),
        );
        $this->assertStringContainsString('public function testItemsOrZeroReturns0WhenItemCountThrows(): void', $this->generated('App\Orders\CheckoutService'));
    }

    public function testSearchCombinesInputsTheFirstRoundOnlyVariedSeparately(): void
    {
        if (\Testor\Runtime\Recorder::driver() === null) {
            $this->markTestSkipped('the search needs PCOV or Xdebug (coverage mode)');
        }
        // 'Out of stock' needs itemCount() >= 5 *and* reserve() returning false.
        $this->assertStringContainsString("\$this->assertSame('Out of stock', \$sut->reserve());", $this->generated('App\Orders\CheckoutService'));

        $out = Process::tempDir();
        try {
            [$report] = (new Generator(self::demo(), $out, searchRounds: 0))->generate([self::demo() . '/src/Orders/CheckoutService.php']);
            $this->assertStringNotContainsString("'Out of stock'", (string) file_get_contents((string) $report->output));
        } finally {
            Process::removeDir($out);
        }
    }

    public function testMethodsWithSideEffectsAreSkipped(): void
    {
        $this->assertContains(['export', 'side effects: file_put_contents'], self::$reports['App\Io\CsvExporter']->skipped);
        $this->assertContains(['close', 'side effects: error_log'], self::$reports['App\Io\AccountService']->skipped);
        $this->assertStringNotContainsString('close(', $this->generated('App\Io\AccountService'));
    }

    public function testNonInstantiableKindsAreReported(): void
    {
        $this->assertSame('enum: skipped', self::$reports['App\Pricing\CustomerTier']->error);
        $this->assertSame('interface: skipped', self::$reports['App\Pricing\TaxRateProvider']->error);
        $this->assertSame('abstract: skipped', self::$reports['App\Io\AbstractExporter']->error);
    }

    public function testLineCoverageIsReportedPerClass(): void
    {
        if (\Testor\Runtime\Recorder::driver() === null) {
            $this->markTestSkipped('needs PCOV or Xdebug (coverage mode)');
        }
        $this->assertSame(100.0, self::$reports['App\Report\Formatter']->coverage());
        // export() is skipped (side effects), so only toCsv() is covered.
        $this->assertSame(50.0, self::$reports['App\Io\CsvExporter']->coverage());
    }

    public function testCoverageStrategyKeepsFewerTestsForTheSameCoverage(): void
    {
        if (\Testor\Runtime\Recorder::driver() === null) {
            $this->markTestSkipped('needs PCOV or Xdebug (coverage mode)');
        }
        $out = Process::tempDir();
        try {
            [$report] = (new Generator(self::demo(), $out, select: 'coverage'))->generate([self::demo() . '/src/Math']);
        } finally {
            Process::removeDir($out);
        }
        $full = self::$reports['App\Math\Calculator'];

        $this->assertLessThan($full->kept, $report->kept);
        $this->assertSame($full->coverage(), $report->coverage());
    }

    public function testGeneratedSuitePassesWithoutWarnings(): void
    {
        $result = (new Runner(self::demo()))->run(self::$out);

        $this->assertSame([], $result->failed, $result->output);
        $this->assertSame([], $result->noisy, $result->output);
        $this->assertCount(array_sum(array_map(fn (ClassReport $r) => $r->kept, self::$reports)), $result->passed);
    }
}
