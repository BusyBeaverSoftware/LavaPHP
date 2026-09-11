<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Lava\Core\Container\Container;
use Lava\Core\Problem\BadMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Composes PSR-15 middleware around a callable core. Middleware entries are
 * container ids (class-strings), resolved from the container when the layer
 * actually runs. The list is outermost-first — the first entry is the first
 * to see the request and the last to see the response.
 */
final class MiddlewarePipeline
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * @param list<string> $middleware class-strings, outermost first
     * @param callable(ServerRequestInterface): ResponseInterface $core
     */
    public function run(ServerRequestInterface $request, array $middleware, callable $core): ResponseInterface
    {
        $next = new CallableHandler(\Closure::fromCallable($core));
        for ($i = count($middleware) - 1; $i >= 0; $i--) {
            $next = new CallableHandler($this->frame($middleware[$i], $next));
        }
        return $next->handle($request);
    }

    /** Wraps one middleware layer around the rest of the chain. */
    private function frame(string $class, RequestHandlerInterface $next): \Closure
    {
        return function (ServerRequestInterface $request) use ($class, $next): ResponseInterface {
            $middleware = $this->container->get($class);
            if (!$middleware instanceof MiddlewareInterface) {
                throw BadMiddleware::notPsr15($class, 'the middleware pipeline');
            }
            return $middleware->process($request, $next);
        };
    }
}