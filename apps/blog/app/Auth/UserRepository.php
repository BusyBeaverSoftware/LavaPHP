<?php

declare(strict_types=1);

namespace App\Auth;

use Lava\Db\Connection;
use Lava\Db\Query\Operator;

/**
 * Every read and write of the `users` table, in one place.
 *
 * Handlers never touch SQL: a controller asks for a `User`, and the only place
 * that knows the table has a `password_hash` column is this repository. That is
 * also what keeps the hash out of the templates — `User` does not carry it.
 */
final class UserRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function byId(int $id): ?User
    {
        $row = $this->db->fetchOne(
            $this->db->table('users')->where('id', Operator::Eq, $id)->toSelect(),
        );

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Lookup is by the normalised address, because that is what was stored.
     * Normalising on both sides is what stops `Alice@example.com` and
     * `alice@example.com` from being two accounts.
     */
    public function byEmail(string $email): ?User
    {
        $row = $this->db->fetchOne(
            $this->db->table('users')->where('email', Operator::Eq, self::normalise($email))->toSelect(),
        );

        return $row === null ? null : self::hydrate($row);
    }

    public function create(string $email, string $displayName, string $passwordHash): User
    {
        $this->db->run(
            $this->db->table('users')->insert([
                'email' => self::normalise($email),
                'display_name' => $displayName,
                'password_hash' => $passwordHash,
                'session_epoch' => 0,
            ]),
        );

        // `lastInsertId()` is nullable by design (a driver may refuse to report
        // it), so the failure is stated rather than silently coerced to 0 — an
        // id of 0 would be a user that does not exist, discovered much later.
        $id = $this->db->lastInsertId();
        if ($id === null) {
            throw new \RuntimeException('The driver did not report the new user id.');
        }

        return new User((int) $id, self::normalise($email), $displayName, 0);
    }

    /** Replace the stored hash, and bump the epoch so existing sessions die. */
    public function changePassword(int $id, string $passwordHash): void
    {
        $this->writeHash($id, $passwordHash, bumpEpoch: true);
    }

    /**
     * A transparent upgrade — the password is unchanged, only its cost is.
     *
     * Deliberately does NOT bump the epoch: nobody's credential changed, and
     * logging a user out of every device because the server got faster would be
     * a security measure that punishes the wrong person.
     */
    public function rehash(int $id, string $passwordHash): void
    {
        $this->writeHash($id, $passwordHash, bumpEpoch: false);
    }

    /**
     * The user and their hash for a login attempt, or null for an unknown
     * address.
     *
     * Deliberately a separate method from {@see byEmail()}: this is the only
     * query in the app that returns a hash, so `grep password_hash app/` finds a
     * short list rather than a dozen places a secret could leak out of.
     */
    public function credentials(string $email): ?Credentials
    {
        $row = $this->db->fetchOne(
            $this->db->table('users')->where('email', Operator::Eq, self::normalise($email))->toSelect(),
        );

        if ($row === null) {
            return null;
        }

        $hash = $row['password_hash'] ?? null;

        if (!is_string($hash) || $hash === '') {
            throw new \RuntimeException('A users row has no password hash, so it can never be signed in to.');
        }

        return new Credentials(self::hydrate($row), $hash);
    }

    private function writeHash(int $id, string $passwordHash, bool $bumpEpoch): void
    {
        $values = ['password_hash' => $passwordHash];

        if ($bumpEpoch) {
            $values['session_epoch'] = $this->nextEpoch($id) + 1;
        }

        $this->db->run(
            $this->db->table('users')->where('id', Operator::Eq, $id)->update($values),
        );
    }

    private function nextEpoch(int $id): int
    {
        $row = $this->db->fetchOne(
            $this->db->table('users')->select('session_epoch')->where('id', Operator::Eq, $id)->toSelect(),
        );

        $current = $row['session_epoch'] ?? 0;

        return is_numeric($current) ? (int) $current : 0;
    }

    private static function normalise(string $email): string
    {
        return strtolower(trim($email));
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): User
    {
        $id = $row['id'] ?? null;
        $email = $row['email'] ?? null;
        $displayName = $row['display_name'] ?? null;
        $epoch = $row['session_epoch'] ?? 0;

        if (!is_numeric($id) || !is_string($email) || !is_string($displayName)) {
            throw new \RuntimeException('A users row is missing a column this app requires.');
        }

        return new User((int) $id, $email, $displayName, is_numeric($epoch) ? (int) $epoch : 0);
    }
}
