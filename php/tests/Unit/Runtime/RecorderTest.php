<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Testor\Runtime\Recorder;
use Testor\Tests\Unit\Runtime\Fixtures\Branchy;

enum Suit
{
    case Hearts;
}

#[CoversClass(Recorder::class)]
final class RecorderTest extends TestCase
{
    private string $file;
    private string|false $previous;

    protected function setUp(): void
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'testor-rec');
        unlink($this->file);
        $this->previous = getenv('TESTOR_RECORD_FILE');
        putenv("TESTOR_RECORD_FILE=$this->file");
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'TESTOR_RECORD_FILE' : "TESTOR_RECORD_FILE=$this->previous");
        @unlink($this->file);
    }

    /** The outcome recorded for $call, without the (empty) list of spied calls. */
    private function record(callable $call): array
    {
        Recorder::record('id', $call);
        $outcome = Recorder::read($this->file)['cases']['id'];
        $this->assertSame([], $outcome['calls']);
        unset($outcome['calls']);
        return $outcome;
    }

    public function testScalarsAndArraysAreExportedAsPhpCode(): void
    {
        $this->assertSame(['kind' => 'value', 'export' => "'it\\'s'", 'type' => 'string'], $this->record(fn () => "it's"));
        $this->assertSame(['kind' => 'value', 'export' => '1.0', 'type' => 'float'], $this->record(fn () => 1.0));
        $this->assertSame(['kind' => 'value', 'export' => 'null', 'type' => 'null'], $this->record(fn () => null));
        $this->assertSame(
            ['kind' => 'value', 'export' => '[1, [true, null]]', 'type' => 'array'],
            $this->record(fn () => [1, [true, null]]),
        );
        $this->assertSame(
            ['kind' => 'value', 'export' => "[0 => 1, 'k' => -INF]", 'type' => 'array'],
            $this->record(fn () => [1, 'k' => -INF]),
            'keys are kept unless the array is a list',
        );
    }

    public function testEnumsAreExportedAsCaseReferences(): void
    {
        $this->assertSame('\\' . Suit::class . '::Hearts', $this->record(fn () => Suit::Hearts)['export']);
    }

    public function testExceptionsAreCaptured(): void
    {
        $this->assertSame(
            ['kind' => 'throws', 'class' => \DomainException::class, 'message' => 'nope'],
            $this->record(fn () => throw new \DomainException('nope')),
        );
    }

    public function testWarningsAreReportedInsteadOfTheValue(): void
    {
        $outcome = $this->record(function () {
            $a = [];
            return $a['missing'];
        });

        $this->assertSame('notice', $outcome['kind']);
        $this->assertStringStartsWith('Undefined array key "missing"', $outcome['notices'][0]);
    }

    public function testSilencedWarningsAreIgnored(): void
    {
        $this->assertSame('value', $this->record(fn () => @file_get_contents('/does/not/exist'))['kind']);
    }

    public function testNonExportableValues(): void
    {
        $this->assertSame(['kind' => 'nan'], $this->record(fn () => NAN));
        $this->assertSame(['kind' => 'array', 'count' => 1], $this->record(fn () => [new \stdClass()]));
        $this->assertSame(['kind' => 'array', 'count' => 5000], $this->record(fn () => range(1, 5000)), 'too large to assert');
    }

    public function testLargeArraysArePrettyPrintedOneItemPerLine(): void
    {
        $value = ['name' => str_repeat('x', 60), 'tags' => range(1, 30), 'empty' => []];
        $export = $this->record(fn () => $value)['export'];

        $this->assertStringStartsWith("[\n    'name' => 'xxx", $export);
        $this->assertStringContainsString("\n    'tags' => [\n        1,\n        2,", $export, 'nested arrays are indented');
        $this->assertStringContainsString("\n    'empty' => [],\n]", $export);
        $this->assertSame($value, eval("return $export;"));
    }

    public function testStringsWithControlCharactersStayOnOneLine(): void
    {
        $value = "line 1\nline 2\t\$var \"quoted\" \\ \x07";
        $export = $this->record(fn () => $value)['export'];

        $this->assertStringNotContainsString("\n", $export);
        $this->assertSame($value, eval("return $export;"));
    }

    public function testObjectsAreDescribedByTheirPublicState(): void
    {
        $object = new class {
            public int $id = 7;
            public array $tags = ['a'];
            public \stdClass $nested;
            protected string $hidden = 'no';
            public function __construct() { $this->nested = new \stdClass(); }
        };
        $this->assertSame(
            ['kind' => 'object', 'class' => null, 'properties' => ['id' => '7', 'tags' => "['a']"], 'datetime' => null],
            $this->record(fn () => $object),
        );
        $this->assertSame(
            ['kind' => 'object', 'class' => \DateTimeImmutable::class, 'properties' => [], 'datetime' => '2024-01-15T10:30:00+00:00'],
            $this->record(fn () => new \DateTimeImmutable('2024-01-15 10:30:00', new \DateTimeZone('UTC'))),
        );
        $double = $this->createStub(\Countable::class);
        $this->assertSame(\Countable::class, $this->record(fn () => $double)['class'], 'doubled type, not TestStub_…');
    }

    public function testRecordsAccumulateInTheSameFile(): void
    {
        Recorder::record('a', fn () => 1);
        Recorder::record('b', fn () => 2);

        $this->assertSame(['a', 'b'], array_keys(Recorder::read($this->file)['cases']));
    }

    public function testSpiesRecordCallsAndReturnTheConfiguredValue(): void
    {
        Recorder::start('id', []);
        $spy = Recorder::spy('ctor:repo', 'find', fn () => 'found');
        $double = $this->createStub(\Countable::class);

        Recorder::record('id', fn () => [$spy(1, 'two', new \ArrayObject(), $double), $spy()]);
        $outcome = Recorder::read($this->file)['cases']['id'];

        $this->assertSame("['found', 'found']", $outcome['export']);
        $this->assertSame([
            ['ctor:repo', 'find', [
                ['kind' => 'value', 'export' => '1'],
                ['kind' => 'value', 'export' => "'two'"],
                ['kind' => 'object', 'class' => \ArrayObject::class],
                ['kind' => 'object', 'class' => \Countable::class], // the doubled type, not MockObject_…
            ]],
            ['ctor:repo', 'find', []],
        ], $outcome['calls']);
        $this->assertFileDoesNotExist($this->file . '.running', 'the case is no longer running');
    }

    public function testStartMarksTheRunningCase(): void
    {
        Recorder::start('c42', []);

        $this->assertSame('c42', file_get_contents($this->file . '.running'));
        Recorder::record('c42', fn () => 1);
    }

    public function testNoCoverageUnlessStarted(): void
    {
        $this->assertArrayNotHasKey('covered', $this->record(fn () => 1));
        $this->assertArrayNotHasKey('executable', Recorder::read($this->file));
    }

    public function testCoverageOfTheCallIsMeasured(): void
    {
        if (Recorder::driver() === null) {
            $this->markTestSkipped('needs PCOV or Xdebug (coverage mode)');
        }
        $file = (string) realpath(__DIR__ . '/Fixtures/Branchy.php');
        $dir = (string) ini_get('pcov.directory');
        if (Recorder::driver() === 'pcov' && ($dir === '' || !str_starts_with($file, (string) realpath($dir)))) {
            $this->markTestSkipped('PCOV does not instrument tests/: run `composer test` (sets pcov.directory)');
        }

        Recorder::start('id', [$file]);
        $sut = new Branchy();
        Recorder::record('id', fn () => $sut->answer(true));

        $data = Recorder::read($this->file);
        $this->assertContains(12, $data['cases']['id']['covered'][$file]);
        $this->assertNotContains(14, $data['cases']['id']['covered'][$file]);
        $this->assertContains(14, $data['executable'][$file]);
        $this->assertSame('value', $data['cases']['id']['kind']);
    }
}
