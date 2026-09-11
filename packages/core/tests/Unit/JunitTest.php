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
        $run = Junit::parse($this->report(suite: 'ok-app'));

        self::assertSame('ok-app', $run->suite);
        self::assertSame(['ok-app'], $run->suites);
    }

    public function testItCountsEveryOutcomeAndKeepsOnlyTheNonPassingCases(): void
    {
        $run = Junit::parse($this->report(suite: 'ok-app'));

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
        $run = Junit::parse($this->report(suite: 'ok-app'));

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

        $run = Junit::parse($xml);

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

        $run = Junit::parse($xml);

        self::assertSame('core', $run->suite);
        self::assertSame(1, $run->tests);
    }

    public function testAnEmptyReportIsAGreenRunWithNothingInIt(): void
    {
        $run = Junit::parse('<?xml version="1.0" encoding="UTF-8"?><testsuites/>');

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
            Junit::parse('<?xml version="1.0"?><testsuites><testsuite name="x">');
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
        $run = Junit::parse($this->report(suite: 'ok-app'));

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
