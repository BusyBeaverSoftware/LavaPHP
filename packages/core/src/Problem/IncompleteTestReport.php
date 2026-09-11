<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * PHPUnit's exit code says something went wrong; the JUnit report does not say
 * what.
 *
 * `--log-junit` has a hole, and it is a quiet one. A test class that fails in
 * `setUpBeforeClass` is written to the report as an EMPTY `<testsuite>` — no
 * `<testcase>`, no `<error>`, and the file's own totals still read zero
 * failures. PHPUnit's console prints "ERRORS!" and it exits 2, but a reader that
 * trusts the XML alone sees a clean run.
 *
 * That is not a corner case, it is the shape of an app whose tests touch a
 * database: lose the driver and every DB test class errors at setup at once,
 * silently. Without this problem `lava test` and `lava check` report a green
 * suite and exit 0 while the suite is red — the exact silent pass this framework
 * exists to prevent.
 *
 * It sits beside {@see BadTestReport}, and the two are separated on purpose
 * because they need different fixes. `bad_test_report` means there was nothing
 * to read (no report, or malformed XML) and PHPUnit's own output is the whole
 * diagnosis. This one means there WAS a readable report, it simply does not
 * describe everything the runner's exit code knows — so the fix is to run the
 * runner directly and read the output the report omitted.
 */
final class IncompleteTestReport extends LavaProblem
{
    public static function of(int $exitCode, int $tests, int $cases): self
    {
        return new self(
            "PHPUnit exited {$exitCode} but its JUnit report lists no failures and no errors "
            . "({$tests} tests, {$cases} non-passing cases).",
            'Run the suite directly to see what the report left out: php vendor/bin/phpunit — '
            . 'a test class that errors in setUpBeforeClass is written to --log-junit as an empty '
            . '<testsuite>, with no <testcase> and no <error> to read.',
            ['exit_code' => $exitCode, 'tests' => $tests, 'cases' => $cases],
        );
    }

    public function code(): string
    {
        return 'incomplete_test_report';
    }
}
