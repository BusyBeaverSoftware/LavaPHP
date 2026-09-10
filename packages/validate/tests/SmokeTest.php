<?php

declare(strict_types=1);

namespace Lava\Validate\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_php_floor_is_honored(): void
    {
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID, 'lava/validate requires PHP 8.3+');
    }
}