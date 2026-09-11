<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Boot\BootFailure;
use Lava\Core\Console\AppBoot;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\Envelope;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava list` — every command this app can run, grouped by the pack that
 * provides it. The first thing an agent runs in an unfamiliar app, so the
 * text view leads with the fields a human scans and the `--json` view carries
 * one more: `schema`, the contract name the command's envelope claims.
 *
 * That one field is machine-only by design. Its value is a document name
 * (`lava.db.status/1`), not a flag to type or a phrase to read, so it earns a
 * key in the payload and not a fourth column in a table — and it is here
 * because a command is the only thing that knows which contract it emits:
 * re-deriving the name from the command name is a second, silent copy of
 * `Envelope::schema()`'s colon-to-dot rule and its per-command version, and
 * the copy that drifts is always the one nobody thinks to update.
 *
 * It boots the app opportunistically, because the command set IS a boot
 * decision: a pack that is enabled contributes commands, and listing only the
 * core set would hide them. But it never fails on a boot failure — "what can I
 * still run?" is a question most worth answering when the app is broken, so the
 * core set is shown and `booted: false` records why. Diagnosing the failure is
 * `lava check`'s job, not this one's.
 *
 * @phpstan-type CommandRow array{name: string, summary: string, flags: list<string>, pack: string, schema: string}
 */
final class ListCommand extends Command
{
    public function __construct(private readonly CommandRegistry $registry)
    {
    }

    public function name(): string
    {
        return 'list';
    }

    public function summary(): string
    {
        return 'List every available command, grouped by pack.';
    }

    public function flags(): array
    {
        return ['json', 'env'];
    }

    /**
     * No commands listed, and no boot to report on.
     *
     * `booted: false` is accurate rather than pessimistic: this shape is emitted
     * for an invocation the kernel rejects before ListCommand runs, where no
     * boot has happened, and false is the answer until one does.
     *
     * @return array<string, mixed>
     */
    public function emptyPayload(Args $args): array
    {
        return ['commands' => [], 'booted' => false];
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $boot = AppBoot::boot($appDir, $args->value('env'));
        $registry = $boot instanceof App ? $boot->commands() : $this->registry;

        $commands = array_map(
            static fn (Command $command): array => [
                'name' => $command->name(),
                'summary' => $command->summary(),
                'flags' => $command->flags(),
                'pack' => $command->pack(),
                // Asked of the command, through the one place a contract name
                // is built, so a `/2` bump reaches this payload the day it
                // reaches the envelope.
                'schema' => Envelope::schema($command->name()),
            ],
            $registry->all(),
        );
        $io->data('commands', $commands);
        $io->data('booted', !$boot instanceof BootFailure);

        foreach ($this->grouped($commands) as $pack => $rows) {
            $io->line(sprintf('%s (%d):', $pack, count($rows)));
            $io->text((new Table(['command', 'flags', 'summary'], $rows))->render());
            $io->line();
        }

        if ($boot instanceof BootFailure) {
            $io->text("note: this app does not boot — pack commands are unknown. Run: lava check\n");
        }

        return $io->emit($this->name());
    }

    /**
     * @param list<CommandRow> $commands
     * @return array<string, list<list<string>>> pack => rows, core first
     */
    private function grouped(array $commands): array
    {
        $groups = [];
        foreach ($commands as $command) {
            $flags = implode(' ', array_map(
                static fn (string $flag): string => '--' . $flag,
                $command['flags'],
            ));
            $groups[$command['pack']][] = [$command['name'], $flags, $command['summary']];
        }

        uksort($groups, static function (string $a, string $b): int {
            if ($a === $b) {
                return 0;
            }
            if ($a === 'core') {
                return -1;
            }
            if ($b === 'core') {
                return 1;
            }
            return strcmp($a, $b);
        });

        return $groups;
    }
}
