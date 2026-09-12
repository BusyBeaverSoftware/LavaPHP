<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The email/password pair did not match a user.
 *
 * One problem for both halves. "No such user" and "wrong password" are the same
 * failure to an attacker probing for which addresses are registered, so the
 * message is the same too — and it is the message a naive login form gets wrong.
 */
final class InvalidCredentials extends LavaProblem
{
    public static function of(string $email): self
    {
        return new self(
            'Those credentials do not match an account.',
            'Check the address and password. If you have not registered yet: GET /register.',
            ['email' => $email],
        );
    }

    public function code(): string
    {
        return 'invalid_credentials';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
