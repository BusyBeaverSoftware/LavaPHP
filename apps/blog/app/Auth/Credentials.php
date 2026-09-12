<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A user and their stored hash, fetched together.
 *
 * A pair rather than two calls because they come from the same row, and two
 * lookups would be two chances for the answers to disagree — a password checked
 * against one account and a session opened for another. It also keeps the hash
 * on a type that only the login path ever holds: `User` itself has no hash
 * field, so nothing that renders a user can leak one.
 */
final readonly class Credentials
{
    public function __construct(
        public User $user,
        public string $passwordHash,
    ) {
    }
}
