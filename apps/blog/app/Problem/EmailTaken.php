<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A registration against an address that already has an account.
 *
 * 409 rather than 422: the input is well formed, it just conflicts with the
 * current state of the database. (Note the login path deliberately does NOT do
 * this — see {@see InvalidCredentials}.)
 */
final class EmailTaken extends LavaProblem
{
    public static function of(string $email): self
    {
        return new self(
            "{$email} is already registered.",
            'Sign in instead: GET /login. If the password is lost, change it with lava user:password.',
            ['email' => $email],
        );
    }

    public function code(): string
    {
        return 'email_taken';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
