<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\SessionCookie;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every request a session, and every first-time visitor the cookie that
 * carries it.
 *
 * Global, because the CSRF token has to exist before any form is rendered —
 * including the login form, which is reached by someone who has no session yet.
 * That is why anonymous sessions are a thing: "signed in" is a property of a
 * session, not a prerequisite for having one.
 *
 * The cookie is set **only when the request arrived without one**. That is not
 * an optimisation, it is what makes the login and logout handlers possible: they
 * set their own `Set-Cookie` on the way out, and this middleware wraps them, so
 * a blanket write here would overwrite the authenticated session with the
 * anonymous one it read on the way in — a login that appears to succeed and then
 * silently does not.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    /** The request attribute handlers read the session from. */
    public const ATTRIBUTE = 'session';

    public function __construct(private readonly SessionCookie $cookie)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $this->cookie->read($request);

        if ($session !== null) {
            return $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));
        }

        // No cookie, a tampered one, or an expired one: mint a fresh session.
        // A tampered cookie gets no error page — there is nothing useful to tell
        // the holder of a cookie we did not issue, and treating it as a stranger
        // is both the safe and the friendly answer.
        $session = $this->cookie->anonymous();

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $session))
            ->withHeader(SessionCookie::HEADER, $this->cookie->header($session));
    }
}
