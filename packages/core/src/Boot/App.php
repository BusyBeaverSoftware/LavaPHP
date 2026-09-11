<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Config\Config;
use Lava\Core\Config\EnvVar;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Features\Features;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Modules\PackInfo;
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
     * The trailing four fields are what boot DECIDED, carried forward for the
     * inspection commands (`lava about`, `lava env`, `lava describe`). They are
     * records of boot's own work, never recomputed at read time — a command
     * that re-derived them could disagree with the app it is describing.
     *
     * @param list<string> $globalMiddleware class-strings from app/Middleware.php
     * @param list<ModuleRef> $moduleRefs every entry of app/Modules.php, in order
     * @param array<string, PackInfo> $packs manifest by module class — enabled packs
     *        and disabled-but-installed ones alike (a pack that is off and
     *        missing is simply absent, which is why `lava about` can't guess)
     * @param array<string, string> $dotEnv valid KEY => VALUE pairs from config/.env
     * @param array<string, string> $envFromFile the subset of dotEnv boot promoted
     *        because the real environment did not already define it
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
        public readonly array $moduleRefs = [],
        public readonly array $packs = [],
        public readonly array $dotEnv = [],
        public readonly array $envFromFile = [],
    ) {
    }

    /**
     * Every environment variable this app reads, declared or not.
     *
     * The union is the point. App-declared EnvVars come first (in the order
     * the app wrote them), then the packs' in module order, then names that
     * exist only in config/.env — that last group is invisible to every other
     * view of the app, and it is exactly what an agent needs when a value "is
     * definitely set" but never reaches the code.
     *
     * A name declared twice keeps the APP's declaration: a pack and an app
     * disagreeing about a variable is the app's call to make, and the app is
     * the one the reader can see and change.
     *
     * @return list<array{name: string, var: EnvVar|null, by: string|null}>
     *         `var` is null for a bare .env entry nothing declared
     */
    public function envVars(): array
    {
        $entries = [];

        foreach ($this->packs as $pack) {
            foreach ($pack->envVars as $name) {
                $entries[$name] = [
                    'name' => $name,
                    'var' => EnvVar::optional($name, "Read by {$pack->package}."),
                    'by' => $pack->package,
                ];
            }
        }

        // Boot validated this id's shape (see WireAppServices), so the
        // instanceof is a guard against a fixture or a future step, not a
        // routine possibility.
        if ($this->container->has(EnvVar::CONTAINER_ID)) {
            $declared = $this->container->get(EnvVar::CONTAINER_ID);
            if (is_array($declared)) {
                foreach ($declared as $entry) {
                    if ($entry instanceof EnvVar) {
                        $entries[$entry->name] = [
                            'name' => $entry->name,
                            'var' => $entry,
                            'by' => 'app/Services.php',
                        ];
                    }
                }
            }
        }

        foreach (array_keys($this->dotEnv) as $name) {
            $entries[$name] ??= ['name' => $name, 'var' => null, 'by' => null];
        }

        return array_values($entries);
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