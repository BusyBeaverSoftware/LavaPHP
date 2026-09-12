<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The context every page template needs, in one place.
 *
 * This exists because `lava/view` exposes exactly two Twig functions and no way
 * to add a global: there is no `addGlobal()`, no extension registration, and the
 * environment is built inside the pack's factory. So a layout that wants the
 * signed-in user and the CSRF token — and a blog layout wants both, since the
 * "Sign out" control is a CSRF-protected form — has to receive them in the
 * context of every single render.
 *
 * Without something like this, every controller in the app ends up repeating
 * `['user' => $auth->user($request), 'csrf' => $auth->csrf($request), …]`, and
 * the one that forgets produces a template error at render time. The keys are
 * merged last so a caller cannot accidentally shadow `user` or `csrf` — which is
 * the whole point of centralising it.
 */
final class Page
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function context(Auth $auth, ServerRequestInterface $request, array $extra = []): array
    {
        return array_merge($extra, [
            'user' => $auth->user($request),
            'csrf' => $auth->csrf($request),
        ]);
    }
}
