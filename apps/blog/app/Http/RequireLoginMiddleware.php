<?php

declare(strict_types=1);

namespace App\Http;

use App\Auth\Auth;
use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Per-route gate for the HTML pages: signed out means a redirect, not a 401.
 *
 * A browser cannot act on a 401 — it renders whatever body came with it and the
 * user has no way forward. So the HTML half of this app redirects to the login
 * page and remembers where the request was going; the JSON half returns the
 * status ({@see RequireLoginJsonMiddleware}).
 *
 * That these are two classes rather than one is a direct consequence of the
 * framework having no content negotiation: a route answers one media, so the
 * "which failure shape?" question is answered by which route you registered, not
 * by an `Accept` header inspected at runtime. The alternative — one middleware
 * sniffing `Accept` — would be the implicit behaviour this framework exists to
 * avoid, and it would be wrong for the requests that send no header at all.
 */
final class RequireLoginMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Auth $auth)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->auth->user($request) !== null) {
            return $handler->handle($request);
        }

        return Responses::redirect(
            Redirects::loginPath($request),
            Redirects::status($request),
        );
    }
}
