<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\Junit;
use Lava\Core\Console\TestRun;
use Lava\Core\Problem\BadTestReport;
use PHPUnit\Framework\TestCase;

/**
 * JUnit XML → TestRun. This is the substrate `lava test` and `lava check` both
 * read, so it is tested against hand-written reports rather than only against
 * whatever PHPUnit happens to emit today: the shapes that matter here are the
 * ones PHPUnit documents, and a red suite must be readable from the report
 * alone.
 */
final class JunitTest extends TestCase
{
    public function testItReadsTheConfiguredSuiteNameNotTheConfigPath(): void
    {
        // The real wrapper PHPUnit writes: one synthetic testsuite named after
        // phpunit.xml.dist, with the configured suites inside it. Reporting the
        // wrapper would put a machine-specific path in the envelope.
        $run = Junit::parse($this->report(suite: 'ok-app'), 2);

        self::assertSame('ok-app', $run->suite);
        self::assertSame(['ok-app'], $run->suites);
    }

    public function testItCountsEveryOutcomeAndKeepsOnlyTheNonPassingCases(): void
    {
        $run = Junit::parse($this->report(suite: 'ok-app'), 2);

        self::assertSame(4, $run->tests);
        self::assertSame(1, $run->failures);
        self::assertSame(1, $run->errors);
        self::assertSame(1, $run->skipped);
        self::assertSame(3, $run->assertions);
        self::assertFalse($run->ok());

        // The green case is deliberately absent: an agent asking "why is this
        // red" must not have the failure buried under the passes.
        self::assertSame(['failed', 'error', 'skipped'], array_column($run->cases, 'status'));
        self::assertSame(['testUsers', 'testSlow', 'testSkipped'], array_column($run->cases, 'name'));
        // `testHealth` passed, so it is the one case absent from the list.
        self::assertSame('App\Tests\OkAppTest', $run->cases[0]['class']);
        self::assertSame('/app/tests/OkAppTest.php', $run->cases[0]['file']);
        self::assertSame(33, $run->cases[0]['line']);
        self::assertSame('PHPUnit\Framework\ExpectationFailedException', $run->cases[0]['type']);
        // The whole message travels, diff and all — trimming it to one line is
        // the text table's job, not the data's.
        self::assertStringStartsWith('Failed asserting that two strings are identical.', $run->cases[0]['message']);
        self::assertStringContainsString('--- Expected', $run->cases[0]['message']);
        self::assertSame('the database is not reachable', $run->cases[1]['message']);
    }

    public function testTheGreenCountIsDerivableWithoutASecondField(): void
    {
        $run = Junit::parse($this->report(suite: 'ok-app'), 2);

        self::assertSame(1, $run->tests - $run->failures - $run->errors - $run->skipped);
    }

    public function testSeveralConfiguredSuitesAreAllReportedAndNoneIsPicked(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="/app/phpunit.xml.dist" tests="2" assertions="2">
                <testsuite name="unit" tests="1" assertions="1">
                  <testcase name="testOne" class="App\Tests\UnitTest" assertions="1" time="0.001"/>
                </testsuite>
                <testsuite name="integration" tests="1" assertions="1">
                  <testcase name="testTwo" class="App\Tests\IntegrationTest" assertions="1" time="0.002"/>
                </testsuite>
              </testsuite>
            </testsuites>
            XML;

        $run = Junit::parse($xml, 0);

        // No single answer exists, so `suite` refuses to invent one.
        self::assertNull($run->suite);
        self::assertSame(['unit', 'integration'], $run->suites);
        self::assertSame(2, $run->tests);
    }

    public function testABareTestsuiteRootIsReadAsASuite(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuite name="core" tests="1" assertions="1">
              <testcase name="testOne" class="App\Tests\CoreTest" assertions="1" time="0.001"/>
            </testsuite>
            XML;

        $run = Junit::parse($xml, 0);

        self::assertSame('core', $run->suite);
        self::assertSame(1, $run->tests);
    }

    public function testAnEmptyReportIsAGreenRunWithNothingInIt(): void
    {
        $run = Junit::parse('<?xml version="1.0" encoding="UTF-8"?><testsuites/>', 0);

        self::assertSame([], $run->suites);
        self::assertNull($run->suite);
        self::assertSame(0, $run->tests);
        self::assertTrue($run->ok());
    }

    public function testMalformedXmlIsADiagnosisNotAnException(): void
    {
        // A truncated report is exactly what a crashed PHPUnit leaves behind,
        // and the agent has to be told that — with the parser's own line number.
        try {
            Junit::parse('<?xml version="1.0"?><testsuites><testsuite name="x">', 2);
            self::fail('a malformed report must throw');
        } catch (BadTestReport $problem) {
            self::assertSame('bad_test_report', $problem->code());
            self::assertStringContainsString('line', $problem->getMessage());
            self::assertStringContainsString('not well-formed XML', $problem->getMessage());
            self::assertStringContainsString('php vendor/bin/phpunit', $problem->fix);
            self::assertArrayHasKey('detail', $problem->context);
        }
    }

    public function testTheSummaryLineIsTheStableTextView(): void
    {
        // `lava check`'s tests section detail embeds this exact string, so it is
        // a contract, not a formatting choice.
        $run = Junit::parse($this->report(suite: 'ok-app'), 2);

        self::assertSame(
            'Tests: 4, Assertions: 3, Failures: 1, Errors: 1, Skipped: 1 (0.020s)',
            $run->summary(),
        );
        self::assertSame(
            ['suite', 'suites', 'tests', 'failures', 'errors', 'skipped', 'assertions', 'time', 'cases'],
            array_keys($run->json()),
        );
    }

    /**
     * The report this test reads is exactly what `--log-junit` writes for a test
     * class that throws in `setUpBeforeClass`: an empty `<testsuite>` for the
     * class, no `<testcase>` and no `<error>` for it, and totals that read zero
     * failures. PHPUnit's console says ERRORS! and it exits 2.
     *
     * A verdict built from this report alone calls the run green — which is how
     * `lava check --strict` came to report `ok` on a red suite. The exit code is
     * the half of the truth the XML does not carry.
     */
    public function testAnExitCodeTheReportCannotExplainIsNotGreen(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="/app/phpunit.xml.dist" tests="1" errors="0" failures="0">
                <testsuite name="demo" tests="1" errors="0" failures="0">
                  <testsuite name="App\Tests\GreenAppTest" tests="1" errors="0" failures="0">
                    <testcase name="testSomethingThatWorks" class="App\Tests\GreenAppTest" assertions="1" time="0.001"/>
                  </testsuite>
                  <testsuite name="App\Tests\SetupErrorTest" tests="0" errors="0" failures="0"/>
                </testsuite>
              </testsuite>
            </testsuites>
            XML;

        $run = Junit::parse($xml, 2);

        // Everything the report says is true, and the run is still red.
        self::assertSame(1, $run->tests);
        self::assertSame(0, $run->failures);
        self::assertSame(0, $run->errors);
        self::assertSame(2, $run->exitCode);
        self::assertTrue($run->unreportedFailure());
        self::assertFalse($run->ok());

        $problems = $run->problems();
        self::assertCount(1, $problems);
        self::assertSame('incomplete_test_report', $problems[0]->code());
        self::assertSame(2, $problems[0]->context['exit_code']);
        self::assertSame(1, $problems[0]->context['tests']);
        self::assertSame(0, $problems[0]->context['cases']);
        // The fix has to name the trap, because PHPUnit's own output is where
        // the error actually is and the report will never show it.
        self::assertStringContainsString('php vendor/bin/phpunit', $problems[0]->fix);
        self::assertStringContainsString('setUpBeforeClass', $problems[0]->fix);
    }

    /**
     * The other side of the same rule, and the one that keeps the framework out
     * of the app's business: when the report DOES describe the failure, the run
     * contributes no problems at all. `red-app` is this case, and it is why a
     * red suite leaves `problems[]` empty.
     */
    public function testACountedFailureIsTheAppsFindingAndNotTheFrameworks(): void
    {
        $run = Junit::parse($this->report(suite: 'ok-app'), 2);

        self::assertFalse($run->ok());
        self::assertFalse($run->unreportedFailure());
        self::assertSame([], $run->problems());
    }

    /**
     * A report shaped the way `--log-junit` writes one: a wrapper suite named
     * after the config file, one configured suite inside it, and one case of
     * each outcome.
     */
    private function report(string $suite): string
    {
        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="/app/phpunit.xml.dist" tests="4" assertions="5" errors="1" failures="1" skipped="1" time="0.020">
                <testsuite name="{$suite}" tests="4" assertions="5" errors="1" failures="1" skipped="1" time="0.020">
                  <testcase name="testHealth" class="App\\Tests\\OkAppTest" file="/app/tests/OkAppTest.php" line="21" assertions="2" time="0.005"/>
                  <testcase name="testUsers" class="App\\Tests\\OkAppTest" file="/app/tests/OkAppTest.php" line="33" assertions="1" time="0.004">
                    <failure type="PHPUnit\\Framework\\ExpectationFailedException">Failed asserting that two strings are identical.
            --- Expected
            +++ Actual</failure>
                  </testcase>
                  <testcase name="testSlow" class="App\\Tests\\OkAppTest" file="/app/tests/OkAppTest.php" line="44" assertions="0" time="0.010">
                    <error type="RuntimeException">the database is not reachable</error>
                  </testcase>
                  <testcase name="testSkipped" class="App\\Tests\\OkAppTest" file="/app/tests/OkAppTest.php" line="55" assertions="0" time="0.001">
                    <skipped>no driver installed</skipped>
                  </testcase>
                </testsuite>
              </testsuite>
            </testsuites>
            XML;
    }
}
