<?php

declare(strict_types=1);

namespace Testor\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Testor\Introspector;

#[CoversClass(Introspector::class)]
final class IntrospectorTest extends DemoProjectTestCase
{
    private static ?array $data = null;

    /** @return array<string, array> classes by name */
    private function classes(): array
    {
        self::$data ??= (new Introspector())->describe(self::demo(), glob(self::demo() . '/src/*/*.php'));
        return array_column(self::$data['classes'], null, 'name');
    }

    private function method(string $class, string $method): array
    {
        return array_column($this->classes()[$class]['methods'], null, 'name')[$method];
    }

    private function domainValues(string $class, string $method, ?string $param = null): array
    {
        $params = array_column($this->method($class, $method)['params'], null, 'name');
        $domain = $param === null ? reset($params)['domain'] : $params[$param]['domain'];
        return array_map(fn (array $v) => isset($v['const']) ? "{$v['const']}={$v['expr']}" : $v['expr'], $domain['values'] ?? []);
    }

    public function testReflectionDescribesKindsAndSignatures(): void
    {
        $classes = $this->classes();

        $this->assertSame('enum', $classes['App\Pricing\CustomerTier']['kind']);
        $this->assertSame('interface', $classes['App\Pricing\TaxRateProvider']['kind']);
        $this->assertSame('trait', $classes['App\Io\WritesAuditLog']['kind']);
        $this->assertNull($classes['App\Identity\Email']['constructor'], 'private constructor');
        $this->assertTrue($this->method('App\Identity\Email', 'from')['factory']);

        $tier = $this->method('App\Pricing\PriceCalculator', 'discountFor')['params'][0]['type'];
        $this->assertSame(['Standard', 'Premium', 'Vip'], self::$data['types'][$tier['name']]['enumCases']);
    }

    public function testTypeRegistryDescribesDependenciesAndHowToBuildObjects(): void
    {
        $this->classes();
        $types = self::$data['types'];

        $this->assertSame('App\Orders\Order', $types['App\Orders\OrderRepository']['methods']['find']['returnType']['name']);
        $this->assertSame(['id', 'amount', 'status'], array_column($types['App\Orders\Order']['constructor'], 'name'));
        $this->assertSame([], $types['App\Orders\Order']['methods'], 'final classes are built, not stubbed');
    }

    public function testReturnValueDomainsFollowArgumentsIntoOtherClasses(): void
    {
        $domain = $this->method('App\Orders\CheckoutService', 'shippingLabel')['returnDomains']['property']['basket']['itemcount'];

        $this->assertSame(['2', '3', '4'], array_column($domain['values'], 'expr'), 'from ShippingPolicy::isFree(): $items >= 3');
    }

    public function testDefaultsAndReadPropertiesForObjectVariants(): void
    {
        $this->classes();
        $status = array_column(self::$data['types']['App\Orders\Order']['constructor'], null, 'name')['status'];

        $this->assertSame(['kind' => 'enum', 'case' => 'Pending'], $status['default']);
        $this->assertContains('status', $this->classes()['App\Orders\OrderService']['readProperties']);
    }

    public function testDependencyCallsAndConstructorPropertiesAreAttached(): void
    {
        $classes = $this->classes();

        $this->assertSame(['orders' => 'orders', 'payments' => 'payments'], $classes['App\Orders\OrderService']['ctorProperties']);
        $this->assertSame(
            ['orders' => ['find'], 'payments' => ['charge', 'fee']],
            $this->method('App\Orders\OrderService', 'pay')['calls']['property'],
        );
    }

    public function testPublicConstantsAreResolvedWithTheirName(): void
    {
        $this->assertSame(
            ["LEVEL_INFO='info'", "LEVEL_WARNING='warning'", "LEVEL_ERROR='error'"],
            $this->domainValues('App\Report\Formatter', 'cssClass'),
        );
    }

    public function testPrivateConstantsAreResolvedToLiterals(): void
    {
        $this->assertSame(['200', '201', '204'], $this->domainValues('App\Report\Formatter', 'isSuccess'));
    }

    public function testCodeDomainsAreAttached(): void
    {
        $this->assertSame(['89', '90', '91', '74', '75', '76'], $this->domainValues('App\Report\Formatter', 'grade'));
        $this->assertSame(["'asc'", "'desc'"], $this->domainValues('App\Report\Formatter', 'sortLabel'));
    }

    public function testSideEffectsFollowParentsAndTraits(): void
    {
        $this->assertSame(['file_put_contents'], $this->method('App\Io\CsvExporter', 'export')['sideEffects']);
        $this->assertSame([], $this->method('App\Io\CsvExporter', 'toCsv')['sideEffects']);
        $this->assertSame(['error_log'], $this->method('App\Io\AccountService', 'close')['sideEffects']);
        $this->assertSame(['file_put_contents'], $this->method('App\Io\ReportWriter', 'save')['sideEffects']);
    }
}
