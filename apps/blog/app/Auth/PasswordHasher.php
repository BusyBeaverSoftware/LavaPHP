<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Password hashing, as a service so the choice is visible in `app/Services.php`
 * like every other choice this app makes.
 *
 * It is a thin wrapper on purpose — `password_hash`/`password_verify` are the
 * PHP core's own implementation of a current algorithm, and the framework's
 * stated position is to own the API layer and delegate the CVE edges. Writing a
 * bespoke hasher here would be the opposite of that.
 */
final class PasswordHasher
{
    /**
     * A real bcrypt hash of a value nobody holds, used to verify against when
     * there is no account to verify against.
     *
     * This is the fix for a timing oracle, and it is easy to miss because the
     * naive version reads as correct: `if ($hash === null || !verify(...))`
     * short-circuits, so an unknown email is answered in microseconds and a
     * known one in ~50ms. Anyone can then measure which addresses are
     * registered — through an endpoint whose whole job is to not say that.
     *
     * Verifying against a decoy makes the two paths do the same work.
     */
    private const DECOY = '$2y$12$FStTCADQ/WuDiUdxwl78l.P6GcDASdHQwLcO6If1N5BDDzHHGVsEu';

    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    /**
     * Verify a password against a stored hash — or against the decoy when the
     * account does not exist. A null `$hash` is accepted here rather than
     * guarded against at the call site, so there is no way to write the
     * short-circuiting version without deliberately reaching around this.
     */
    public function verify(?string $hash, string $plain): bool
    {
        return password_verify($plain, $hash ?? self::DECOY);
    }

    /**
     * Whether a stored hash was made with parameters weaker than today's
     * default — checked on each successful login, so the cost of upgrading is
     * paid once per user at the moment their password is already in memory.
     */
    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
