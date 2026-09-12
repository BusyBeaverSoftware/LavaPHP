<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use App\Problem\NotAuthenticated;
use Lava\Core\Http\HttpErrors;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The JSON half of the auth gate — a 401 problem envelope, not a redirect.
 *
 * The caller is a program. A 302 to `/login` would hand it an HTML page with a
 * 200 under some clients, and there is no way for it to tell "signed out" from
 * "the endpoint moved". `not_authenticated` carries the code, the fix, and the
 * status, which is what a client needs to decide to re-authenticate.
 */
final class RequireLoginJsonMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->user($request) !== null) {
            return $handler->handle($request);
        }

        return HttpErrors::toResponse(NotAuthenticated::of(), $request);
    }
}
