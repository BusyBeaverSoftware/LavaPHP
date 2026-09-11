<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The green half of this fixture's suite.
 *
 * It exists so the JUnit report has something in it: the finding under test is
 * a report that looks clean while the runner exits 2, and a report with no cases
 * at all would be a different problem (`bad_test_report`). One passing test is
 * what makes the report believable and the exit code the only tell.
 */
final class GreenAppTest extends TestCase
{
    public function testSomethingThatWorks(): void
    {
        self::assertSame(2, 1 + 1);
    }
}
