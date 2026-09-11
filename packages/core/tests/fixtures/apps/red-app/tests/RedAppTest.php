<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * red-app's suite: one green test and one red one, on purpose.
 *
 * A red suite is a NORMAL result — the app's own finding about the app — and
 * `lava test` has to report it as data with exit 1 while leaving `problems[]`
 * empty. A framework that turned a failing assertion into a `LavaProblem`
 * would be pronouncing on code it never read, and `lava check`'s tests section
 * would lose the distinction between "your test failed" and "the framework
 * could not run your tests".
 *
 * No boot, no App\ classes: the run itself is what this fixture exists for.
 */
final class RedAppTest extends TestCase
{
    public function testSomethingThatWorks(): void
    {
        self::assertSame(2, 1 + 1);
    }

    public function testSomethingThatDoesNot(): void
    {
        self::assertSame(200, 404, 'the fixture fails this on purpose');
    }
}
