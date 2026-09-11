<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;

/**
 * Dispatch: which command a name actually reaches.
 *
 * `lava list` can show a pack command while `lava <pack-command>` answers
 * `unknown_command` — the registered set and the dispatched set are two
 * different things, and only a test that RUNS the command proves they agree.
 * That gap is what these tests exist to keep closed: a command an agent can
 * see in `lava list` but cannot run is worse than one that is absent.
 */
final class ConsoleDispatchTest extends CommandTestCase
{
    public function testAPackCommandDispatches(): void
    {
        [$code, $envelope] = $this->json('commands-app', ['demo:ping']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('ok', $envelope['status']);
        self::assertSame('lava.demo:ping/1', $envelope['schema']);
        self::assertSame(['pong' => true], $envelope['data']);
    }

    public function testAnAppCommandDispatches(): void
    {
        [$code, $envelope] = $this->json('commands-app', ['app:report']);

        self::assertSame(ExitCode::Ok, $code);
        self::assertSame('lava.app:report/1', $envelope['schema']);
        self::assertStringEndsWith('commands-app', (string) $envelope['data']['app_dir']);
    }

    public function testEveryListedCommandIsAlsoDispatchable(): void
    {
        // The contract, stated directly: the two sets are the same set. A
        // fixture command that is listed but not dispatchable fails here with
        // the offending name, rather than in a user's terminal.
        [, $list] = $this->json('commands-app', ['list']);
        $names = array_column($list['data']['commands'], 'name');

        self::assertContains('demo:ping', $names);
        self::assertContains('app:report', $names);

        foreach ($names as $name) {
            [, $envelope] = $this->json('commands-app', [$name, '--help']);
            self::assertNotSame(
                'unknown_command',
                $envelope['problems'][0]['code'] ?? null,
                "lava list shows '{$name}' but lava {$name} does not dispatch it",
            );
        }
    }

    public function testANearMissSuggestsAPackCommand(): void
    {
        // `nearest` is computed over the booted app's registry, not just the
        // core set: a pack command the caller never knew existed is exactly
        // what a "did you mean" is for.
        [$code, $envelope] = $this->json('commands-app', ['demo:pin']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('unknown_command', $envelope['problems'][0]['code']);
        self::assertSame('demo:ping', $envelope['problems'][0]['context']['nearest']);
        self::assertStringContainsString('lava demo:ping', $envelope['problems'][0]['fix']);
    }

    public function testCoreCommandsStillRunOnAnAppThatCannotBoot(): void
    {
        [$code, $envelope] = $this->json('broken-wiring-app', ['routes']);

        self::assertSame(ExitCode::Failure, $code);
        self::assertNotSame('unknown_command', $envelope['problems'][0]['code']);
        self::assertContains('service_not_registered', array_column($envelope['problems'], 'code'));
    }

    public function testAnUnknownNameOnABrokenAppCarriesTheBootReport(): void
    {
        // A pack command only exists once the app boots, so a broken app
        // contributes none — and "no command named X" alone would read as a
        // typo when the real answer is "this app cannot boot". The unknown name
        // stays first, so the exit-2 usage contract is unchanged.
        [$code, $envelope] = $this->json('broken-wiring-app', ['nope']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame('unknown_command', $envelope['problems'][0]['code']);
        self::assertContains('service_not_registered', array_column($envelope['problems'], 'code'));
    }
}
