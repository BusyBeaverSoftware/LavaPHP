<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A state-changing request arrived without the token this session was served.
 *
 * 403, and deliberately not 401: the caller is very likely authenticated and the
 * cookie was very likely sent. This is the browser being made to issue a request
 * it did not intend to — a cross-site form post — which is exactly what
 * `SameSite=Lax` misses on a top-level navigation.
 */
final class CsrfMismatch extends LavaProblem
{
    public static function of(bool $sent): self
    {
        return new self(
            $sent
                ? 'The CSRF token does not match the one this session was issued.'
                : 'This request needs a CSRF token and none was sent.',
            'Send the session\'s token back as a `csrf` form field or an X-CSRF-Token header. Get it by loading the page that hosts the form.',
            ['sent' => $sent],
        );
    }

    public function code(): string
    {
        return 'csrf_mismatch';
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
