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

    protected function setUp(): void
    {
        $this->imports = new Imports();
    }

    /** @return list<string>|null */
    private function exprs(?array $type, ?array $domain = null): ?array
    {
        $vals = Values::forParam(self::param('p', $type, ['domain' => $domain]), $this->imports);
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
        $type = self::classType('App\Tier', ['isEnum' => true, 'isFinal' => true, 'enumCases' => ['Gold', 'Silver']]);

        $this->assertSame(['Tier::Gold', 'Tier::Silver'], $this->exprs($type));
        $this->assertSame(['use App\Tier;'], $this->imports->lines());
    }

    public function testInterfacesAndOpenClassesAreStubbed(): void
    {
        $vals = Values::forParam(self::param('p', self::classType('App\Clock', ['isInterface' => true])), $this->imports);

        $this->assertEquals([new Val('$this->createStub(Clock::class)', hoist: true)], $vals);
    }

    public function testFinalClassIsInstantiatedOnlyWithoutRequiredArguments(): void
    {
        $this->assertSame(['new Money()'], $this->exprs(self::classType('App\Money', ['isFinal' => true])));
        $this->assertNull($this->exprs(self::classType('App\Id', ['isFinal' => true, 'requiredCtorParams' => 1])));
    }

    public function testUnsupportedTypes(): void
    {
        $this->assertNull($this->exprs(['kind' => 'named', 'name' => 'self', 'builtin' => false, 'nullable' => false]));
        $this->assertNull($this->exprs(['kind' => 'intersection']));
        $this->assertNull($this->exprs(self::classType('App\Missing', ['exists' => false])));
    }

    public function testDatesUseAFixedInstant(): void
    {
        $this->assertSame(
            ["new DateTimeImmutable('2024-01-15 10:30:00')"],
            $this->exprs(['kind' => 'named', 'name' => 'DateTimeInterface', 'builtin' => false, 'nullable' => false]),
        );
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
