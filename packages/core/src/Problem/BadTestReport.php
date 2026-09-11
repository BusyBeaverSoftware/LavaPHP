<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * PHPUnit ran but produced no usable JUnit report — or produced one that is
 * not well-formed XML.
 *
 * This is distinct from "the tests failed": a failed test still writes a
 * report, and its failures are a normal, expected result. An ABSENT report
 * means PHPUnit itself never got as far as testing — a broken phpunit.xml, a
 * bootstrap that fatals, a bad `--filter` with no match under a strict config.
 * PHPUnit's own output is the only diagnosis available, so it travels in the
 * context rather than being swallowed.
 */
final class BadTestReport extends LavaProblem
{
    public static function empty(int $exitCode, string $output): self
    {
        return new self(
            "PHPUnit exited {$exitCode} without writing a JUnit report.",
            'Run the test runner directly to see its output: php vendor/bin/phpunit',
            [
                'exit_code' => $exitCode,
                // Bounded: a PHPUnit fatal can print pages, and a problem is
                // read by an agent, not paged through.
                'output' => self::trim($output),
            ],
        );
    }

    public static function malformed(string $detail): self
    {
        return new self(
            "The JUnit report PHPUnit wrote is not well-formed XML: {$detail}",
            'Re-run the suite: php vendor/bin/phpunit — a truncated report usually means PHPUnit was killed mid-run.',
            ['detail' => $detail],
        );
    }

    public function code(): string
    {
        return 'bad_test_report';
    }

    private static function trim(string $output): string
    {
        $output = trim($output);
        return strlen($output) > 2000 ? substr($output, 0, 2000) . "\n… (truncated)" : $output;
    }
}
