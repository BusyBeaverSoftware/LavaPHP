<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Problem\BadUsage;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnexpectedFailure;
use Lava\Core\Problem\UnknownCommand;

/**
 * The CLI kernel: turn argv into a command, run it, and let IO render the
 * result. Everything else in the console layer is a leaf — this is the only
 * place that reads argv, resolves a name, and maps a result to an exit code.
 *
 * It is also the only place that checks an invocation against the flags the
 * command declares. That belongs here rather than in each command for the same
 * reason the problem-report catch does: a pack command written tomorrow is
 * covered by this file without its author knowing to write the check, and a
 * check that each command must remember is one that the next command forgets.
 *
 * `main()` is the whole entry point `bin/lava` needs; the app directory
 * defaults to the current working directory, so `lava` runs from an app root
 * exactly like composer does.
 */
final class Console
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly string $appDir,
    ) {
    }

    /**
     * @param list<string> $argv the process argv (argv[0] is the script path)
     */
    public static function main(array $argv, ?string $appDir = null): int
    {
        return (new self(CommandRegistry::core(), $appDir ?? (getcwd() ?: '.')))->run($argv);
    }

    /**
     * @param list<string> $argv
     * @param IO|null $io injected by tests; built from `--json`/`--quiet` otherwise
     */
    public function run(array $argv, ?IO $io = null): int
    {
        $tokens = array_slice($argv, 1);

        // The command name is the first token that isn't a flag, so bare
        // `lava --json` lists, and `lava routes --json` routes.
        $name = 'list';
        if (isset($tokens[0]) && !str_starts_with($tokens[0], '-')) {
            $name = (string) array_shift($tokens);
        }

        $args = Args::parse($tokens);
        $io ??= IO::standard($args->bool('json'), $args->bool('quiet'));

        // A pack or app command is a BOOT DECISION: it exists only once
        // app/Modules.php and app/Commands.php have run. So a name the core
        // set doesn't know is the one case worth paying a boot for — core
        // commands stay on the fast path and read no app files at all.
        //
        // Booting here costs a second boot for a pack command that is itself
        // an AppCommand (it boots again to inspect). That is the price of
        // keeping `run(IO, Args, string $appDir)` the whole command contract;
        // the alternative is a command that can be handed a half-built app.
        $registry = $this->registry;
        $failure = null;

        $command = $registry->get($name);
        if ($command === null) {
            $boot = AppBoot::boot($this->appDir, $args->value('env'));
            if ($boot instanceof App) {
                $registry = $boot->commands();
                $command = $registry->get($name);
            } else {
                $failure = $boot;
            }
        }

        if ($command === null) {
            return $this->unknownCommand($io, $name, $registry, $failure);
        }

        // Seed the command's declared payload shape before deciding anything,
        // because two of the envelopes emitted below are emitted WITHOUT the
        // command ever running: `--help`, and an invocation rejected for a flag
        // the command does not declare. Both claim `lava.<cmd>/N`, and that
        // schema requires its `data` keys on every exit path — so the shape has
        // to be in place before either branch. The command seeds the same shape
        // itself; the values are identical and the writes are idempotent, so
        // which seed comes first cannot change the output.
        foreach ($command->emptyPayload($args) as $key => $value) {
            $io->data($key, $value);
        }

        if ($args->bool('help')) {
            $io->line($command->usage());
            $io->line($command->summary());
            return $io->emit($name);
        }

        // A flag the command does not declare used to be parsed by `Args`,
        // handed to the command, and never asked for — so `lava routes --strct`
        // printed the route table and exited 0. A silently ignored flag is the
        // one mistake this CLI would otherwise swallow, and it is a mistake an
        // agent makes, so it is a usage error: it is about how the command was
        // typed, not about the app. Every flag is therefore declared by the
        // command or read by the kernel ({@see Command::UNIVERSAL_FLAGS}).
        $unknown = $this->unknownFlags($command, $args);
        if ($unknown !== []) {
            $report = new ProblemReport();
            foreach ($unknown as $flag) {
                $report->add(BadUsage::unknownFlag($flag, $name, $command->flags()));
            }
            $io->emit($name, $report);
            return ExitCode::Usage;
        }

        // A command that throws is still a report, never a stack trace. This
        // is the single dispatch point, so every command — including pack
        // commands that don't exist yet — inherits the guarantee for free,
        // exactly as Kernel does for boot steps. Whatever the command already
        // wrote is kept: a partial table plus the reason it stopped is more
        // useful than either alone.
        try {
            return $command->run($io, $args, $this->appDir);
        } catch (LavaProblem $problem) {
            $report = new ProblemReport();
            $report->add($problem);
            return $io->emit($name, $report);
        } catch (\Throwable $throwable) {
            $report = new ProblemReport();
            $report->add(UnexpectedFailure::inCommand($name, $throwable));
            return $io->emit($name, $report);
        }
    }

    /**
     * The flags in this invocation that neither the command declares nor the
     * kernel reads, in the order they were typed.
     *
     * Read off the parsed flag map, not re-scanned from argv, so `--` literal
     * arguments are positional by the time this runs and a flag after it is
     * never mistaken for one. Pack-defined flags come through the same door as
     * core ones: a command that declares `batches` accepts `--batches`.
     *
     * @return list<string>
     */
    private function unknownFlags(Command $command, Args $args): array
    {
        return array_values(array_diff(
            array_keys($args->flags()),
            $command->flags(),
            Command::UNIVERSAL_FLAGS,
        ));
    }

    /**
     * `nearest` is computed over the widest command set we managed to see —
     * the booted app's when it booted, the core set when it did not — so the
     * hint can name a pack command the caller never knew existed.
     */
    private function unknownCommand(
        IO $io,
        string $name,
        CommandRegistry $registry,
        ?BootFailure $failure = null,
    ): int {
        $report = new ProblemReport();
        $report->add(UnknownCommand::of($name, $registry->nearest($name)));

        // A name we could not resolve may still exist: pack commands only
        // appear once the app boots, and an app that cannot boot contributes
        // none. When the boot is what stopped us, its report rides along —
        // "it is unreachable right now" and "you typed it wrong" need
        // different fixes, and the first problem stays the unknown name so
        // the usage contract is unchanged.
        if ($failure !== null) {
            $report->merge($failure->problems);
        }

        $io->emit($name, $report);

        // A bad invocation is a usage error, distinct from a command that ran
        // and failed — an agent can tell "you typed it wrong" from "it's broken".
        return ExitCode::Usage;
    }
}
