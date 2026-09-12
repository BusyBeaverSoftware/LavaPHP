<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The two forms a signed-out visitor can reach.
 *
 * Both render the same context shape the POST handlers re-render on failure —
 * `errors` and `values` — so the templates have exactly one contract. A GET that
 * passed `email` at the top level and a POST that passed `values.email` would
 * render fine on the happy path and break the first time somebody mistyped a
 * password, which is the worst possible moment to discover it.
 *
 * `register()` is only wired to a route when `signups_open` is on, so this class
 * never tests the flag — the route does ({@see \App\Routes}). That is the shape
 * the framework pushes you toward and it is the better one: a flag checked in a
 * handler leaves the route reachable, and a POST to a route that "does not
 * exist" is a 404 rather than a handler that decided to behave as if it did.
 */
final class AuthPageController
{
    public function login(
        ServerRequestInterface $request,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        $query = $request->getQueryParams();

        return $view->render('login', Page::context($auth, $request, [
            'errors' => [],
            'values' => [
                'email' => '',
                'next' => Redirects::safeNext($query['next'] ?? null),
            ],
        ]));
    }

    public function register(
        ServerRequestInterface $request,
        Auth $auth,
        ViewRenderer $view,
    ): ResponseInterface {
        return $view->render('register', Page::context($auth, $request, [
            'errors' => [],
            'values' => ['email' => '', 'display_name' => ''],
        ]));
    }
}
