<?php

declare(strict_types=1);

namespace App\Problem;

use Lava\Core\Problem\LavaProblem;

/**
 * A handler asked for the session and there was none on the request.
 *
 * This is a wiring fault rather than a request fault, which is why it is a 500
 * and not a 401: `SessionMiddleware` is global and runs on every request, so the
 * only way to reach this is to have removed it from `app/Middleware.php` — and
 * the moment that happens, `csrf()` would otherwise start returning a nonce that
 * no browser ever received, which is a silent CSRF hole rather than a visible
 * failure. Loud is the right answer.
 */
final class SessionMissing extends LavaProblem
{
    public static function of(string $attribute): self
    {
        return new self(
            "No session on the request (attribute '{$attribute}' is absent).",
            'Add ' . \App\Http\SessionMiddleware::class . ' to app/Middleware.php — it mints the session every handler reads.',
            ['attribute' => $attribute],
        );
    }

    public function code(): string
    {
        return 'session_missing';
    }
}
