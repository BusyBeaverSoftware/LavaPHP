<?php

declare(strict_types=1);

namespace App\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * What is in the database, without opening it.
 *
 * A read-only command, and the one `task_not_found`'s fix line tells a caller
 * to run — so its `--json` payload is a small contract: four integer keys, in
 * this order, always present, even when the app failed to boot (the payload is
 * seeded before the boot for exactly that reason).
 *
 * Dates are compared in UTC, matching the timestamps the repository writes. A
 * demo that mixed local and UTC dates would be right most of the day and wrong
 * near midnight, which is the worst kind of wrong.
 */
final class StatsCommand extends AppTaskCommand
{
    public function name(): string
    {
        return 'app:stats';
    }

    public function summary(): string
    {
        return 'Count the tasks, and how many are open, done or overdue.';
    }

    /**
     * @return array<string, int>
     */
    protected function emptyPayload(Args $args): array
    {
        return ['total' => 0, 'open' => 0, 'done' => 0, 'overdue' => 0];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $stats = $this->tasks($app)->stats(gmdate('Y-m-d'));

        // Both views are written on every run: `data()` is a no-op outside
        // `--json` and `text()` is a no-op inside it, so no command ever
        // branches on the output mode, and the two views cannot drift.
        foreach ($stats as $metric => $count) {
            $io->data($metric, $count);
        }

        $io->text((new Table(['metric', 'count'], [
            ['total', (string) $stats['total']],
            ['open', (string) $stats['open']],
            ['done', (string) $stats['done']],
            ['overdue', (string) $stats['overdue']],
        ]))->render());

        return $io->emit($this->name());
    }
}
