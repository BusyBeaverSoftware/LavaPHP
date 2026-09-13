<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Envelope;
use Lava\Core\Console\IO;
use Lava\Core\Problem\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A command's name becomes the contract id of every envelope it emits, so a name
 * that id cannot hold is reported with the rename.
 *
 * Found by an outside build (Lava Notes, B9): `blog:publish-due` ran, and every
 * `--json` envelope it emitted claimed `lava.blog.publish-due/1`, which fails the
 * envelope's own `schema` pattern. Reported as a WARNING rather than refused:
 * commands register on every boot, and the same build's `blog:create-user` would
 * otherwise have taken its website down on upgrade.
 */
final class CommandNameTest extends TestCase
{
    /** @return array<string, array{string, string|null}> */
    public static function invalidNames(): array
    {
        return [
            'a hyphen' => ['blog:publish-due', 'blog:publish:due'],
            'uppercase' => ['Report:Daily', 'report:daily'],
            'an underscore' => ['report:daily_stats', 'report:daily:stats'],
            'a space' => ['send mail', 'send:mail'],
            'an empty word' => ['blog::publish', 'blog:publish'],
            'a leading digit' => ['9lives', null],
        ];
    }

    #[DataProvider('invalidNames')]
    public function testANameTheContractIdCannotHoldIsAWarningWithTheRename(string $name, ?string $suggestion): void
    {
        $registry = new CommandRegistry();
        $registry->add(self::command($name));

        // Still registered: the command runs, and the warning says what to rename.
        self::assertTrue($registry->has($name));
        $problems = $registry->nameProblems();
        self::assertCount(1, $problems);
        self::assertSame('invalid_command_name', $problems[0]->code());
        self::assertSame(Severity::Warn, $problems[0]->severity());
        self::assertSame($name, $problems[0]->context['name']);

        if ($suggestion !== null) {
            self::assertStringContainsString("'{$suggestion}'", $problems[0]->fix);
            // The suggestion is itself a name nothing warns about.
            $renamed = new CommandRegistry();
            $renamed->add(self::command($suggestion));
            self::assertSame([], $renamed->nameProblems());
        }
    }

    public function testEveryCoreCommandNameIsValidAndMakesAValidContractId(): void
    {
        $core = CommandRegistry::core();

        self::assertSame([], $core->nameProblems());
        foreach ($core->all() as $command) {
            self::assertMatchesRegularExpression(
                '/^lava\.[a-z][a-z0-9.]*\/[0-9]+$/',
                Envelope::schema($command->name()),
            );
        }
    }

    private static function command(string $name): Command
    {
        return new class ($name) extends Command {
            public function __construct(private readonly string $commandName)
            {
            }

            public function name(): string
            {
                return $this->commandName;
            }

            public function summary(): string
            {
                return 'A command under test.';
            }

            public function run(IO $io, Args $args, string $appDir): int
            {
                return 0;
            }
        };
    }
}
