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
        self::assertSame($suggestion, $problems[0]->context['suggestion']);

        if ($suggestion !== null) {
            self::assertStringContainsString("'{$suggestion}'", $problems[0]->fix);
            // The suggestion is itself a name nothing warns about.
            $renamed = new CommandRegistry();
            $renamed->add(self::command($suggestion));
            self::assertSame([], $renamed->nameProblems());
        }
    }

    public function testASuggestionAnotherCommandAlreadyHasIsNotMade(): void
    {
        // Lava Notes (R2-B9): `blog:publish_due` was told to become
        // `blog:publish:due`, which the app already had. Following the fix raised
        // duplicate_command, which is fatal on every boot — the very outcome the
        // warning exists to avoid.
        $registry = new CommandRegistry();
        $registry->add(self::command('blog:publish:due'));
        $registry->add(self::command('blog:publish_due'));

        $problems = $registry->nameProblems();

        self::assertCount(1, $problems);
        self::assertNull($problems[0]->context['suggestion']);
        self::assertStringNotContainsString("Rename it to 'blog:publish:due'", $problems[0]->fix);
        self::assertStringContainsString("'blog:publish:due' is taken", $problems[0]->fix);
    }

    public function testTwoNamesWithTheSameNearestNameAreNotBothOfferedIt(): void
    {
        $registry = new CommandRegistry();
        $registry->add(self::command('Report:Daily'));
        $registry->add(self::command('report daily'));

        $problems = $registry->nameProblems();

        self::assertSame('report:daily', $problems[0]->context['suggestion']);
        self::assertNull($problems[1]->context['suggestion']);
    }

    public function testANameOutsideAsciiGetsNoSuggestion(): void
    {
        // Dropping the bytes of `é` turned `café:list` into `caf:list`, which is
        // a different word rather than the nearest valid name.
        $registry = new CommandRegistry();
        $registry->add(self::command('café:list'));

        $problems = $registry->nameProblems();

        self::assertNull($problems[0]->context['suggestion']);
        self::assertStringNotContainsString('caf:list', $problems[0]->fix);
    }

    public function testACommandTheAppRegisteredReadsAsTheAppsAndPointsAtItsClass(): void
    {
        // `(from core)` with a null source, for a command in app/Commands.php,
        // sent the reader to the framework.
        $registry = CommandRegistry::core();
        $registry->addingFor('app', static function (CommandRegistry $commands): void {
            $commands->add(self::command('blog:publish_due'));
        });

        $problems = $registry->nameProblems();

        self::assertCount(1, $problems);
        self::assertStringContainsString('(from app)', $problems[0]->getMessage());
        self::assertSame('app', $problems[0]->context['pack']);
        self::assertSame(__FILE__, $problems[0]->source?->file);
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
