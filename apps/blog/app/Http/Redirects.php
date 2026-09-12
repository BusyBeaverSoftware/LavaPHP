<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * `next=` handling, in one place because both sides of it are security-relevant
 * and neither is obvious.
 *
 * The parameter exists so that clicking "New post" while signed out returns you
 * to the editor rather than the front page. That convenience is an **open
 * redirect** unless the value is checked: `?next=https://evil.example/login`
 * would turn this app into a believable phishing front end, and the browser
 * would be showing the attacker's page under a URL the user was just on.
 *
 * So only a local absolute path is ever honoured. The scheme check is not
 * redundant with the leading-slash check — `//evil.example` is protocol-relative
 * and resolves to another host, and `/\evil.example` is treated as a path by
 * some browsers.
 */
final class Redirects
{
    /** Where an unauthenticated request should be sent, remembering where it was going. */
    public static function loginPath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        if ($path === '/login' || $path === '') {
            return '/login';
        }

        return '/login?next=' . rawurlencode($path);
    }

    /** The path to continue to after signing in — local, or the fallback. */
    public static function safeNext(mixed $raw, string $fallback = '/'): string
    {
        if (!is_string($raw) || $raw === '') {
            return $fallback;
        }

        if (!str_starts_with($raw, '/')) {
            return $fallback;
        }

        // `//host` and `/\host` are another origin wearing a path's clothes.
        if (str_starts_with($raw, '//') || str_starts_with($raw, '/\\')) {
            return $fallback;
        }

        // A CR or LF has no business in a Location header, and a URL-encoded one
        // arrives here already decoded.
        if (str_contains($raw, "\n") || str_contains($raw, "\r")) {
            return $fallback;
        }

        return $raw;
    }

    /**
     * 302 for a GET, 303 for anything else.
     *
     * The distinction is not pedantry: 302 says "the resource is temporarily
     * elsewhere" and a browser will re-issue the POST at the new location. 303
     * says "go here with a GET", which is what "your POST was refused, sign in
     * first" actually means.
     */
    public static function status(ServerRequestInterface $request): int
    {
        $method = strtoupper($request->getMethod());

        return $method === 'GET' || $method === 'HEAD' ? 302 : 303;
    }
}
