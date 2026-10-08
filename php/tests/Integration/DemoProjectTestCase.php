<?php

declare(strict_types=1);

namespace Testor\Tests\Integration;

use PHPUnit\Framework\TestCase;

/** Runs against examples/php-demo (needs `composer install` there). */
abstract class DemoProjectTestCase extends TestCase
{
    protected static function demo(): string
    {
        return dirname(__DIR__, 3) . '/examples/php-demo';
    }

    protected function setUp(): void
    {
        if (!is_file(self::demo() . '/vendor/bin/phpunit')) {
            $this->markTestSkipped('run `composer install` in examples/php-demo first');
        }
    }
}
