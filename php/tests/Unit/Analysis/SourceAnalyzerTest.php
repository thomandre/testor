<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Testor\Analysis\DomainExtractor;
use Testor\Analysis\SourceAnalyzer;
use Testor\Process;

#[CoversClass(SourceAnalyzer::class)]
#[CoversClass(DomainExtractor::class)]
final class SourceAnalyzerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = Process::tempDir();
    }

    protected function tearDown(): void
    {
        Process::removeDir($this->dir);
    }

    /** @param array<string, string> $sources file name => PHP code (without the opening tag) */
    private function analyse(array $sources): array
    {
        $files = [];
        foreach ($sources as $name => $code) {
            file_put_contents($files[] = "$this->dir/$name", "<?php\n\n$code");
        }
        return (new SourceAnalyzer())->analyse($files);
    }

    /** Domain of `$p` in method `m` of class `App\Sut`, whose method body is given. */
    private function domainOf(string $body, string $signature = 'string $p', string $doc = '', string $prelude = ''): ?array
    {
        $result = $this->analyse(['Sut.php' => <<<PHP
            namespace App;
            $prelude
            final class Sut
            {
                private const MAP = ['a' => 1, 'b' => 2];
                private const LIST = ['x', 'y'];
                public const LEVEL_INFO = 'info';
                $doc
                public function m($signature) { $body }
            }
            PHP]);
        return $result['App\Sut']['methods']['m']['domains']['p'];
    }

    /** @return list<string|array> literal expressions, or the constant references */
    private static function values(?array $domain): array
    {
        return array_map(fn (array $v) => $v['expr'] ?? $v['ref'], $domain['values'] ?? []);
    }

    // ---- Domains from code -------------------------------------------------------------

    public function testEqualityWithALiteralOnEitherSide(): void
    {
        $domain = $this->domainOf("if (\$p === 'asc') { return 1; } if ('desc' == \$p) { return 2; } return 0;");

        $this->assertSame(["'asc'", "'desc'"], self::values($domain));
        $this->assertFalse($domain['exhaustive']);
        $this->assertSame('code', $domain['source']);
    }

    public function testRelationalComparisonYieldsBoundaryValues(): void
    {
        $domain = $this->domainOf('return $p >= 90 ? "A" : ($p < -5 ? "C" : "B");', 'int $p');

        $this->assertSame(['89', '90', '91', '-6', '-5', '-4'], self::values($domain));
    }

    public function testComparisonWithANonLiteralExpressionIsIgnored(): void
    {
        // Regression: the tokenizer-based version saw the `2` and inferred a threshold.
        $this->assertNull($this->domainOf('while ($p >= 2 ** $i) { ++$i; } return $i;', 'int $p, int $i'));
    }

    public function testInArrayWithLiteralArray(): void
    {
        $domain = $this->domainOf("return in_array(\$p, ['csv', 'json'], true);");

        $this->assertSame(["'csv'", "'json'"], self::values($domain));
    }

    public function testInArrayWithClassConstantIsAReference(): void
    {
        $domain = $this->domainOf('return in_array($p, self::LIST, true);');

        $this->assertSame([['class' => 'App\Sut', 'name' => 'LIST', 'keys' => false]], self::values($domain));
    }

    public function testArrayKeyExistsUsesKeys(): void
    {
        $this->assertSame(
            [['class' => 'App\Sut', 'name' => 'MAP', 'keys' => true]],
            self::values($this->domainOf('return array_key_exists($p, self::MAP);')),
        );
        $this->assertSame(
            ["'a'", "'b'"],
            self::values($this->domainOf("return array_key_exists(\$p, ['a' => 1, 'b' => 2]);")),
        );
    }

    public function testImportedClassConstantIsResolvedToItsFullyQualifiedName(): void
    {
        // Regression: names inside class bodies must be read after NameResolver resolved them.
        $domain = $this->domainOf('return $p === Status::OK;', 'int $p', prelude: 'use Http\Codes\Status;');

        $this->assertSame([['class' => 'Http\Codes\Status', 'name' => 'OK', 'keys' => false]], self::values($domain));
    }

    public function testMatchOnAnExpressionOfTheParameter(): void
    {
        $domain = $this->domainOf("return match (strtolower(\$p)) { 'asc', 'up' => 1, 'desc' => 2, default => 0 };");

        $this->assertSame(["'asc'", "'up'", "'desc'"], self::values($domain));
    }

    public function testSwitchCases(): void
    {
        $domain = $this->domainOf('switch ($p) { case self::LEVEL_INFO: return 1; case -1: return 2; default: return 0; }', 'mixed $p');

        $this->assertSame([['class' => 'App\Sut', 'name' => 'LEVEL_INFO', 'keys' => false], '-1'], self::values($domain));
    }

    public function testMatchOnAnotherVariableIsIgnored(): void
    {
        $this->assertNull($this->domainOf("return match (\$other) { 'a' => \$p, default => 0 };", 'string $p, string $other'));
    }

    public function testFirstClassCallableSyntaxDoesNotCrash(): void
    {
        $this->assertNull($this->domainOf('$f = in_array(...); return $f($p, []);'));
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function usages(): iterable
    {
        yield 'filter_var e-mail' => ['return filter_var($p, FILTER_VALIDATE_EMAIL) !== false;', 'string $p', ["'user@example.com'"]];
        yield 'preg_match' => ["return preg_match('/^[A-Z]{2}\\d{3}$/', \$p) === 1;", 'string $p', ["'AA111'"]];
        yield 'json_decode' => ['return json_decode($p, true);', 'string $p', ["'{\"key\":\"value\"}'", "'[1,2,3]'"]];
        yield 'explode' => ["return explode(';', \$p);", 'string $p', ["'a;b;c'"]];
        yield 'str_starts_with' => ["return str_starts_with(\$p, 'SKU-');", 'string $p', ["'SKU-testor'"]];
        yield 'date' => ['return new \\DateTimeImmutable($p);', 'string $p', ["'2024-01-15 10:30:00'"]];
        yield 'string length' => ['return strlen($p) > 3;', 'string $p', ["'aa'", "'aaa'", "'aaaa'"]];
        yield 'array size' => ['return count($p) === 2;', 'array $p', ['[1, 1]']];
    }

    /** @param list<string> $expected */
    #[DataProvider('usages')]
    public function testValuesInferredFromHowTheParameterIsUsed(string $body, string $signature, array $expected): void
    {
        $this->assertSame($expected, self::values($this->domainOf($body, $signature)));
    }

    public function testPropertyUsageGivesTheDomainOfConstructorAndFactoryParameters(): void
    {
        $methods = $this->analyse(['Email.php' => <<<'PHP'
            namespace App;
            final class Email
            {
                private function __construct(private string $value) {}
                public static function from(string $value): self { return new self(strtolower($value)); }
                public function isValid(): bool { return filter_var($this->value, FILTER_VALIDATE_EMAIL) !== false; }
            }
            PHP])['App\Email']['methods'];

        $this->assertSame(["'user@example.com'"], self::values($methods['__construct']['domains']['value']));
        $this->assertSame(["'user@example.com'"], self::values($methods['from']['domains']['value']));
    }

    // ---- Array shapes -----------------------------------------------------------------

    public function testKeysReadOnAnArrayGiveItsShape(): void
    {
        $body = "if (!isset(\$p['name']) || \$p['role'] !== 'admin' || !array_key_exists('id', \$p)) { return 'guest'; }"
            . " return \$p['name'];";

        $this->assertSame(["['name' => 'testor', 'role' => 'admin', 'id' => 'testor']"], self::values($this->domainOf($body, 'array $p')));
    }

    public function testShapeVariantsChangeOneKeyAtATime(): void
    {
        $values = self::values($this->domainOf("return \$p['mode'] === 'a' || \$p['mode'] === 'b' ? \$p['x'] : null;", 'array $p'));

        $this->assertSame(["['mode' => 'a', 'x' => 'testor']", "['mode' => 'b', 'x' => 'testor']"], $values);
    }

    public function testNestedShapesAndListsOfShapes(): void
    {
        $body = "\$n = 0; foreach (\$p as \$row) { if (\$row['meta']['tag'] === 'x') { \$n++; } } return \$n;";

        $this->assertSame(["[['meta' => ['tag' => 'x']]]"], self::values($this->domainOf($body, 'array $p')));
    }

    public function testKeyValuesFollowCastsAndFallbacks(): void
    {
        $body = "return [(bool) \$p['on'], \$p['n'] ?? 0, \$p['mode'] ?? self::LEVEL_INFO, \$p['unknown'] ?? \$this->x()];";

        $this->assertSame(
            ["['on' => true, 'n' => 1, 'mode' => \\App\\Sut::LEVEL_INFO]"],
            self::values($this->domainOf($body, 'array $p')),
            'a key with an unknown value read with a fallback is left out',
        );
    }

    public function testDestructuredArraysAndVariableVariables(): void
    {
        $body = "[\$top, \$right] = \$p; return \$top * 2;";
        $this->assertSame(['[0 => 1, 1 => 1]'], self::values($this->domainOf($body, 'array $p')));

        $body = "[\$top, \$bottom] = \$w; return \$\$p;";
        $this->assertSame(["'top'", "'bottom'"], self::values($this->domainOf($body, 'string $p, array $w')));
    }

    public function testPropertiesReadAndWrittenThroughTheClassMethods(): void
    {
        $methods = $this->analyse(['Box.php' => <<<'PHP'
            namespace App;
            final class Box
            {
                private int $w = 0;
                private array $items = [];
                public function setWidth(int $w): void { $this->w = $w; }
                public function add(string $item): void { $this->items[] = $item; }
                public function area(): int { return $this->width() * count($this->items); }
                private function width(): int { return $this->w; }
            }
            PHP])['App\Box']['methods'];

        $this->assertSame(['w'], $methods['setwidth']['writes']);
        $this->assertSame(['items'], $methods['add']['writes']);
        $this->assertEqualsCanonicalizing(['items', 'w'], $methods['area']['reads']);
    }

    public function testValuePassedToOwnMethodsGetsOneShapeWithAllTheirKeys(): void
    {
        $methods = $this->analyse(['Settings.php' => <<<'PHP'
            namespace App;
            final class Settings
            {
                private string $format;
                private bool $debug;
                public function __construct(array $settings = [])
                {
                    $this->format = $this->setFormat($settings);
                    $this->debug = self::setDebug($settings);
                }
                private function setFormat(array $settings): string
                {
                    return in_array($settings['format'] ?? '', ['pdf', 'svg'], true) ? $settings['format'] : 'pdf';
                }
                private static function setDebug(array $s): bool { return isset($s['debug']) && $s['debug'] === true; }
            }
            PHP])['App\Settings']['methods'];

        $this->assertSame(
            ["['format' => 'testor', 'debug' => true]"],
            self::values($methods['__construct']['domains']['settings']),
        );
    }

    public function testCaughtExceptionsAreCollectedAndFollowed(): void
    {
        $methods = $this->analyse(['Svc.php' => <<<'PHP'
            namespace App;
            use App\Errors\GatewayError;
            final class Svc
            {
                public function run(): int { return $this->safe(); }
                private function safe(): int
                {
                    try { return 1; } catch (GatewayError | \InvalidArgumentException) { return 0; }
                }
            }
            PHP])['App\Svc']['methods'];

        $this->assertSame(['App\Errors\GatewayError', 'InvalidArgumentException'], $methods['run']['catches']);
    }

    // ---- Domains from PHPDoc ----------------------------------------------------------

    public function testPhpDocLiteralUnionIsExhaustive(): void
    {
        $domain = $this->domainOf('return $p;', doc: "/** @param 'csv'|'json'|'xml' \$p */");

        $this->assertSame(["'csv'", "'json'", "'xml'"], self::values($domain));
        $this->assertTrue($domain['exhaustive']);
        $this->assertSame('phpdoc', $domain['source']);
    }

    public function testPhpDocConstantWildcardIsAReference(): void
    {
        $domain = $this->domainOf('return $p;', doc: '/** @phpstan-param self::LEVEL_* $p */');

        $this->assertSame([['class' => 'App\Sut', 'name' => 'LEVEL_*', 'keys' => false]], self::values($domain));
        $this->assertTrue($domain['exhaustive']);
    }

    public function testPhpDocConstantOfAnImportedClass(): void
    {
        $domain = $this->domainOf('return $p;', doc: '/** @psalm-param Status::STATUS_* $p */', prelude: 'use Http\Status;');

        $this->assertSame([['class' => 'Http\Status', 'name' => 'STATUS_*', 'keys' => false]], self::values($domain));
    }

    public function testMostPreciseAnnotationWins(): void
    {
        $domain = $this->domainOf('return $p;', doc: "/**\n * @param string \$p\n * @phpstan-param 'a'|'b' \$p\n */");

        $this->assertSame(["'a'", "'b'"], self::values($domain));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function refinedTypes(): iterable
    {
        yield 'int range' => ['int<0, 100>', ['0', '100']];
        yield 'open range' => ['int<1, max>', ['1']];
        yield 'positive-int' => ['positive-int', ['1', '100']];
        yield 'non-empty-string' => ['non-empty-string', ["'testor'"]];
    }

    /** @param list<string> $expected */
    #[DataProvider('refinedTypes')]
    public function testRefinedPhpDocTypesAreNotExhaustive(string $type, array $expected): void
    {
        $domain = $this->domainOf('return $p;', 'mixed $p', doc: "/** @param $type \$p */");

        $this->assertSame($expected, self::values($domain));
        $this->assertFalse($domain['exhaustive']);
    }

    public function testNonFinitePhpDocTypeIsIgnored(): void
    {
        $this->assertNull($this->domainOf('return $p;', 'array $p', doc: '/** @param array<string|int> $p */'));
    }

    public function testNonExhaustivePhpDocIsMergedWithCodeDomain(): void
    {
        $domain = $this->domainOf('return $p === 42;', 'int $p', doc: '/** @param positive-int $p */');

        $this->assertSame(['1', '100', '42'], self::values($domain));
        $this->assertSame('phpdoc+code', $domain['source']);
    }

    // ---- Side effects -----------------------------------------------------------------

    private function effects(array $sources, string $class): array
    {
        return array_map(fn (array $m) => $m['sideEffects'], $this->analyse($sources)[$class]['methods']);
    }

    public function testDirectAndTransitiveSideEffects(): void
    {
        $effects = $this->effects(['Svc.php' => <<<'PHP'
            namespace App;
            final class Svc
            {
                public function pure(string $s): string { return trim($s); }
                public function save(string $s): void { $this->write($s); }
                public function fetch(): string { return self::download(); }
                public function connect(): \PDO { return new \PDO('sqlite::memory:'); }
                public function stop(): never { exit(1); }
                private function write(string $s): void { file_put_contents('/tmp/x', $s); }
                private static function download(): string { return (string) \curl_exec(\curl_init('http://x')); }
            }
            PHP], 'App\Svc');

        $this->assertSame([], $effects['pure']);
        $this->assertSame(['file_put_contents'], $effects['save']);
        $this->assertSame(['curl_exec', 'curl_init'], $effects['fetch']);
        $this->assertSame(['new pdo'], $effects['connect']);
        $this->assertSame(['exit'], $effects['stop']);
    }

    public function testCallsOnOtherObjectsAreNotFollowed(): void
    {
        $effects = $this->effects(['Svc.php' => <<<'PHP'
            namespace App;
            final class Svc
            {
                public function __construct(private Repo $repo) {}
                public function run(): void { $this->repo->write(); }
                public function write(): void { unlink('/tmp/x'); }
            }
            PHP], 'App\Svc');

        $this->assertSame([], $effects['run']);
    }

    public function testSideEffectsOfInheritedMethodsInAnotherFile(): void
    {
        $effects = $this->effects([
            'Base.php' => <<<'PHP'
                namespace App;
                abstract class Base
                {
                    protected function writeFile(string $p): void { file_put_contents($p, ''); }
                    public function template(): void { $this->hook(); }
                    protected function hook(): void {}
                }
                PHP,
            'Child.php' => <<<'PHP'
                namespace App;
                final class Child extends Base
                {
                    public function export(): void { $this->writeFile('/tmp/x'); }
                    public function viaParent(): void { parent::hook(); }
                    protected function hook(): void { mail('a@b.c', 's', 'm'); }
                }
                PHP,
        ], 'App\Child');

        $this->assertSame(['file_put_contents'], $effects['export']);
        // Late static binding: Base::template() calls the overriding Child::hook().
        $this->assertSame(['mail'], $effects['template']);
        // parent::hook() is Base::hook(), which has no effect.
        $this->assertSame([], $effects['viaparent']);
    }

    public function testSideEffectsOfTraitMethods(): void
    {
        $effects = $this->effects([
            'Audits.php' => <<<'PHP'
                namespace App\Concerns;
                trait Audits
                {
                    private function audit(string $m): void { error_log($m); }
                }
                PHP,
            'Svc.php' => <<<'PHP'
                namespace App;
                use App\Concerns\Audits;
                final class Svc
                {
                    use Audits;
                    public function close(): bool { $this->audit('closing'); return true; }
                }
                PHP,
        ], 'App\Svc');

        $this->assertSame(['error_log'], $effects['close']);
    }

    // ---- Dependencies -----------------------------------------------------------------

    public function testCallsOnDependenciesAreCollectedAndFollowed(): void
    {
        $class = $this->analyse(['Svc.php' => <<<'PHP'
            namespace App;
            final class Svc
            {
                private Clock $clock;
                public function __construct(private Repo $repo, Clock $clock, Logger $logger)
                {
                    $this->clock = $clock;
                    $logger->info('created');
                }
                public function run(Mailer $mailer): void
                {
                    $mailer->send($this->repo->find(1));
                    $this->stamp();
                }
                private function stamp(): void { $this->clock?->now(); }
            }
            PHP])['App\Svc'];

        $this->assertSame(['repo' => 'repo', 'clock' => 'clock'], $class['ctorProperties']);
        $this->assertSame(
            ['property' => ['repo' => ['find'], 'clock' => ['now']], 'variable' => ['mailer' => ['send']]],
            $class['methods']['run']['calls'],
        );
        $this->assertSame(['property' => [], 'variable' => ['logger' => ['info']]], $class['methods']['__construct']['calls']);
        $this->assertSame([], $class['methods']['run']['sideEffects'], 'dependency calls are not side effects');
    }

    public function testInheritedConstructorPropertiesAreUsed(): void
    {
        $class = $this->analyse([
            'Base.php' => 'namespace App; abstract class Base { public function __construct(protected Repo $repo) {} }',
            'Child.php' => 'namespace App; final class Child extends Base { public function all(): array { return $this->repo->all(); } }',
        ])['App\Child'];

        $this->assertSame(['repo' => 'repo'], $class['ctorProperties']);
        $this->assertSame(['repo' => ['all']], $class['methods']['all']['calls']['property']);
    }

    public function testWhereDependencyReturnValuesGo(): void
    {
        $methods = $this->analyse(['Svc.php' => <<<'PHP'
            namespace App;
            use App\Rules\Policy;
            final class Svc
            {
                public function __construct(private Db $db) {}
                public function run(Repo $repo): string
                {
                    if ($this->db->query() === false) { return 'failed'; }
                    $version = $this->db->version();
                    if ($version >= 80000) { return 'new'; }
                    return Policy::check((int) $this->db->charset(), $repo->count()) ? 'ok' : $this->label($repo->name());
                }
                private function label(string $name): string { return $name; }
            }
            PHP])['App\Svc']['methods'];

        $this->assertSame([
            ['dep' => ['property', 'db', 'query'], 'items' => [['expr' => 'false', 'type' => 'bool']]],
            ['dep' => ['property', 'db', 'version'], 'items' => [
                ['expr' => '79999', 'type' => 'int'], ['expr' => '80000', 'type' => 'int'], ['expr' => '80001', 'type' => 'int'],
            ]],
            ['dep' => ['property', 'db', 'charset'], 'call' => ['App\Rules\Policy', 'check', 0]],
            ['dep' => ['variable', 'repo', 'count'], 'call' => ['App\Rules\Policy', 'check', 1]],
            ['dep' => ['variable', 'repo', 'name'], 'call' => ['App\Svc', 'label', 0]],
        ], $methods['run']['flows']);
        $this->assertSame(['name'], $methods['label']['paramNames']);
    }

    public function testPropertiesReadOnOtherObjects(): void
    {
        $class = $this->analyse(['Svc.php' => <<<'PHP'
            namespace App;
            final class Svc
            {
                private int $count = 0;
                public function run(Order $order): bool { return $order->status === 1 && $order?->paid && $this->count > 0; }
            }
            PHP])['App\Svc'];

        $this->assertSame(['status', 'paid'], $class['readProperties']);
    }

    public function testUnparsableFileIsSkipped(): void
    {
        $this->assertSame([], $this->analyse(['Broken.php' => 'final class {']));
    }
}
