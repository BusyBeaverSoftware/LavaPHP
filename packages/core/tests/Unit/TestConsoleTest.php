<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestConsole;
use PHPUnit\Framework\TestCase;

/**
 * TestConsole is the consumer-facing twin of this repository's own command
 * harness: an app's commands, run in-process, with the envelope an agent reads.
 */
final class TestConsoleTest extends TestCase
{
    public function testAnAppsOwnCommandRunsAndReturnsItsEnvelope(): void
    {
        $appDir = TestApp::autoloadFixture('commands-app');

        $result = (new TestConsole($appDir))->json('app:report');

        self::assertSame(ExitCode::Ok, $result->exitCode(), $result->errors());
        self::assertSame('lava.app.report/1', $result->envelope()['schema']);
        self::assertSame($appDir, $result->data()['app_dir']);
        self::assertSame([], $result->problemCodes());
    }

    public function testTextModeIsWhatAPersonReadsAndHasNoEnvelope(): void
    {
        $result = (new TestConsole(TestApp::autoloadFixture('commands-app')))->run('app:report');

        self::assertSame(ExitCode::Ok, $result->exitCode());
        self::assertStringContainsString('app:report', $result->output());

        $this->expectException(\UnexpectedValueException::class);
        $result->envelope();
    }

    public function testAnAppThatCannotBootComesBackAsProblemsAndAFailure(): void
    {
        $result = (new TestConsole(TestApp::autoloadFixture('broken-wiring-app')))->json('routes');

        self::assertSame(ExitCode::Failure, $result->exitCode());
        self::assertContains('service_not_registered', $result->problemCodes());
    }

    public function testEachRunHasItsOwnEnvironmentAndLeavesNoneBehind(): void
    {
        $appDir = TestApp::autoloadFixture('env-app');
        $lavaEnvBefore = getenv('LAVA_ENV');
        self::assertFalse(getenv('APP_REGION'), 'precondition: nothing outside the run sets APP_REGION');

        $prod = (new TestConsole($appDir, ['LAVA_ENV' => 'prod']))->json('env');
        self::assertSame('prod', $prod->data()['resolved_env']);

        // env-app's config/.env sets APP_REGION, and booting promotes it into the
        // process environment. None of that may outlive the run, or the next
        // test would read it as though a shell had exported it.
        self::assertFalse(getenv('APP_REGION'));
        self::assertSame($lavaEnvBefore, getenv('LAVA_ENV'));
    }
}
