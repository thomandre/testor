<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Render;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Testor\Cases\Arg;
use Testor\Cases\Construction;
use Testor\Cases\Imports;
use Testor\Cases\Scenario;
use Testor\Cases\Val;
use Testor\Render\Renderer;
use Testor\Tests\Support\Descriptors;

#[CoversClass(Renderer::class)]
final class RendererTest extends TestCase
{
    use Descriptors;

    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Renderer();
    }

    private static function scenario(string $method, array $args = [], ?Construction $construction = new Construction(null, []),
                                     array $methodExtra = []): Scenario
    {
        return new Scenario('c0', self::method($method, [], $methodExtra), $construction, $args);
    }

    public function testPhpStringEscapesQuotesAndBackslashes(): void
    {
        $this->assertSame("'it\\'s a C:\\\\path'", Renderer::phpString("it's a C:\\path"));
        $this->assertSame("it's a C:\\path", eval('return ' . Renderer::phpString("it's a C:\\path") . ';'));
    }

    public function testArrangeHoistsObjectsIntoUniquelyNamedVariables(): void
    {
        $stub = new Val('$this->createStub(Clock::class)', hoist: true);
        $case = self::scenario('at', [new Arg('clock', $stub), new Arg('n', new Val('1'))],
            new Construction(null, [new Arg('clock', $stub)]));

        [$lines, $call] = $this->renderer->arrange($case, 'Svc');

        $this->assertSame([
            '$clock = $this->createStub(Clock::class);',
            '$sut = new Svc($clock);',
            '$clock2 = $this->createStub(Clock::class);',
        ], $lines);
        $this->assertSame('$sut->at($clock2, 1)', $call);
    }

    public function testArrangeConfiguresStubReturnValues(): void
    {
        $stub = new Val('$this->createStub(Gateway::class)', hoist: true, returns: ['charge' => 'false', 'fee' => '1.5']);
        $case = self::scenario('pay', [], new Construction(null, [new Arg('gateway', $stub)]));

        $this->assertSame([[
            '$gateway = $this->createStub(Gateway::class);',
            "\$gateway->method('charge')->willReturn(false);",
            "\$gateway->method('fee')->willReturn(1.5);",
            '$sut = new Svc($gateway);',
        ], '$sut->pay()'], $this->renderer->arrange($case, 'Svc'));
    }

    public function testArrangeConfiguresThrowingStubs(): void
    {
        $stub = new Val('$this->createStub(Basket::class)', hoist: true, throws: ['count' => "new RuntimeException('testor')"]);

        [$lines] = $this->renderer->arrange(self::scenario('items', [], new Construction(null, [new Arg('basket', $stub)])), 'Svc');

        $this->assertContains("\$basket->method('count')->willThrowException(new RuntimeException('testor'));", $lines);
    }

    public function testTestNamesDescribeWhatDiffersFromTheBaseline(): void
    {
        $case = new Scenario('c1', self::method('pay'), new Construction(null, []), [], [0, 2],
            ['WhenFindReturnsNull', 'WhenChargeReturnsFalse', 'WithAmountMinus1']);
        $class = self::classDescriptor('App\Svc', []);

        [, $names] = $this->renderer->final($class, [
            [$case, ['kind' => 'value', 'export' => "'declined'", 'type' => 'string']],
            [$case, ['kind' => 'value', 'export' => "'a very long string that cannot name a test'", 'type' => 'string']],
        ], new Imports(), 'Tests');

        $this->assertSame([
            'testPayReturnsDeclinedWhenFindReturnsNullAndWhenChargeReturnsFalse',
            'testPayReturnsStringWhenFindReturnsNullAndWhenChargeReturnsFalse',
        ], $names, 'at most two variations');
    }

    public function testRecordingSpiesOnConfiguredAndVoidMethods(): void
    {
        $stub = new Val('$this->createStub(Gateway::class)', hoist: true, returns: ['charge' => 'true'],
            throws: ['fee' => "new RuntimeException('testor')"], spied: ['log']);
        $case = self::scenario('pay', [], new Construction(null, [new Arg('gateway', $stub)]));

        [$lines] = $this->renderer->arrange($case, 'Svc', recording: true);

        $this->assertSame([
            '$gateway = $this->createStub(Gateway::class);',
            "\$gateway->method('charge')->willReturnCallback(\\Testor\\Runtime\\Recorder::spy('ctor:gateway', 'charge', fn () => true));",
            "\$gateway->method('fee')->willReturnCallback(\\Testor\\Runtime\\Recorder::spy('ctor:gateway', 'fee', fn () => throw new RuntimeException('testor')));",
            "\$gateway->method('log')->willReturnCallback(\\Testor\\Runtime\\Recorder::spy('ctor:gateway', 'log', fn () => null));",
            '$sut = new Svc($gateway);',
        ], $lines);
    }

    public function testFinalTestsExpectTheRecordedCalls(): void
    {
        $stub = new Val('$this->createStub(Gateway::class)', hoist: true, returns: ['charge' => 'true', 'fee' => '1.5'], spied: ['log']);
        $case = self::scenario('pay', [], new Construction(null, [new Arg('gateway', $stub)]));
        $calls = [
            ['ctor:gateway', 'charge', [['kind' => 'value', 'export' => '3.0'], ['kind' => 'object', 'class' => 'App\Card']]],
            ['ctor:gateway', 'log', [['kind' => 'any']]],
            ['ctor:gateway', 'log', [['kind' => 'any']]],
        ];
        $imports = new Imports();

        [$lines] = $this->renderer->arrange($case, 'Svc', $calls, imports: $imports);

        $this->assertSame([
            '$gateway = $this->createMock(Gateway::class);',
            "\$gateway->expects(\$this->once())->method('charge')->with(3.0, \$this->isInstanceOf(Card::class))->willReturn(true);",
            "\$gateway->expects(\$this->never())->method('fee');",
            "\$gateway->expects(\$this->exactly(2))->method('log');",
            '$sut = new Svc($gateway);',
        ], $lines);
        $this->assertSame(['use App\Card;'], $imports->lines());
    }

    public function testVoidMethodTestsRelyOnMockExpectations(): void
    {
        $stub = new Val('$this->createStub(Mailer::class)', hoist: true, spied: ['send']);
        $case = new Scenario('c0', self::method('notify', [], ['returnType' => self::builtin('void')]),
            new Construction(null, [new Arg('mailer', $stub)]), []);

        [$source] = $this->renderer->final(self::classDescriptor('App\Svc', []), [[$case, [
            'kind' => 'value', 'export' => 'null', 'type' => 'null', 'calls' => [['ctor:mailer', 'send', []]],
        ]]], new Imports(), 'Tests');

        $this->assertStringContainsString("\$mailer->expects(\$this->once())->method('send');", $source);
        $this->assertStringNotContainsString('expectNotToPerformAssertions', $source);
    }

    public function testArrangeWithFactoryAndStaticCall(): void
    {
        $this->assertSame(
            [["\$sut = Email::from('a@b.c');"], '$sut->domain()'],
            $this->renderer->arrange(self::scenario('domain', [], new Construction('from', [new Arg('v', new Val("'a@b.c'"))])), 'Email'),
        );
        $this->assertSame([[], 'Util::even(2)'], $this->renderer->arrange(self::scenario('even', [new Arg('n', new Val('2'))], null), 'Util'));
    }

    public function testFinalFileAssertsEachKindOfOutcome(): void
    {
        $class = self::classDescriptor('App\Calc', []);
        $cases = [
            [self::scenario('add'), ['kind' => 'value', 'export' => '3', 'type' => 'int']],
            [self::scenario('add'), ['kind' => 'value', 'export' => '4', 'type' => 'int']],
            [self::scenario('isEven'), ['kind' => 'value', 'export' => 'true', 'type' => 'bool']],
            [self::scenario('divide'), ['kind' => 'throws', 'class' => 'DivisionByZeroError', 'message' => "Can't divide"]],
            [self::scenario('reset', methodExtra: ['returnType' => self::builtin('void')]), ['kind' => 'value', 'export' => 'null', 'type' => 'null']],
            [self::scenario('now'), ['kind' => 'object', 'class' => 'DateTimeImmutable']],
            [self::scenario('rows'), ['kind' => 'array', 'count' => 2]],
        ];

        [$source, $names] = $this->renderer->final($class, $cases, new Imports(), 'Tests\Testor\App');

        $this->assertSame([
            'testAddReturns3', 'testAddReturns4', 'testIsEvenReturnsTrue', 'testDivideThrowsDivisionByZeroError',
            'testResetSucceeds', 'testNowReturnsDateTimeImmutable', 'testRowsReturnsArray',
        ], $names);
        $this->assertStringContainsString("namespace Tests\\Testor\\App;\n", $source);
        $this->assertStringContainsString(
            "use App\\Calc;\nuse DateTimeImmutable;\nuse DivisionByZeroError;\n"
            . "use PHPUnit\\Framework\\Attributes\\BackupGlobals;\nuse PHPUnit\\Framework\\Attributes\\BackupStaticProperties;\n"
            . "use PHPUnit\\Framework\\Attributes\\CoversClass;\nuse PHPUnit\\Framework\\TestCase;\n",
            $source,
        );
        $this->assertStringContainsString('$this->assertSame(3, $sut->add());', $source);
        $this->assertStringContainsString('$this->assertTrue($sut->isEven());', $source);
        $this->assertStringContainsString(
            "        \$this->expectException(DivisionByZeroError::class);\n"
            . "        \$this->expectExceptionMessage('Can\\'t divide');\n\n"
            . "        \$sut->divide();\n",
            $source,
        );
        $this->assertStringContainsString("\$this->expectNotToPerformAssertions();\n\n        \$sut->reset();", $source);
        $this->assertStringContainsString('$this->assertInstanceOf(DateTimeImmutable::class, $sut->now());', $source);
        $this->assertStringContainsString('$this->assertCount(2, $result);', $source);
        $this->assertStringContainsString("#[CoversClass(Calc::class)]\n#[BackupGlobals(true)]\n#[BackupStaticProperties(true)]\nfinal class CalcTest extends TestCase\n{", $source);
        $this->assertStringEndsWith("    }\n}\n", $source);
    }

    public function testFinalFileIsValidPhp(): void
    {
        $class = self::classDescriptor('App\Calc', []);
        [$source] = $this->renderer->final($class, [[self::scenario('add'), ['kind' => 'nan']]], new Imports(), 'Tests\Testor\App');

        $file = tempnam(sys_get_temp_dir(), 'testor') . '.php';
        file_put_contents($file, $source);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file), $output, $code);
        unlink($file);

        $this->assertSame(0, $code, implode("\n", $output));
        $this->assertStringContainsString('$this->assertNan($sut->add());', $source);
    }

    public function testRecordingFileCallsTheRecorderForEachCase(): void
    {
        $source = $this->renderer->recording(self::classDescriptor('App\Calc', []), [self::scenario('add')], new Imports(), '/x/Recorder.php');

        $this->assertStringContainsString("namespace Testor\\Recording;", $source);
        $this->assertStringContainsString("require_once '/x/Recorder.php';", $source);
        $this->assertStringContainsString("\\Testor\\Runtime\\Recorder::record('c0', fn () => \$sut->add());", $source);
    }
}
