<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * One task, in the shape the API returns it.
 *
 * Rows come out of PDO as `array<string, mixed>` — everything a string, a bool
 * as 0 or 1, a column that was never set as null. This class is the single
 * place that turns one into a typed value, so the JSON body of every endpoint
 * is decided here rather than in five handlers.
 *
 * A `readonly` class rather than an array shape, because a typo in
 * `$task['dueOn']` is a runtime notice in one world and a TypeError in the
 * other. The DB column is `due_on`; the property and the JSON key are
 * deliberately the same word, so there is exactly one name to remember.
 */
final readonly class Task
{
    public function __construct(
        public int $id,
        public string $title,
        public bool $done,
        public ?string $dueOn,
        public string $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            title: (string) $row['title'],
            // SQLite and MySQL hand back 0/1 for a boolean column — as an int
            // or as the string "0"/"1" depending on the driver. `(bool)` reads
            // all four correctly; `'0'` is the one that catches people out.
            done: (bool) $row['done'],
            dueOn: $row['due_on'] === null ? null : (string) $row['due_on'],
            createdAt: (string) $row['created_at'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'done' => $this->done,
            'due_on' => $this->dueOn,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * The same fields, as text, for the CSV export.
     *
     * A bool has to be spelled out: `fputcsv` writes `false` as the empty
     * string, which in a spreadsheet reads as "missing", not as "not done".
     *
     * @return list<string>
     */
    public function csvRow(): array
    {
        return [
            (string) $this->id,
            $this->title,
            $this->done ? '1' : '0',
            $this->dueOn ?? '',
            $this->createdAt,
        ];
    }
}
