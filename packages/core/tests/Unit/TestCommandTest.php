<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;

/**
 * `lava test` — the app's own suite, reported as data.
 *
 * Two promises are load-bearing here. First, the result is structured (counts
 * and failing cases from the JUnit report, never scraped from PHPUnit's summary
 * line), so an agent can act on a failure without parsing prose. Second, a red
 * suite is NOT a framework problem: `problems[]` stays empty and only the exit
 * code and `status` go red, because the framework has no business pronouncing
 * on code it never read.
 */
final class TestCommandTest extends CommandTestCase
{
    public function testAGreenSuiteIsReportedAsStructuredData(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['test']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('ok', $envelope['status']);
        self::assertSame('lava.test/1', $envelope['schema']);
        self::assertSame([], $envelope['problems']);

        $tests = $envelope['data'];
        self::assertSame('ok-app', $tests['suite']);
        self::assertSame(['ok-app'], $tests['suites']);
        self::assertSame(3, $tests['tests']);
        self::assertSame(0, $tests['failures']);
        self::assertSame(0, $tests['errors']);
        // Passing cases are not listed: "why is this red" must not be buried.
        self::assertSame([], $tests['cases']);
    }

    public function testARedSuiteExitsOneWithoutBecomingAFrameworkProblem(): void
    {
        [$code, $envelope] = $this->json('red-app', ['test']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $envelope['status']);
        self::assertSame([], $envelope['problems']);

        $tests = $envelope['data'];
        self::assertSame(2, $tests['tests']);
        self::assertSame(1, $tests['failures']);
        self::assertSame(['testSomethingThatDoesNot'], array_column($tests['cases'], 'name'));

        // A failing case carries where it failed and what the assertion said —
        // the two things an agent needs to fix it in one round trip.
        $case = $tests['cases'][0];
        self::assertStringEndsWith('/tests/RedAppTest.php', (string) $case['file']);
        self::assertGreaterThan(0, $case['line']);
        self::assertStringContainsString('the fixture fails this on purpose', (string) $case['message']);
    }

    /**
     * The one failure mode an agent-first framework cannot have: a red suite
     * reported as green.
     *
     * PHPUnit writes a test class that throws in `setUpBeforeClass` as an EMPTY
     * `<testsuite>` — no `<testcase>`, no `<error>`, and the report's own totals
     * still read zero failures — while its console says ERRORS! and it exits 2.
     * `setup-error-app` is that shape, and it is not exotic: it is every DB test
     * class at once when the driver is missing.
     */
    public function testAClassThatErrorsAtSetupIsNotASilentPass(): void
    {
        [$code, $envelope] = $this->json('setup-error-app', ['test']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $envelope['status']);
        self::assertSame(['incomplete_test_report'], array_column($envelope['problems'], 'code'));

        $problem = $envelope['problems'][0];
        self::assertSame(2, $problem['context']['exit_code']);
        // The fix has to send the reader to PHPUnit's own output, because that
        // is the only place the error exists — the report will never show it.
        self::assertStringContainsString('php vendor/bin/phpunit', (string) $problem['fix']);
        self::assertStringContainsString('setUpBeforeClass', (string) $problem['fix']);

        // The payload keeps reporting what the REPORT contains. Rewriting the
        // counts to match the exit code would invent numbers PHPUnit never wrote
        // down; the problem is what says the report is incomplete.
        self::assertSame(1, $envelope['data']['tests']);
        self::assertSame(0, $envelope['data']['errors']);
        self::assertSame([], $envelope['data']['cases']);
    }

    public function testARunnerThatProducesNoReportIsADiagnosis(): void    {
        // PHPUnit exits 2 with "Test directory … not found" and writes an empty
        // report. The runner DID run, so this is not `missing_test_runner` — and
        // PHPUnit's own output is the only diagnosis available, so it travels in
        // the context rather than being swallowed.
        [$code, $envelope] = $this->json('bad-suite-app', ['test']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame(['bad_test_report'], array_column($envelope['problems'], 'code'));
        self::assertSame(2, $envelope['problems'][0]['context']['exit_code']);
        self::assertStringContainsString('not found', (string) $envelope['problems'][0]['context']['output']);
        self::assertStringContainsString('php vendor/bin/phpunit', (string) $envelope['problems'][0]['fix']);
    }

    public function testAMissingRunnerIsAProblemButThePayloadShapeSurvives(): void
    {
        // module-app has no suite and no vendor/, which is exactly what a fresh
        // app looks like before `composer install`. The envelope still carries
        // every key, so a `--json` consumer never branches on a shape.
        [$code, $envelope] = $this->json('module-app', ['test']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame(['missing_test_runner'], array_column($envelope['problems'], 'code'));
        self::assertSame(
            ['suite', 'suites', 'tests', 'failures', 'errors', 'skipped', 'assertions', 'time', 'cases'],
            array_keys($envelope['data']),
        );
        self::assertNull($envelope['data']['suite']);
        self::assertSame(0, $envelope['data']['tests']);
    }

    public function testAFilterIsAppliedToTheRun(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['test', '--filter=testHealth']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame(1, $envelope['data']['tests']);
        self::assertSame('ok-app', $envelope['data']['suite']);
    }

    public function testTheTextViewEchoesTheFilterAndSummarisesTheRun(): void
    {
        $text = $this->text('ok-app', ['test', '--filter=testHealth']);

        self::assertStringContainsString('Tests: 1, Assertions: 2', $text);
        self::assertStringContainsString('filter: testHealth', $text);
    }

    public function testTheTextViewListsFailingCasesOneLineEach(): void
    {
        // A failure message is a whole stack; the table cell must stay one line
        // or the table becomes unreadable exactly when it matters most.
        $text = $this->text('red-app', ['test']);

        self::assertStringContainsString('Tests: 2', $text);
        self::assertStringContainsString('RedAppTest', $text);
        self::assertStringContainsString('testSomethingThatDoesNot', $text);
        self::assertStringContainsString('failed', $text);
    }
}
