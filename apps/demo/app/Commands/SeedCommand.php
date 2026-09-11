<?php

declare(strict_types=1);

namespace App\Commands;

use Lava\Core\Boot\App;
use Lava\Core\Console\Args;
use Lava\Core\Console\IO;
use Lava\Core\Console\Table;

/**
 * A few sample tasks, so a fresh clone has something to look at.
 *
 * It APPENDS rather than replacing, and that is a deliberate limit: a `--fresh`
 * flag would be a one-word way to delete a database, and the demo should not
 * ship a destructive command a reader might type against something real. The
 * framework's own answer is already here and already explicit about what it
 * undoes:
 *
 *     lava db:rollback      # undoes the last batch, table and all
 *     lava db:migrate       # and this puts an empty one back
 *
 * Run it twice and you get the sample tasks twice. `lava app:stats` will say so.
 */
final class SeedCommand extends AppTaskCommand
{
    public function name(): string
    {
        return 'app:seed';
    }

    public function summary(): string
    {
        return 'Insert a few sample tasks.';
    }

    /**
     * @return array<string, int>
     */
    public function emptyPayload(Args $args): array
    {
        return ['inserted' => 0, 'total' => 0];
    }

    protected function inspect(IO $io, Args $args, App $app): int
    {
        $tasks = $this->tasks($app);
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        // One due today (not overdue), one due later, two undated — so the
        // `overdue` count is a number the reader can check by hand.
        $sample = [
            ['Read the generated AGENTS.md', null],
            ['Wire the routes', $today->modify('+3 days')->format('Y-m-d')],
            ['Write the migration', $today->format('Y-m-d')],
            ['Seed the database', null],
        ];

        $rows = [];
        foreach ($sample as [$title, $dueOn]) {
            $task = $tasks->create($title, $dueOn);
            $rows[] = [(string) $task->id, $task->title, $task->dueOn ?? '—'];
        }

        $io->data('inserted', count($rows));
        $io->data('total', count($tasks->all()));

        $io->text((new Table(['id', 'title', 'due_on'], $rows))->render());

        return $io->emit($this->name());
    }
}
