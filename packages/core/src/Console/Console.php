<?php

declare(strict_types=1);

namespace Lava\Core\Console;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnexpectedFailure;
use Lava\Core\Problem\UnknownCommand;

/**
 * The CLI kernel: turn argv into a command, run it, and let IO render the
 * result. Everything else in the console layer is a leaf — this is the only
 * place that reads argv, resolves a name, and maps a result to an exit code.
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

        $command = $this->registry->get($name);
        if ($command === null) {
            return $this->unknownCommand($io, $name);
        }

        if ($args->bool('help')) {
            $io->line($command->usage());
            $io->line($command->summary());
            return $io->emit($name);
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

    private function unknownCommand(IO $io, string $name): int
    {
        $report = new ProblemReport();
        $report->add(UnknownCommand::of($name, $this->registry->nearest($name)));
        $io->emit($name, $report);

        // A bad invocation is a usage error, distinct from a command that ran
        // and failed — an agent can tell "you typed it wrong" from "it's broken".
        return ExitCode::Usage;
    }
}
