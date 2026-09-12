<?php

declare(strict_types=1);

namespace App\Auth;

use App\Problem\CsrfMismatch;
use App\Problem\NotAuthenticated;
use App\Problem\SessionMissing;
use Psr\Http\Message\ServerRequestInterface;

/**
 * "Who is making this request, and did they mean to?"
 *
 * This is the service every protected handler depends on, and it is deliberately
 * written so that the REQUEST is an explicit argument rather than state held by
 * the object. The container holds one instance of this for the whole process —
 * a singleton cannot remember who is signed in, because the next request is
 * somebody else. Passing the request in is what makes that impossible to get
 * wrong, at the cost of typing it once per call.
 *
 * The session itself is attached to the request by {@see \App\Http\SessionMiddleware}
 * rather than read here, because only middleware can put the cookie on the
 * RESPONSE — and a session that is read but never written back would work for
 * exactly one request.
 */
final class Auth
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    /**
     * The session this request arrived with — always present, because the global
     * middleware mints one when the client has none.
     *
     * A missing attribute is therefore a wiring fault, not an anonymous visitor,
     * and it throws rather than returning an anonymous session: the silent
     * version of this would hand every form a CSRF nonce that no browser holds,
     * and every POST would then fail with `csrf_mismatch` — a bug that presents
     * as a security feature working.
     */
    public function session(ServerRequestInterface $request): SessionData
    {
        $session = $request->getAttribute(\App\Http\SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof SessionData) {
            throw SessionMissing::of(\App\Http\SessionMiddleware::ATTRIBUTE);
        }

        return $session;
    }

    /**
     * The signed-in user, or null. Two separate things have to line up, and each
     * is a different class of failure:
     *
     *  - the cookie's signature and lifetime have to hold, which is
     *    {@see SessionCookie::read()} — that layer owns them because they are
     *    properties of the cookie, and it is the only layer that can see the
     *    bytes;
     *  - the user's `session_epoch` has to match the one the cookie carries,
     *    which is how changing a password logs out everyone still holding a
     *    cookie minted before it.
     *
     * The epoch check is the one that needs the database, which is what makes it
     * the one that has to live here rather than in the cookie layer.
     */
    public function user(ServerRequestInterface $request): ?User
    {
        $session = $this->session($request);

        // The id itself, not `authenticated()`: the method says the same thing,
        // but only a null check on the property tells the type system the id
        // below is an int — and the lookup must never be handed a null.
        if ($session->userId === null) {
            return null;
        }

        $user = $this->users->byId($session->userId);

        if ($user === null || $user->sessionEpoch !== $session->epoch) {
            return null;
        }

        return $user;
    }

    /** The signed-in user, or a 401. For handlers the middleware has already gated. */
    public function requireUser(ServerRequestInterface $request): User
    {
        return $this->user($request) ?? throw NotAuthenticated::of();
    }

    /** This session's CSRF token, for a form or a header. */
    public function csrf(ServerRequestInterface $request): string
    {
        return $this->session($request)->csrf;
    }

    /**
     * Verify the token on a state-changing request, or throw.
     *
     * `hash_equals` for the same reason the cookie signature uses it. Both the
     * form field and a header are accepted because the JSON routes have no form
     * to put a hidden input in.
     *
     * @param array<string, mixed> $form the parsed body, for form posts
     */
    public function assertCsrf(ServerRequestInterface $request, array $form): void
    {
        $sent = $form['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');

        if (!is_string($sent) || $sent === '') {
            throw CsrfMismatch::of(false);
        }

        if (!hash_equals($this->csrf($request), $sent)) {
            throw CsrfMismatch::of(true);
        }
    }
}
