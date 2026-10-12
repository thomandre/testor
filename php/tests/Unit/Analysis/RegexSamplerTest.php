<?php

declare(strict_types=1);

namespace Testor\Tests\Unit\Analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Testor\Analysis\RegexSampler;

#[CoversClass(RegexSampler::class)]
final class RegexSamplerTest extends TestCase
{
    /** @return iterable<array{string}> */
    public static function supported(): iterable
    {
        yield 'code' => ['/^[A-Z]{2}\d{4}$/'];
        yield 'e-mail' => ['/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i'];
        yield 'other delimiter' => ['#^\d{4}-\d{2}-\d{2}$#'];
        yield 'alternation and groups' => ['/^(?:\+33|0)[1-9](\d{2}){4}$/'];
        yield 'negated class' => ['/^[^@\s]+@[^@\s]+$/'];
        yield 'named group' => ['/(?<year>\d{4})-(?P<month>\d{2})/'];
        yield 'optional parts' => ['/^v?(\d+)\.(\d+)(?:\.(\d+))?$/'];
        yield 'posix class' => ['/^[[:alpha:]]+$/'];
        yield 'unicode property' => ['/^\p{Lu}\w*$/u'];
        yield 'lookahead' => ['/^(?=.*\d)[a-z\d]{8,}$/'];
    }

    #[DataProvider('supported')]
    public function testSampleMatchesThePattern(string $pattern): void
    {
        $sample = RegexSampler::sample($pattern);

        $this->assertNotNull($sample);
        $this->assertMatchesRegularExpression($pattern, $sample);
    }

    public function testUnsupportedPatternsGiveNull(): void
    {
        $this->assertNull(RegexSampler::sample('/(a)\1/'), 'back-reference');
        $this->assertNull(RegexSampler::sample('/a b # comment/x'), 'extended syntax');
        $this->assertNull(RegexSampler::sample('not a pattern'));
    }
}
