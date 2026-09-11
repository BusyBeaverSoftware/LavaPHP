<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Adapts a callable into the PSR-15 RequestHandlerInterface middlewares
 * expect. The pipeline composes these; nothing else needs the adapter.
 */
final class CallableHandler implements RequestHandlerInterface
{
    /**
     * @param \Closure(ServerRequestInterface): ResponseInterface $core
     */
    public function __construct(private readonly \Closure $core)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->core)($request);
    }
}