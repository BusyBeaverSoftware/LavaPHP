<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * The caller is not signed in and the thing they asked for needs them to be.
 *
 * A 401 rather than a 403: there is an identity they could present, they just
 * have not. The HTML routes never produce this — they redirect to the login page
 * instead, because a browser cannot act on a 401. It exists for the JSON routes,
 * where a redirect would be nonsense and the caller can actually respond to the
 * status.
 */
final class NotAuthenticated extends LavaProblem
{
    public static function of(): self
    {
        return new self(
            'This endpoint requires a signed-in user.',
            'POST /login with {"email": …, "password": …}, then send the session cookie with this request.',
        );
    }

    public function code(): string
    {
        return 'not_authenticated';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
