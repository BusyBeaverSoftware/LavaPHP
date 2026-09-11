<?php

declare(strict_types=1);

namespace Lava\Core\Console\Commands;

use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * `lava list` — every command this app can run, grouped by the pack that
 * provides it. The first thing an agent runs in an unfamiliar app, so the
 * text view leads with the same fields the `--json` view carries.
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

    public function run(IO $io, Args $args, string $appDir): int
    {
        $commands = array_map(
            static fn (Command $command): array => [
                'name' => $command->name(),
                'summary' => $command->summary(),
                'flags' => $command->flags(),
                'pack' => $command->pack(),
            ],
            $this->registry->all(),
        );
        $io->data('commands', $commands);

        foreach ($this->grouped($commands) as $pack => $rows) {
            $io->line(sprintf('%s (%d):', $pack, count($rows)));
            $io->text((new Table(['command', 'flags', 'summary'], $rows))->render());
            $io->line();
        }

        return $io->emit($this->name());
    }

    /**
     * @param list<array{name: string, summary: string, flags: list<string>, pack: string}> $commands
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
