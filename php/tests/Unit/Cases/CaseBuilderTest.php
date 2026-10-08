<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Cases;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Testor\Cases\Arg;
use Testor\Cases\CaseBuilder;
use Testor\Cases\Imports;
use Testor\Cases\Scenario;
use Testor\Cases\Values;
use Testor\Tests\Support\Descriptors;

#[CoversClass(CaseBuilder::class)]
final class CaseBuilderTest extends TestCase
{
    use Descriptors;

    /** @var array<string, array> class registry */
    private array $types = [];

    /** @return array{list<Scenario>, list<array{string, string}>} */
    private function build(array $class, int $maxCases = 20, bool $allowSideEffects = false): array
    {
        return (new CaseBuilder($maxCases, $allowSideEffects, new Values($this->types)))->build($class, new Imports());
    }

    /** Registers a dependency interface whose methods return the given types. */
    private function dependency(string $name, array $methods): array
    {
        $this->types[$name] = self::typeInfo(['isInterface' => true, 'methods' => array_combine(
            array_map('strtolower', array_keys($methods)),
            array_map(fn (string $m, ?array $t) => ['name' => $m, 'returnType' => $t], array_keys($methods), $methods),
        )]);
        return self::classType($name);
    }

    /** @return list<array<string, string>> the stub configuration of argument $i in each scenario */
    private static function returns(array $scenarios, string $arg): array
    {
        return array_map(function (Scenario $s) use ($arg) {
            $all = [...$s->construction?->args ?? [], ...$s->args];
            return array_values(array_filter($all, fn (Arg $a) => $a->name === $arg))[0]->val->returns;
        }, $scenarios);
    }

    /** @return list<string> e.g. "add(1, 0)" */
    private static function calls(array $scenarios): array
    {
        return array_map(
            fn (Scenario $s) => $s->method['name'] . '(' . implode(', ', array_map(fn (Arg $a) => $a->val->expr, $s->args)) . ')',
            $scenarios,
        );
    }

    public function testBaselineThenOneParameterVariedAtATime(): void
    {
        $class = self::classDescriptor('App\Calc', [
            self::method('add', [self::param('a', self::builtin('int')), self::param('b', self::builtin('bool'))]),
        ]);

        [$scenarios] = $this->build($class);

        $this->assertSame(['add(1, true)', 'add(0, true)', 'add(-1, true)', 'add(100, true)', 'add(1, false)'], self::calls($scenarios));
        $this->assertSame(['c0', 'c1', 'c2', 'c3', 'c4'], array_map(fn (Scenario $s) => $s->id, $scenarios));
    }

    public function testMaxCasesCapsScenariosPerMethod(): void
    {
        $class = self::classDescriptor('App\Calc', [
            self::method('a', [self::param('x', self::builtin('int'))]),
            self::method('b', [self::param('x', self::builtin('int'))]),
        ]);

        [$scenarios] = $this->build($class, maxCases: 2);

        $this->assertSame(['a(1)', 'a(0)', 'b(1)', 'b(0)'], self::calls($scenarios));
    }

    public function testOptionalParametersWithoutStrategyAreOmitted(): void
    {
        $class = self::classDescriptor('App\Svc', [
            self::method('run', [
                self::param('a', self::builtin('bool')),
                self::param('b', ['kind' => 'intersection'], ['optional' => true]),
            ]),
        ]);

        $this->assertSame(['run(true)', 'run(false)'], self::calls($this->build($class)[0]));
    }

    public function testSkippedMethodsComeWithAReason(): void
    {
        $class = self::classDescriptor('App\Svc', [
            self::method('save', [], ['sideEffects' => ['file_put_contents', 'mkdir']]),
            self::method('swap', [self::param('a', self::builtin('int'), ['byRef' => true])]),
            self::method('cmp', [self::param('a', ['kind' => 'intersection'])]),
        ]);

        [$scenarios, $skipped] = $this->build($class);

        $this->assertSame([], $scenarios);
        $this->assertSame([
            ['save', 'side effects: file_put_contents, mkdir'],
            ['swap', 'parameter $a is passed by reference'],
            ['cmp', 'no value strategy for parameter $a'],
        ], $skipped);
    }

    public function testSideEffectsCanBeAllowed(): void
    {
        $class = self::classDescriptor('App\Svc', [self::method('save', [], ['sideEffects' => ['unlink']])]);

        $this->assertSame(['save()'], self::calls($this->build($class, allowSideEffects: true)[0]));
    }

    public function testConstructorGetsBaselineValues(): void
    {
        $this->types['App\Clock'] = self::typeInfo(['isInterface' => true]);
        $class = self::classDescriptor('App\Svc', [self::method('run')], [
            self::param('clock', self::classType('App\Clock')),
            self::param('retries', self::builtin('int'), ['optional' => true]),
        ]);

        $construction = $this->build($class)[0][0]->construction;

        $this->assertNull($construction->factory);
        $this->assertSame(['clock'], array_map(fn (Arg $a) => $a->name, $construction->args), 'optional parameters use their default');
        $this->assertSame('$this->createStub(Clock::class)', $construction->args[0]->val->expr);
    }

    public function testStubReturnValuesAreExploredOneAtATime(): void
    {
        $gateway = $this->dependency('App\Gateway', ['charge' => self::builtin('bool'), 'fee' => self::builtin('float'), 'log' => self::builtin('void')]);
        $class = self::classDescriptor('App\Svc', [
            self::method('pay', extra: ['calls' => ['property' => ['gateway' => ['charge', 'fee', 'log']], 'variable' => []]]),
            self::method('name'),
        ], [self::param('gateway', $gateway)]);
        $class['ctorProperties'] = ['gateway' => 'gateway'];

        [$scenarios] = $this->build($class);
        $pay = array_values(array_filter($scenarios, fn (Scenario $s) => $s->method['name'] === 'pay'));
        $name = array_values(array_filter($scenarios, fn (Scenario $s) => $s->method['name'] === 'name'));

        $this->assertSame([
            ['charge' => 'true', 'fee' => '1.5'],
            ['charge' => 'false', 'fee' => '1.5'],
            ['charge' => 'true', 'fee' => '0.0'],
            ['charge' => 'true', 'fee' => '-2.5'],
        ], self::returns($pay, 'gateway'), 'void methods are not configured');
        $this->assertSame([[]], self::returns($name, 'gateway'), 'methods not calling the dependency leave it unconfigured');
    }

    public function testMethodsCalledByTheConstructorAreConfiguredInEveryScenario(): void
    {
        $relation = $this->dependency('App\Relation', ['params' => self::builtin('array')]);
        $class = self::classDescriptor('App\Svc', [self::method('run')], [self::param('relation', $relation)]);
        $class['constructorCalls'] = ['property' => [], 'variable' => ['relation' => ['params']]];

        [$scenarios] = $this->build($class);

        $this->assertSame(['params' => '[1, 2, 3]'], self::returns($scenarios, 'relation')[0]);
    }

    public function testStubParametersOfTheMethodAreConfiguredToo(): void
    {
        $repo = $this->dependency('App\Repo', ['exists' => self::builtin('bool')]);
        $class = self::classDescriptor('App\Svc', [self::method('check', [self::param('repo', $repo)],
            ['calls' => ['property' => [], 'variable' => ['repo' => ['exists']]]])]);

        [$scenarios] = $this->build($class);

        $this->assertSame([['exists' => 'true'], ['exists' => 'false']], self::returns($scenarios, 'repo'));
    }

    public function testPrivateConstructorFallsBackToStaticFactory(): void
    {
        $class = self::classDescriptor('App\Email', [
            self::method('from', [self::param('value', self::builtin('string'))], ['static' => true, 'factory' => true]),
            self::method('domain'),
        ], constructor: null);

        [$scenarios] = $this->build($class);
        $domain = array_values(array_filter($scenarios, fn (Scenario $s) => $s->method['name'] === 'domain'))[0];

        $this->assertSame('from', $domain->construction->factory);
        $this->assertSame("'testor'", $domain->construction->args[0]->val->expr);
    }

    public function testStaticMethodsNeedNoInstance(): void
    {
        $class = self::classDescriptor('App\Util', [
            self::method('even', [self::param('n', self::builtin('int'))], ['static' => true]),
            self::method('instance'),
        ], constructor: null);

        [$scenarios, $skipped] = $this->build($class);

        $this->assertSame(['even(1)', 'even(0)', 'even(-1)', 'even(100)'], self::calls($scenarios));
        $this->assertNull($scenarios[0]->construction);
        $this->assertSame([['instance', 'constructor is not public']], $skipped);
    }
}
