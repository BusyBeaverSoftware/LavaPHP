<?php

declare(strict_types=1);

namespace App\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** Global middleware — the outermost layer; its marker lands first in the order log. */
final class TimingMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $request = $request->withAttribute('order', [...(array) ($request->getAttribute('order') ?? []), 'global']);
        return $handler->handle($request)->withHeader('X-Timing', 'global');
    }
}