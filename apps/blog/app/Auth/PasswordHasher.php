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
     * Verifying against a decoy makes the two paths do the same work — but only
     * while the decoy costs what a real hash costs. This one is bcrypt at cost 12,
     * PHP 8.4's default. This app allows PHP 8.3, whose default is cost 10, and
     * there the decoy was the SLOW path: the same oracle, reversed. So
     * {@see decoyHash()} uses it only when `PASSWORD_DEFAULT` agrees, and hashes a
     * fresh decoy with today's default when it does not.
     */
    private const DECOY = '$2y$12$FStTCADQ/WuDiUdxwl78l.P6GcDASdHQwLcO6If1N5BDDzHHGVsEu';

    private ?string $decoy = null;

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
        return password_verify($plain, $hash ?? $this->decoyHash());
    }

    /**
     * The hash an unknown account is verified against: made with the same
     * algorithm and cost as `hash()` makes today, which is the whole of its job.
     * Public so a test can hold it to that.
     */
    public function decoyHash(): string
    {
        return $this->decoy ??= password_needs_rehash(self::DECOY, PASSWORD_DEFAULT)
            ? password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)
            : self::DECOY;
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
