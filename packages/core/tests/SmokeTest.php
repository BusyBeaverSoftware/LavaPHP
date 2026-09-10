<?php

declare(strict_types=1);

namespace Lava\Core\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function test_php_floor_is_honored(): void
    {
        // lava/core requires PHP ^8.3; the CI matrix starts at 8.3.
        self::assertGreaterThanOrEqual(80300, PHP_VERSION_ID, 'lava/core requires PHP 8.3+');
    }
}