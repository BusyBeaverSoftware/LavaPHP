<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The red half — and the whole reason this fixture exists.
 *
 * `setUpBeforeClass` throws, which is what a test class does when it boots
 * something it cannot boot: a database whose driver is missing, a fixture file
 * that was deleted, a service that needs an env var. PHPUnit prints "ERRORS!"
 * and exits 2, and it writes this class to `--log-junit` as an EMPTY
 * `<testsuite>` — no `<testcase>`, no `<error>`, and the report's own totals
 * still read zero failures.
 *
 * So the report says green and the runner says red. A verdict built from the
 * report alone calls this run clean, which is the silent pass the framework must
 * not produce: `lava check --strict` would say `ok` on a red suite.
 */
final class SetupErrorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        throw new \RuntimeException('the fixture cannot set itself up, on purpose');
    }

    public function testSomethingThatNeverRuns(): void
    {
        self::assertTrue(false, 'unreachable: setUpBeforeClass threw');
    }
}
