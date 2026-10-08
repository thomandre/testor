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
            'testAddReturnsInt', 'testAddReturnsInt2', 'testIsEvenReturnsTrue', 'testDivideThrowsDivisionByZeroError',
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
