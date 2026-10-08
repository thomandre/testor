<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Cases;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Testor\Cases\Imports;
use Testor\Cases\Val;
use Testor\Cases\Values;
use Testor\Tests\Support\Descriptors;

#[CoversClass(Values::class)]
final class ValuesTest extends TestCase
{
    use Descriptors;

    private Imports $imports;
    /** @var array<string, array> class registry */
    private array $types = [];

    protected function setUp(): void
    {
        $this->imports = new Imports();
    }

    /** Registers a class and returns its type descriptor. */
    private function register(string $name, array $info = [], bool $nullable = false): array
    {
        $this->types[$name] = self::typeInfo($info);
        return self::classType($name, $nullable);
    }

    private function values(): Values
    {
        return new Values($this->types);
    }

    /** @return list<string>|null */
    private function exprs(?array $type, ?array $domain = null): ?array
    {
        $vals = $this->values()->forParam(self::param('p', $type, ['domain' => $domain]), $this->imports);
        return $vals === null ? null : array_map(fn (Val $v) => $v->expr, $vals);
    }

    public function testBuiltinTypesStartWithATypicalValue(): void
    {
        $this->assertSame(['1', '0', '-1', '100'], $this->exprs(self::builtin('int')));
        $this->assertSame(["'testor'", "''", "'  Hello, World!  '"], $this->exprs(self::builtin('string')));
        $this->assertSame(['true', 'false'], $this->exprs(self::builtin('bool')));
    }

    public function testNullableTypesAlsoTryNull(): void
    {
        $this->assertSame(['true', 'false', 'null'], $this->exprs(self::builtin('bool', nullable: true)));
    }

    public function testUntypedParametersGetMixedValues(): void
    {
        $this->assertSame(["'testor'", 'null', '1', '[]'], $this->exprs(null));
    }

    public function testUnionMergesMemberCandidates(): void
    {
        $this->assertSame(
            ['1', '0', '-1', '100', "'testor'", "''", "'  Hello, World!  '", 'null'],
            $this->exprs(self::union([self::builtin('int'), self::builtin('string')], nullable: true)),
        );
    }

    public function testEnumGetsEveryCase(): void
    {
        $type = $this->register('App\Tier', ['isEnum' => true, 'isFinal' => true, 'enumCases' => ['Gold', 'Silver']]);

        $this->assertSame(['Tier::Gold', 'Tier::Silver'], $this->exprs($type));
        $this->assertSame(['use App\Tier;'], $this->imports->lines());
    }

    public function testInterfacesAndOpenClassesAreStubbed(): void
    {
        $type = $this->register('App\Clock', ['isInterface' => true]);

        $this->assertEquals(
            [new Val('$this->createStub(Clock::class)', hoist: true)],
            $this->values()->forParam(self::param('p', $type), $this->imports),
        );
        $this->assertTrue($this->values()->isStubbable($type));
        $this->assertFalse($this->values()->isStubbable(self::builtin('int')));
    }

    public function testFinalClassesAreBuiltWithBaselineArguments(): void
    {
        $money = $this->register('App\Money', ['isFinal' => true, 'constructor' => [
            self::param('amount', self::builtin('int')),
            self::param('currency', self::builtin('string'), ['optional' => true]),
        ]]);

        $this->assertSame(['new Money(1)'], $this->exprs($money), 'optional parameters keep their default');
    }

    public function testFinalClassWithPrivateConstructorUsesAFactory(): void
    {
        $email = $this->register('App\Email', ['isFinal' => true, 'factories' => [
            ['name' => 'from', 'params' => [self::param('value', self::builtin('string'))]],
        ]]);

        $this->assertSame(["Email::from('testor')"], $this->exprs($email));
    }

    public function testNestedObjectsAreBuiltInlineUpToALimitedDepth(): void
    {
        $this->register('App\Id', ['isFinal' => true, 'constructor' => [self::param('v', self::builtin('int'))]]);
        $this->register('App\Clock', ['isInterface' => true]);
        $order = $this->register('App\Order', ['isFinal' => true, 'constructor' => [
            self::param('id', self::classType('App\Id')),
            self::param('clock', self::classType('App\Clock')),
        ]]);
        $this->assertSame(['new Order(new Id(1), $this->createStub(Clock::class))'], $this->exprs($order));

        // L1 > L2 > L3 > L4 > L5: L5 would be built at depth 4, beyond the limit.
        foreach (range(1, 5) as $i) {
            $this->register("App\\L$i", ['isFinal' => true, 'constructor' => [
                self::param('v', $i < 5 ? self::classType('App\\L' . ($i + 1)) : self::builtin('int')),
            ]]);
        }
        $this->assertSame(['new L2(new L3(new L4(new L5(1))))'], $this->exprs(self::classType('App\L2')));
        $this->assertNull($this->exprs(self::classType('App\L1')), 'too deep');
    }

    public function testUnbuildableTypes(): void
    {
        $this->register('App\Sealed', ['isFinal' => true]);

        $this->assertNull($this->exprs(self::classType('App\Sealed')), 'no public constructor, no factory');
        $this->assertNull($this->exprs(['kind' => 'named', 'name' => 'self', 'builtin' => false, 'nullable' => false]));
        $this->assertNull($this->exprs(['kind' => 'intersection']));
        $this->assertNull($this->exprs(self::classType('App\Unknown')));
    }

    public function testDatesUseAFixedInstant(): void
    {
        $this->assertSame(
            ["new DateTimeImmutable('2024-01-15 10:30:00')"],
            $this->exprs(['kind' => 'named', 'name' => 'DateTimeInterface', 'builtin' => false, 'nullable' => false]),
        );
    }

    public function testReturnValuesForStubbedMethods(): void
    {
        $this->register('App\Order', ['isFinal' => true, 'constructor' => [self::param('id', self::builtin('int'))]]);
        $this->register('App\Repo', ['isInterface' => true]);
        $returns = fn (?array $type) => array_map(fn (?Val $v) => $v?->expr, $this->values()->forReturn($type, $this->imports));

        $this->assertSame(['true', 'false'], $returns(self::builtin('bool')));
        $this->assertSame(['new Order(1)', 'null'], $returns(self::classType('App\Order', nullable: true)));
        $this->assertSame([null, 'null'], $returns(self::classType('App\Repo', nullable: true)), 'default stub, then null');
        $this->assertSame([], $returns(self::classType('App\Repo')), 'PHPUnit default stub is enough');
        $this->assertSame([], $returns(self::builtin('void')));
        $this->assertSame([], $returns(null));
    }

    public function testStubReturnDomainValuesComeAfterTheGenericBaseline(): void
    {
        $domain = self::domain([self::literal('99', 'int'), self::literal('100', 'int'), self::literal("'x'", 'string')]);
        $returns = array_map(fn (Val $v) => $v->expr, $this->values()->forReturn(self::builtin('int'), $this->imports, $domain));

        $this->assertSame(['1', '99', '100', '0', '-1'], $returns);
    }

    public function testOptionalArgumentsReadByTheCodeUnderTestAreVaried(): void
    {
        $this->register('App\Status', ['isEnum' => true, 'isFinal' => true, 'enumCases' => ['Pending', 'Paid', 'Cancelled']]);
        $order = $this->register('App\Order', ['isFinal' => true, 'constructor' => [
            self::param('id', self::builtin('int')),
            self::param('status', self::classType('App\Status'), ['optional' => true, 'default' => ['kind' => 'enum', 'case' => 'Pending']]),
            self::param('note', self::builtin('string', nullable: true), ['optional' => true, 'default' => ['kind' => 'null']]),
        ]]);

        $this->assertSame(['new Order(1)'], $this->exprs($order), 'no hint: defaults only');

        $vals = $this->values()->withHints(['status', 'note'])->forParam(self::param('p', $order), $this->imports);
        $this->assertSame([
            'new Order(1)',
            'new Order(1, status: Status::Paid)',
            'new Order(1, status: Status::Cancelled)',
            "new Order(1, note: 'testor')",
            "new Order(1, note: '')",
        ], array_map(fn (Val $v) => $v->expr, $vals), 'default values are skipped, at most 4 variants');
    }

    public function testExhaustiveDomainOnlyUsesItsValues(): void
    {
        $domain = self::domain([self::literal("'csv'", 'string'), self::literal("'json'", 'string')], exhaustive: true);

        $this->assertSame(["'csv'", "'json'"], $this->exprs(self::builtin('string'), $domain));
        $this->assertSame(["'csv'", "'json'", 'null'], $this->exprs(self::builtin('string', nullable: true), $domain));
    }

    public function testNonExhaustiveDomainAddsAFallbackValue(): void
    {
        $domain = self::domain([self::literal("'asc'", 'string'), self::literal("'desc'", 'string')]);

        $this->assertSame(["'asc'", "'desc'", "'testor'"], $this->exprs(self::builtin('string'), $domain));
        $this->assertSame(["'asc'", "'desc'", "'testor'", 'null'], $this->exprs(null, $domain));
    }

    public function testDomainValuesOfTheWrongTypeAreDropped(): void
    {
        $domain = self::domain([self::literal("'5'", 'string'), self::literal('5', 'int'), self::literal('null', 'null')]);

        $this->assertSame(['5', '1'], $this->exprs(self::builtin('int'), $domain));
        $this->assertSame(['5', '1.5'], $this->exprs(self::builtin('float'), $domain), 'int is accepted for float');
    }

    public function testPublicConstantsAreReferencedByName(): void
    {
        $domain = self::domain([['expr' => "'error'", 'type' => 'string', 'class' => 'App\Log', 'const' => 'ERROR']], exhaustive: true);

        $this->assertSame(['Log::ERROR'], $this->exprs(self::builtin('string'), $domain));
        $this->assertSame(['use App\Log;'], $this->imports->lines());
    }

    public function testEmptyDomainFallsBackToGenericValues(): void
    {
        $domain = self::domain([self::literal("'x'", 'string')]);

        $this->assertSame(['true', 'false'], $this->exprs(self::builtin('bool'), $domain));
    }
}
