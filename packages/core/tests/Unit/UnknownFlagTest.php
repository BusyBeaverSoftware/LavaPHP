<?php

declare(strict_types=1);

namespace Lava\Core\Tests\Unit;

use Lava\Core\Console\Args;
use Lava\Core\Console\Commands\ServeCommand;
use Lava\Core\Console\ExitCode;
use Lava\Core\Tests\Support\CommandTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An undeclared flag is refused, not ignored.
 *
 * `Args` accepts any `--flag` it is handed, and until this contract nothing
 * compared what arrived against what the command declared — so `lava routes
 * --strct` printed the route table and exited 0. A typo'd flag was the one
 * mistake this CLI swallowed silently, which is the failure mode the framework
 * bans everywhere else, and it is the mistake an agent — which has no muscle
 * memory for a flag list — makes most often.
 *
 * The check lives in the kernel, so these tests are about the kernel's two
 * obligations: refuse what the command does not declare, and keep accepting
 * what it does — including the flags the kernel itself reads, a pack's flags,
 * and `--` literals, which are arguments and not flags at all. That the
 * envelopes it emits this way obey the schema they name is the other half, and
 * it is checked for every command at once in {@see \Lava\Core\Tests\Schema\JsonSchemaTest}.
 */
final class UnknownFlagTest extends CommandTestCase
{
    public function testATypoedFlagIsAUsageErrorThatNamesTheFlagAndTheFix(): void
    {
        [$code, $envelope] = $this->json('ok-app', ['routes', '--strct']);

        self::assertSame(ExitCode::Usage, $code, 'an undeclared flag is about how the command was typed, not the app');
        self::assertSame(['bad_usage'], array_column($envelope['problems'], 'code'));

        $problem = $envelope['problems'][0];
        self::assertIsArray($problem);
        self::assertSame("Unknown flag '--strct'.", $problem['problem']);
        // The failing INPUT, which is the flag as typed.
        self::assertSame('strct', $problem['context']['flag']);
        self::assertSame('routes', $problem['context']['command']);
        // Both halves of the fix: the list to choose from, and the command that
        // prints it for THIS command.
        self::assertStringContainsString('lava routes --help', $problem['fix']);
        self::assertStringContainsString('--all', $problem['fix']);
    }

    public function testTheAcceptedListIsTheCommandsDeclaredFlagsAndNothingElse(): void
    {
        // Read off `lava list` rather than restated here: the table is the
        // command's own declaration, and a list hard-coded in this file could
        // agree with itself while disagreeing with the command.
        [, $listed] = $this->json('ok-app', ['list']);
        $declared = [];
        foreach ($listed['data']['commands'] as $row) {
            self::assertIsArray($row);
            if ($row['name'] === 'check') {
                $declared = $row['flags'];
            }
        }
        self::assertNotSame([], $declared, '`lava list` no longer reports `check`');

        [$code, $envelope] = $this->json('ok-app', ['check', '--strct']);
        self::assertSame(ExitCode::Usage, $code);

        sort($declared);
        self::assertSame($declared, $envelope['problems'][0]['context']['accepted']);

        // The universal flags are deliberately absent, and that is the point
        // rather than an omission: `--json`, `--quiet` and `--env` can never
        // reach this error (the kernel reads them for every command), and
        // `--help` is what the fix tells the caller to type. Padding the list
        // with them would hand a typo an answer that does not contain the flag
        // it meant.
        self::assertNotContains('quiet', $envelope['problems'][0]['context']['accepted']);
        self::assertNotContains('help', $envelope['problems'][0]['context']['accepted']);
    }

    public function testADeclaredFlagIsStillAccepted(): void
    {
        // The check must not cost a command its own flags — `routes` declares
        // `all`, and a run that uses it is an ordinary success.
        [$code] = $this->json('ok-app', ['routes', '--all']);

        self::assertSame(ExitCode::Ok, $code);
    }

    /** @return array<string, array{string}> */
    public static function universalFlags(): array
    {
        return [
            '--quiet' => ['--quiet'],
            '-q' => ['-q'],
            '--help' => ['--help'],
            '-h' => ['-h'],
            '--env' => ['--env=prod'],
            '--json' => ['--json'],
        ];
    }

    /**
     * `about` declares only `json` and `env`, so `--quiet` and `--help` are
     * undeclared on it and must still be accepted: the kernel is what reads
     * them, on every command's behalf, and a command that had to declare
     * them would be describing the kernel's work as its own.
     *
     * @param string $flag the flag as typed, long, short, or with a value
     */
    #[DataProvider('universalFlags')]
    public function testAFlagTheKernelReadsIsAcceptedByEveryCommand(string $flag): void
    {
        [$code, $envelope] = $this->json('ok-app', ['about', $flag]);

        self::assertNotSame(ExitCode::Usage, $code, "`{$flag}` is universal and must never be a usage error");
        self::assertNotContains('bad_usage', array_column($envelope['problems'], 'code'));
    }

    public function testALiteralArgumentAfterADoubleDashIsNotAFlag(): void
    {
        // `--` means "everything after this is positional", and the check reads
        // the PARSED flag map, so a literal can never be mistaken for a flag.
        // `describe` takes a selector, so `--strct` arrives as one and is looked
        // up: a different problem with a different fix, which is what shows the
        // flag check did not fire.
        $out = $this->text('ok-app', ['describe', '--', '--strct']);

        self::assertStringContainsString('unknown_selector', $out);
        self::assertStringNotContainsString('bad_usage', $out);
    }

    public function testEveryTypoedFlagIsReportedNotJustTheFirst(): void
    {
        // A report collects everything in one pass, and a caller that mistyped
        // twice should not have to run the command twice to hear about both.
        [, $envelope] = $this->json('ok-app', ['routes', '--strct', '--jsn']);

        self::assertSame(
            ["Unknown flag '--strct'.", "Unknown flag '--jsn'."],
            array_column($envelope['problems'], 'problem'),
        );
    }

    public function testARejectedInvocationCarriesTheCommandsDeclaredSeed(): void
    {
        // The envelope is emitted WITHOUT the command running, and
        // `lava.serve/1` requires all seven of its keys — so the kernel seeds
        // the shape the command declares. Asserted against that declaration
        // itself rather than against a list of keys copied into this file,
        // which would be a second shape to keep true.
        [$code, $envelope] = $this->json('ok-app', ['serve', '--strct']);

        self::assertSame(ExitCode::Usage, $code);
        self::assertSame((new ServeCommand())->emptyPayload(Args::parse([])), $envelope['data']);
    }

    public function testHelpIsAnsweredEvenWhenATypoAccompaniesIt(): void
    {
        // `--help` answers "what can I type here", which is the question the
        // typo was asking — and its answer is the list the typo needed. Failing
        // the help request would hide the answer behind the mistake.
        [$io, $stdout] = $this->io(json: false);
        $code = $this->isolated(
            fn (): int => $this->console('ok-app')->run(['lava', 'routes', '--strct', '--help'], $io),
            'ok-app',
        );

        self::assertSame(ExitCode::Ok, $code);
        $out = $this->contents($stdout);
        self::assertStringContainsString('lava routes', $out);
        self::assertStringNotContainsString('bad_usage', $out);
    }
}
