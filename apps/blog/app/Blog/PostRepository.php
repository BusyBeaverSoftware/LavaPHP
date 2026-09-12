<?php

declare(strict_types=1);

namespace App\Blog;

use App\Auth\User;
use Lava\Db\Connection;
use Lava\Db\Query\ConditionGroup;
use Lava\Db\Query\Direction;
use Lava\Db\Query\Operator;

/**
 * Every read and write of the `posts` table.
 *
 * Two things about this repository are shaped by the query builder rather than
 * by taste, and both are worth knowing before the first query is written:
 *
 *  - **There is no aggregate verb.** `select('COUNT(*)')` would compile to
 *    `SELECT "COUNT(*)"`, because the compiler quotes every column it is given
 *    and `Dialect::quote()` only knows how to quote dotted identifier paths. A
 *    count therefore goes through the raw-read hatch, {@see Connection::query()}.
 *
 *  - **There are no column aliases.** `select('users.display_name AS name')`
 *    would compile to `"users"."display_name AS name"`, which is not valid SQL —
 *    the whole string is treated as one identifier path. Selecting
 *    `users.display_name` unaliased produces the array key `display_name`, which
 *    is what this class reads, and is the reason the join below needs no `AS`.
 */
final class PostRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * What a viewer may see: every published post, plus their own drafts.
     *
     * Written as one grouped condition rather than "all published, then their
     * drafts appended", so there is one ordering and one place a visibility rule
     * lives. The `whereGroup` is not cosmetic: an ungrouped
     * `published = 1 OR author_id = ?` would let an `AND` added later bind more
     * tightly than intended and quietly widen the result.
     *
     * @return list<Post>
     */
    public function visibleTo(?User $viewer): array
    {
        $query = $this->base();

        if ($viewer === null) {
            $query->where('posts.published', Operator::Eq, true);
        } else {
            $query->whereGroup(function (ConditionGroup $g) use ($viewer): void {
                $g->where('posts.published', Operator::Eq, true)
                    ->orWhere('posts.author_id', Operator::Eq, $viewer->id);
            });
        }

        return array_map(self::hydrate(...), $this->db->fetch($query->toSelect()));
    }

    /** One post if the viewer may see it, null otherwise. Drafts are private. */
    public function visibleById(int $id, ?User $viewer): ?Post
    {
        $query = $this->base()->where('posts.id', Operator::Eq, $id);

        if ($viewer === null) {
            $query->where('posts.published', Operator::Eq, true);
        } else {
            $query->whereGroup(function (ConditionGroup $g) use ($viewer): void {
                $g->where('posts.published', Operator::Eq, true)
                    ->orWhere('posts.author_id', Operator::Eq, $viewer->id);
            });
        }

        $row = $this->db->fetchOne($query->toSelect());

        return $row === null ? null : self::hydrate($row);
    }

    public function create(int $authorId, string $title, string $body, bool $published): Post
    {
        $now = self::now();

        $this->db->run(
            $this->db->table('posts')->insert([
                'author_id' => $authorId,
                'title' => $title,
                'body' => $body,
                'published' => $published,
                'created_at' => $now,
                'updated_at' => $now,
            ]),
        );

        $id = $this->db->lastInsertId();
        if ($id === null) {
            throw new \RuntimeException('The driver did not report the new post id.');
        }

        $created = $this->find((int) $id);
        if ($created === null) {
            throw new \RuntimeException('The post was inserted but cannot be read back.');
        }

        return $created;
    }

    public function update(int $id, string $title, string $body, bool $published): void
    {
        $this->db->run(
            $this->db->table('posts')
                ->where('id', Operator::Eq, $id)
                ->update([
                    'title' => $title,
                    'body' => $body,
                    'published' => $published,
                    'updated_at' => self::now(),
                ]),
        );
    }

    public function delete(int $id): void
    {
        $this->db->run(
            $this->db->table('posts')->where('id', Operator::Eq, $id)->delete(),
        );
    }

    public function count(): int
    {
        $rows = $this->db->query('SELECT COUNT(*) AS n FROM posts');

        return isset($rows[0]['n']) && is_numeric($rows[0]['n']) ? (int) $rows[0]['n'] : 0;
    }

    /** Unfiltered lookup. Never reachable from a handler — see visibleById(). */
    private function find(int $id): ?Post
    {
        $row = $this->db->fetchOne(
            $this->base()->where('posts.id', Operator::Eq, $id)->toSelect(),
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * The select every read in this class starts from: newest first, with the
     * author's name joined in.
     *
     * `LEFT JOIN` rather than `INNER`: an inner join would make a post whose
     * author row had been deleted vanish from the listing entirely, which is a
     * data-loss-shaped bug produced by a query written to prevent one.
     */
    private function base(): \Lava\Db\Query\QueryBuilder
    {
        return $this->db->table('posts')
            ->select('posts.*', 'users.display_name')
            ->leftJoin('users', 'posts.author_id', 'users.id')
            ->orderBy('posts.created_at', Direction::Desc)
            ->orderBy('posts.id', Direction::Desc);
    }

    /**
     * `timestamps()` in the schema DSL is two nullable datetime columns, not a
     * pair that fills itself — so the app writes them. UTC, because the column
     * holds no offset and a local-time string would be ambiguous for exactly the
     * part of the day a blog gets read.
     */
    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Post
    {
        $id = $row['id'] ?? null;
        $authorId = $row['author_id'] ?? null;
        $title = $row['title'] ?? null;
        $body = $row['body'] ?? null;

        if (!is_numeric($id) || !is_numeric($authorId) || !is_string($title) || !is_string($body)) {
            throw new \RuntimeException('A posts row is missing a column this app requires.');
        }

        $name = $row['display_name'] ?? null;
        $createdAt = $row['created_at'] ?? null;
        $updatedAt = $row['updated_at'] ?? null;

        return new Post(
            (int) $id,
            (int) $authorId,
            is_string($name) ? $name : 'Someone',
            $title,
            $body,
            self::truthy($row['published'] ?? null),
            is_string($createdAt) ? $createdAt : null,
            is_string($updatedAt) ? $updatedAt : null,
        );
    }

    /**
     * SQLite has no boolean type, so a `bool` column comes back as `0`/`1` —
     * or as `'0'`/`'1'`, depending on how the driver decided to bind it. Both
     * spellings are accepted rather than one being assumed, because the wrong
     * guess fails silently in the direction of "every post is a published post".
     */
    private static function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
