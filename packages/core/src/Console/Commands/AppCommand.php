<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\ExitCode;
use Lava\Core\Console\IO;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;

/**
 * A command that inspects a booted app. The boot is the command's first act,
 * and a boot failure is rendered as the ordinary problem report — the same
 * media the app itself would use — so `lava routes` on a broken app explains
 * why it's broken instead of printing an empty table.
 *
 * Every app command takes `--env=<name>`: booting under a different
 * environment is how an agent asks "is this flag on in prod?" without editing
 * config. The override is applied for the boot only and always restored.
 */
abstract class AppCommand extends Command
{
    public function flags(): array
    {
        return ['json', 'env'];
    }

    final public function run(IO $io, Args $args, string $appDir): int
    {
        // Usage is checked BEFORE the boot: "you typed it wrong" does not
        // depend on the app's state, and an agent that fumbled the invocation
        // should not also have to read a boot report to find that out.
        $usage = $this->usageProblem($args);
        if ($usage !== null) {
            $report = new ProblemReport();
            $report->add($usage);
            $io->emit($this->name(), $report);
            return ExitCode::Usage;
        }

        // Seed the payload shape before anything can fail. A command that
        // throws mid-inspect (an unknown flag, say) has written no data yet,
        // and an envelope with `{}` for data would make a `--json` consumer
        // branch on a shape it was promised would be stable. Seeding means
        // every exit path — ok, boot failure, thrown problem — carries this
        // command's keys. inspect() overwrites them in place as it works.
        foreach ($this->emptyPayload($args) as $key => $value) {
            $io->data($key, $value);
        }

        $boot = AppBoot::boot($appDir, $args->value('env'));

        if ($boot instanceof BootFailure) {
            return $io->emit($this->name(), $boot->problems);
        }

        return $this->inspect($io, $args, $boot);
    }

    /**
     * A malformed invocation, or null when the arguments are usable. Commands
     * with positionals implement this; the kernel turns the result into a
     * usage error (exit 2), the same contract `unknown_command` follows.
     */
    protected function usageProblem(Args $args): ?LavaProblem
    {
        return null;
    }

    /**
     * This command's payload keys with no rows, seeded before the boot so
     * `--json` consumers always get the same shape.
     *
     * `$args` is passed because the shape can depend on the subcommand —
     * `lava features` lists flags, `lava features resolve` reports one — and
     * an agent parsing either must get that subcommand's keys even when the
     * app underneath it failed to boot.
     *
     * @return array<string, mixed>
     */
    protected function emptyPayload(Args $args): array
    {
        return [];
    }

    abstract protected function inspect(IO $io, Args $args, App $app): int;
}
