<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Features\Features;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Routing\HandlerInvoker;
use Lava\Core\Routing\Matched;
use Lava\Core\Routing\MiddlewarePipeline;
use Lava\Core\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A successfully booted app: the root object HTTP and CLI entry points hold.
 * A PSR-15 request handler, so the whole app composes with any middleware —
 * dispatch is match → gate → pipeline → invoke, and every problem on that
 * path renders through the same media (JSON/diagnostics) as boot problems.
 */
final class App implements RequestHandlerInterface
{
    /**
     * @param list<string> $globalMiddleware class-strings from app/Middleware.php
     */
    public function __construct(
        public readonly string $appDir,
        public readonly string $env,
        public readonly Config $config,
        public readonly Features $features,
        public readonly Container $container,
        public readonly ProblemReport $problems,
        public readonly Router $router,
        public readonly array $globalMiddleware = [],
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Audience flags decide per request: when the app registered a
        // subject resolver, bind the features to this request's subject
        // BEFORE matching, so gated routes stay real 404s, never 503s.
        $features = $this->features;
        if ($this->container->has(FlagSubjectResolver::class)) {
            $resolver = $this->container->get(FlagSubjectResolver::class);
            if (!$resolver instanceof FlagSubjectResolver) {
                return HttpErrors::toResponse(new InvalidConfig(
                    'The service registered under FlagSubjectResolver::class is '
                        . get_class($resolver) . ', which does not implement FlagSubjectResolver.',
                    'Register a class implementing Lava\\Core\\Features\\FlagSubjectResolver in app/Services.php.',
                    ['registered' => get_class($resolver)],
                ), $request, $this->env);
            }
            $features = $features->forSubject($resolver->subjectFor($request));
        }

        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath(), $features);
        if (!$result instanceof Matched) {
            // RouteNotFound | MethodNotAllowed — both are problems, rendered
            // like every other problem, with the fix in the body.
            return HttpErrors::toResponse($result, $request, $this->env);
        }

        $route = $result->route;

        // These two adapters are stateless views over the container — built
        // here rather than registered, so `lava services` lists only what
        // handlers can actually type-hint.
        $invoker = new HandlerInvoker($this->container);
        $pipeline = new MiddlewarePipeline($this->container);

        // The plan is fetched by name, not carried on the match result —
        // matching stays usable on plan-less routers (tests, URL generation).
        $plan = $this->router->plan($route->name);
        $args = $result->args;

        try {
            return $pipeline->run(
                $request,
                [...$this->globalMiddleware, ...$route->middleware],
                static fn (ServerRequestInterface $r): ResponseInterface => $invoker->invoke($plan, $r, $args),
            );
        } catch (\Lava\Core\Problem\LavaProblem $problem) {
            return HttpErrors::toResponse($problem, $request, $this->env);
        }
    }
}