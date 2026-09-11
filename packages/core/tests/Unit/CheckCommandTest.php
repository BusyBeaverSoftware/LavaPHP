<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;

/**
 * `lava check` — the one-command verification loop, and the contract an agent's
 * tight loop depends on: one invocation, one envelope, one exit code, every
 * finding in a single fix-first list.
 *
 * The sections are the part that is easy to get subtly wrong, because a section
 * is a claim about WHERE the app is broken. These tests pin the claim: a
 * problem must land in the section that owns its code, the tests section must
 * merge "the suite is red" with "there is no runner", and a boot failure must
 * still produce all eight sections rather than a shorter list.
 */
final class CheckCommandTest extends CommandTestCase
{
    public function testAGreenAppChecksClean(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['check', '--no-tests']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('ok', $envelope['status']);
        self::assertSame([], $envelope['problems']);
        self::assertSame(
            ['boot', 'config', 'wiring', 'routes', 'features', 'env', 'commands', 'tests'],
            array_column($envelope['data']['sections'], 'name'),
        );
        // A skipped run says so, and says why — 'skipped' alone would leave an
        // agent wondering whether the suite passed or never ran.
        self::assertSame('skipped', $this->section($envelope, 'tests')['status']);
        self::assertSame('--no-tests', $this->section($envelope, 'tests')['detail']);
        self::assertNull($envelope['data']['tests']);
    }

    public function testTheCountsDescribeTheAppItJustBooted(): void
    {
        [, $envelope] = $this->json('ok-app', ['check', '--no-tests']);

        self::assertSame(
            ['routes' => 4, 'services' => 10, 'features' => 1, 'commands' => 11, 'middleware' => 1],
            $envelope['data']['counts'],
        );
    }

    public function testTheSuiteRunsAndItsResultIsItsOwnSection(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['check']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('ok', $this->section($envelope, 'tests')['status']);
        self::assertSame(0, $this->section($envelope, 'tests')['problems']);
        self::assertStringContainsString('Tests: 3', (string) $this->section($envelope, 'tests')['detail']);

        $tests = $envelope['data']['tests'];
        self::assertIsArray($tests);
        self::assertSame('ok-app', $tests['suite']);
        self::assertSame(3, $tests['tests']);
        self::assertSame([], $tests['cases']);
    }

    public function testARedSuiteFailsTheCommandWithoutBecomingAFrameworkProblem(): void
    {
        // The framework's own verdict is clean; the app's suite is not. Turning
        // the failure into a problem would have the framework pronounce on code
        // it never read, and would erase the difference between "your test
        // failed" and "I could not run your tests".
        [$code, $envelope] = $this->json('red-app', ['check']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame([], $envelope['problems']);
        self::assertSame('failed', $this->section($envelope, 'tests')['status']);
        self::assertSame(1, $this->section($envelope, 'tests')['problems']);
        self::assertSame(
            ['testSomethingThatDoesNot'],
            array_column($envelope['data']['tests']['cases'], 'name'),
        );
    }

    public function testAMissingRunnerIsATestFindingNotABootFinding(): void
    {
        // module-app boots green and has no suite. Filing the missing runner
        // under 'boot' would send an agent to read app/ files that are fine.
        [$code, $envelope] = $this->json('module-app', ['check']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('ok', $this->section($envelope, 'boot')['status']);
        self::assertSame('failed', $this->section($envelope, 'tests')['status']);
        self::assertSame('missing_test_runner', $this->section($envelope, 'tests')['detail']);
        self::assertSame(['missing_test_runner'], array_column($envelope['problems'], 'code'));
    }

    public function testQuickSkipsTheSuiteAndTheSweeps(): void
    {
        // --quick is the tight loop (<2s): boot's own findings, nothing that
        // requires resolving every definition or spawning PHPUnit.
        [$code, $envelope] = $this->json('env-app', ['check', '--quick']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertTrue($envelope['data']['quick']);
        self::assertNull($envelope['data']['tests']);
        self::assertSame('--quick', $this->section($envelope, 'tests')['detail']);
        self::assertSame([], $envelope['problems']);
    }

    public function testNoTestsSkipsOnlyTheSuite(): void
    {
        [$code, $envelope] = $this->json('env-app', ['check', '--no-tests']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('--no-tests', $this->section($envelope, 'tests')['detail']);
        // The env sweep still ran: a required var nothing reads is invisible to
        // boot, and this is the only command that looks.
        self::assertSame(['missing_env_var'], array_column($envelope['problems'], 'code'));
        self::assertSame('ok', $this->section($envelope, 'env')['status']);
        self::assertSame(1, $this->section($envelope, 'env')['problems']);
    }

    public function testStrictEscalatesAWarningToAFailure(): void
    {
        [$code, $envelope] = $this->json('env-app', ['check', '--no-tests', '--strict']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $envelope['status']);
        self::assertTrue($envelope['data']['strict']);
        self::assertSame('failed', $this->section($envelope, 'env')['status']);
        // The problem keeps its severity — the verdict is what changed. An
        // agent that matched on severity would otherwise see a 'warn' inside a
        // failed envelope and have no way to explain it.
        self::assertSame('warn', $envelope['problems'][0]['severity']);
    }

    public function testProblemsLandInTheSectionThatOwnsTheirCode(): void
    {
        [, $envelope] = $this->json('bad-routes-app', ['check', '--no-tests']);

        self::assertSame('ok', $this->section($envelope, 'boot')['status']);
        self::assertSame(4, $this->section($envelope, 'routes')['problems']);
        self::assertSame('bad_route_pattern, bad_handler, bad_middleware', $this->section($envelope, 'routes')['detail']);
        self::assertSame(1, $this->section($envelope, 'features')['problems']);
        self::assertSame('unknown_feature', $this->section($envelope, 'features')['detail']);
    }

    public function testRunnableFixesComeFirst(): void
    {
        // missing-pack-app's discovery order is missing_pack, invalid_gating,
        // then missing_test_runner — and the emitted order hoists BOTH 'Run:'
        // fixes above invalid_gating, which is the whole point: a fix that is a
        // command needs no judgement, so it is the one an agent can act on now.
        [, $envelope] = $this->json('missing-pack-app', ['check']);

        $fixes = array_column($envelope['problems'], 'fix');
        $runnable = array_filter($fixes, static fn (string $fix): bool => str_starts_with($fix, 'Run:'));
        self::assertCount(2, $runnable);
        self::assertSame(
            array_values($runnable),
            array_slice($fixes, 0, count($runnable)),
            'every problem whose fix is a command must precede every other problem',
        );
        self::assertSame('missing_pack', $envelope['problems'][0]['code']);
        self::assertSame('invalid_gating', $envelope['problems'][2]['code']);
    }

    public function testABootFailureStillReportsEverySection(): void
    {
        // The tests section survives a failed boot on purpose: a red suite is
        // often WHY the app cannot boot, so both findings have to arrive in one
        // report. Counts are zeroed rather than omitted — the keys stay stable.
        [$code, $envelope] = $this->json('broken-wiring-app', ['check']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertSame('failed', $this->section($envelope, 'boot')['status']);
        self::assertSame('failed', $this->section($envelope, 'wiring')['status']);
        self::assertSame('failed', $this->section($envelope, 'tests')['status']);
        self::assertSame('missing_test_runner', $this->section($envelope, 'tests')['detail']);
        self::assertSame(
            ['routes' => 0, 'services' => 0, 'features' => 0, 'commands' => 0, 'middleware' => 0],
            $envelope['data']['counts'],
        );
        self::assertContains('service_not_registered', array_column($envelope['problems'], 'code'));
    }

    public function testTheTextViewNamesEverySectionAndTheCounts(): void
    {
        $text = $this->text('ok-app', ['check', '--no-tests']);

        self::assertStringContainsString('routes', $text);
        self::assertStringContainsString('tests', $text);
        self::assertStringContainsString('routes: 4  services: 10  features: 1  commands: 11', $text);
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function section(array $envelope, string $name): array
    {
        $sections = $envelope['data']['sections'] ?? null;
        self::assertIsArray($sections);
        foreach ($sections as $section) {
            self::assertIsArray($section);
            if (($section['name'] ?? null) === $name) {
                return $section;
            }
        }
        self::fail("no '{$name}' section in the report");
    }
}
