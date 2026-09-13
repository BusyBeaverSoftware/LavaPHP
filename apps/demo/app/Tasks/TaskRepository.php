<?php

declare(strict_types=1);

namespace App\Tasks;

use Lava\Db\Connection;
use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;

/**
 * Every read and write of the `tasks` table, in one file.
 *
 * The repository is a thin, honest wrapper: statements are built with the query
 * builder, and rows are handed to {@see Task} to be shaped. It holds no
 * connection of its own — the one it is given connects on first query, so
 * constructing this class touches no network and booting the app needs no
 * database.
 *
 * `UPDATE` and `DELETE` cannot be written without a `where()` clause: the
 * builder refuses an unbounded write rather than trusting the caller to
 * remember. That is why `complete()` and `delete()` below have no "did you mean
 * to update everything" branch to get wrong.
 */
final class TaskRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Newest first. `$done` narrows to open or finished tasks; null means both.
     *
     * @return list<Task>
     */
    public function all(?bool $done = null): array
    {
        $query = $this->db->table('tasks')->select('*');

        if ($done !== null) {
            $query->where('done', Operator::Eq, $done);
        }

        return array_map(
            Task::fromRow(...),
            $this->db->fetch($query->orderBy('id', Direction::Desc)),
        );
    }

    public function find(int $id): ?Task
    {
        $row = $this->db->fetchOne(
            $this->db->table('tasks')->select('*')->where('id', Operator::Eq, $id),
        );

        return $row === null ? null : Task::fromRow($row);
    }

    /**
     * The returned task is built from what was written, not read back.
     *
     * A `SELECT` after the `INSERT` would cost a second round trip to learn
     * something this method already knows, and it would need a "the row is
     * somehow gone" branch that can never be taken.
     */
    public function create(string $title, ?string $dueOn): Task
    {
        $now = gmdate('Y-m-d H:i:s');

        $this->db->run($this->db->table('tasks')->insert([
            'title' => $title,
            'done' => false,
            'due_on' => $dueOn,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        return new Task(
            id: (int) $this->db->lastInsertId(),
            title: $title,
            done: false,
            dueOn: $dueOn,
            createdAt: $now,
        );
    }

    /** Null when no task has that id — the caller turns that into a 404. */
    public function complete(int $id): ?Task
    {
        $updated = $this->db->run(
            $this->db->table('tasks')
                ->where('id', Operator::Eq, $id)
                ->update(['done' => true, 'updated_at' => gmdate('Y-m-d H:i:s')]),
        );

        return $updated === 0 ? null : $this->find($id);
    }

    public function delete(int $id): bool
    {
        return $this->db->run(
            $this->db->table('tasks')->where('id', Operator::Eq, $id)->delete(),
        ) > 0;
    }

    /**
     * What `lava app:stats` reports.
     *
     * Every count is taken in PHP, and that is a deliberate trade rather than
     * an oversight. The builder has no aggregate verb — `select()` takes
     * column names, not expressions, and refuses `select('COUNT(*)')` with
     * `bad_query` rather than quoting it into a string. So a
     * count in SQL means leaving the builder for `Connection::query()` and
     * quoting the identifiers by hand; at demo scale, reading the rows and
     * counting them is shorter, cannot be wrong about the dialect, and is the
     * version a reader should copy. When the table is big enough for that to
     * matter, the raw path is right there and is documented.
     *
     * @return array{total: int, open: int, done: int, overdue: int}
     */
    public function stats(string $today): array
    {
        $tasks = $this->all();

        $open = array_filter($tasks, static fn (Task $task): bool => !$task->done);
        $overdue = array_filter(
            $open,
            // A task with no due date is never overdue. String comparison is
            // safe here because the column is an ISO `YYYY-MM-DD` date, which
            // sorts lexicographically in the same order it sorts in time.
            static fn (Task $task): bool => $task->dueOn !== null && $task->dueOn < $today,
        );

        return [
            'total' => count($tasks),
            'open' => count($open),
            'done' => count($tasks) - count($open),
            'overdue' => count($overdue),
        ];
    }
}
