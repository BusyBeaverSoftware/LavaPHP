<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\IO;
use Lava\Core\Problem\DuplicateCommand;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Testing\TestApp;
use PHPUnit\Framework\TestCase;

/**
 * The boot step that assembles the command set from three sources — core, the
 * enabled packs, and the app's own `app/Commands.php` — and refuses to let two
 * of them claim the same name.
 *
 * A collision is the one failure here that is invisible without a check: the
 * later registration would simply win, `lava routes` would quietly mean
 * something else in this app than in every other, and nothing anywhere would
 * say so. That is why it is Fatal, and why the report names both sides.
 */
final class RegisterCommandsTest extends TestCase
{
    public function testCorePackAndAppCommandsAllReachTheRegistry(): void
    {
        $app = $this->booted('commands-app');
        $packs = $this->packsByName($app->commands());

        self::assertSame('lava/demo-pack', $packs['demo:ping'] ?? null);
        self::assertSame('app', $packs['app:report'] ?? null);
        self::assertSame('core', $packs['routes'] ?? null);

        // Core, then the packs, then the app: the app is registered last so it
        // wins a name it wants, and registration order is the order `lava list`
        // prints. Pinning the tail is enough to catch a reordered step.
        $names = array_map(static fn (Command $c): string => $c->name(), $app->commands()->all());
        self::assertSame(
            count(CommandRegistry::core()->all()) + 2,
            count($names),
            'the app and pack commands must be added to the core set, not replace it',
        );
        self::assertSame(['demo:ping', 'app:report'], array_slice($names, -2));
    }

    public function testADuplicateNameIsReportedForEveryCollidingSource(): void
    {
        // Two independent collisions on purpose: the pack takes `routes`, the
        // app takes `services`. Both must be reported — a failed registration
        // that stopped the next source would hide the app's own mistake.
        $failure = $this->failure('dup-command-app');
        $problems = array_values(array_filter(
            $failure->problems->problems(),
            static fn (LavaProblem $p): bool => $p->code() === 'duplicate_command',
        ));

        self::assertCount(2, $problems);
        self::assertSame('routes', $problems[0]->context['name']);
        self::assertSame('core', $problems[0]->context['existing_pack']);
        self::assertSame('lava/demo-pack', $problems[0]->context['incoming_pack']);
        self::assertSame('services', $problems[1]->context['name']);
        self::assertSame('app', $problems[1]->context['incoming_pack']);
    }

    public function testAWrongShapedCommandsFileIsAnInvalidConfigProblem(): void
    {
        $failure = $this->failure('bad-commands-app');
        $problem = $failure->problems->problems()[0];

        self::assertSame('invalid_config', $problem->code());
        self::assertSame('app/Commands.php', $problem->context['file']);
        // file:line is part of the contract — the reader must not have to guess
        // which file in the app is the wrong shape. `context.file` is the
        // app-relative name to show; `source.file` is the absolute path to open.
        self::assertStringEndsWith('/app/Commands.php', (string) $problem->source?->file);
        self::assertStringStartsWith('/', (string) $problem->source?->file);
        self::assertStringContainsString('return function (CommandRegistry $commands): void', $problem->fix);
    }

    public function testTheFirstRegistrationWinsAndTheSecondIsDropped(): void
    {
        // The registry's half of the rule: the collision is loud, and the
        // incumbent is what stays reachable — never silent shadowing.
        $registry = CommandRegistry::core();
        $incumbent = $registry->get('routes');

        try {
            $registry->add($this->claiming('routes', 'lava/demo-pack'));
            self::fail('a duplicate name must throw');
        } catch (DuplicateCommand $problem) {
            self::assertSame('duplicate_command', $problem->code());
            self::assertSame('core', $problem->context['existing_pack']);
            self::assertSame('lava/demo-pack', $problem->context['incoming_pack']);
        }

        self::assertSame($incumbent, $registry->get('routes'));
    }

    public function testTwoCommandsInTheSamePackDoNotNameThePackTwice(): void
    {
        // A command that never overrode pack() claims 'core', so both sides of
        // the collision can read the same. "provided by core; core provides it
        // too" is a message about a bug in the message, and the fix has to
        // point at the likeliest cause instead.
        try {
            CommandRegistry::core()->add($this->claiming('routes', 'core'));
            self::fail('a duplicate name must throw');
        } catch (DuplicateCommand $problem) {
            self::assertStringNotContainsString('core provides it too', $problem->getMessage());
            self::assertStringContainsString('override pack()', $problem->fix);
        }
    }

    private function booted(string $fixture): App
    {
        $boot = TestApp::bootFixture($fixture);
        self::assertInstanceOf(App::class, $boot, $fixture . ' must boot');
        return $boot;
    }

    private function failure(string $fixture): BootFailure
    {
        $boot = TestApp::bootFixture($fixture);
        self::assertInstanceOf(BootFailure::class, $boot, $fixture . ' must fail to boot');
        return $boot;
    }

    /** @return array<string, string> command name => pack */
    private function packsByName(CommandRegistry $registry): array
    {
        $packs = [];
        foreach ($registry->all() as $command) {
            $packs[$command->name()] = $command->pack();
        }
        return $packs;
    }

    private function claiming(string $name, string $pack): Command
    {
        return new class($name, $pack) extends Command {
            public function __construct(private readonly string $claimed, private readonly string $owner)
            {
            }

            public function name(): string
            {
                return $this->claimed;
            }

            public function summary(): string
            {
                return 'Claims a name that is already taken.';
            }

            public function pack(): string
            {
                return $this->owner;
            }

            public function run(IO $io, Args $args, string $appDir): int
            {
                return $io->emit($this->claimed);
            }
        };
    }
}
