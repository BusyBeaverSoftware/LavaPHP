<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use App\ReplaceConsole\Greeter;
use Lava\Core\Console\ExitCode;
use Lava\Core\Testing\TestApp;
use Lava\Core\Testing\TestConsole;
use PHPUnit\Framework\TestCase;

/**
 * `TestConsole` takes `replace:` as `TestApp::boot()` does (Lava Notes round 1,
 * G6): the boot an app command makes for itself gets the test's substitutes.
 */
final class TestConsoleReplaceTest extends TestCase
{
    public function testACommandsOwnBootGetsTheSubstituteAndTheNextRunDoesNot(): void
    {
        $appDir = TestApp::autoloadFixture('console-replace-app');

        $faked = (new TestConsole($appDir, replace: [Greeter::class => new Greeter('hello from the fake')]))->json('app:greet');
        self::assertSame(ExitCode::Ok, $faked->exitCode(), $faked->output() . $faked->errors());
        self::assertSame('hello from the fake', $faked->data()['greeting']);

        $real = (new TestConsole($appDir))->json('app:greet');
        self::assertSame('hello from the real service', $real->data()['greeting'], 'A substitute lasts one run.');

        $underEnv = (new TestConsole($appDir, replace: [Greeter::class => new Greeter('hello under prod')]))->json('app:greet', '--env=prod');
        self::assertSame('hello under prod', $underEnv->data()['greeting'], '--env boots through the other path.');
    }

    public function testASubstituteForAnIdNothingRegistersIsBadReplacementInTheEnvelope(): void
    {
        $appDir = TestApp::autoloadFixture('console-replace-app');

        $result = (new TestConsole($appDir, replace: ['App\ReplaceConsole\Nothing' => new Greeter('x')]))->json('app:greet');

        // An app command exists only once the app boots, so a boot that fails is
        // reported as the command it could not find plus the reason, as for any
        // app that cannot boot — here, the replacement.
        self::assertSame(ExitCode::Usage, $result->exitCode());
        self::assertSame(['unknown_command', 'bad_replacement'], $result->problemCodes());
        self::assertSame([], $result->data(), 'An envelope for a command that was never found has no data keys (DECISIONS 265).');

        // A core command exists without the app, so it answers for itself: the
        // replacement is the envelope's first problem and the exit is 1 (R3-B18).
        $core = (new TestConsole($appDir, replace: ['App\ReplaceConsole\Nothing' => new Greeter('x')]))->json('routes');
        self::assertSame(ExitCode::Failure, $core->exitCode());
        self::assertSame(['bad_replacement'], $core->problemCodes());
        self::assertSame(['routes' => []], $core->data());
    }
}
