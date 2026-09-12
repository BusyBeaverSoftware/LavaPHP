<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class CookieController
{
    /** What this request carried, in both places a handler can read it from. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return Responses::json([
            'params' => $request->getCookieParams(),
            'header' => $request->getHeaderLine('Cookie'),
        ]);
    }

    /** Two cookies in one response — two `Set-Cookie` lines, one with a comma in its date. */
    public function start(): ResponseInterface
    {
        return Responses::json(['started' => true])
            ->withAddedHeader('Set-Cookie', 'session=abc123; Path=/; HttpOnly; SameSite=Lax')
            ->withAddedHeader('Set-Cookie', 'note=hello%20world; Path=/; Expires=Wed, 01 Jan 2098 00:00:00 GMT');
    }

    /** How an app signs someone out. */
    public function end(): ResponseInterface
    {
        return Responses::json(['ended' => true])
            ->withHeader('Set-Cookie', 'session=; Path=/; Max-Age=0');
    }

    public function expired(): ResponseInterface
    {
        return Responses::json(['expired' => true])
            ->withHeader('Set-Cookie', 'note=gone; Path=/; Expires=Thu, 01 Jan 1970 00:00:00 GMT');
    }

    public function admin(): ResponseInterface
    {
        return Responses::json(['admin' => true])
            ->withHeader('Set-Cookie', 'admin=yes; Path=/admin');
    }

    /** No `Path`: scoped to the directory of this request, `/account/settings`. */
    public function theme(): ResponseInterface
    {
        return Responses::json(['theme' => 'dark'])
            ->withHeader('Set-Cookie', 'theme=dark');
    }
}
