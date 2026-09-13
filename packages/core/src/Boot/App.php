<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Config\Config;
use Lava\Core\Config\EnvVar;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Container\Container;
use Lava\Core\Features\FlagSubjectResolver;
use Lava\Core\Features\Features;
use Lava\Core\Features\FeatureScope;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\RequestBody;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\UnexpectedFailure;
use Lava\Core\Routing\HandlerInvoker;
use Lava\Core\Routing\Matched;
use Lava\Core\Routing\MiddlewarePipeline;
use Lava\Core\Routing\Router;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * A successfully booted app: the root object HTTP and CLI entry points hold.
 * A PSR-15 request handler, so the whole app composes with any middleware —
 * dispatch is match → gate → pipeline → invoke, and every problem on that
 * path renders through the same media (JSON/diagnostics) as boot problems.
 * A request no handler answers still passes through the global middleware:
 * see {@see unrouted()}.
 *
 * **Nothing thrown on the request path escapes.** A `LavaProblem` renders as
 * itself; any other throwable renders as `unexpected_failure`, after the global
 * middleware has had its chance to answer it. Boot and the CLI already wrapped
 * what they could not name, and a handler's `PDOException` used to leave this
 * class as PHP's uncaught exception instead.
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

    /**
     * The app's command set as RegisterCommands assembled it — the core
     * commands plus whatever the enabled modules and app/Commands.php
     * contributed.
     *
     * The fallback is not a convenience: `lava list` has to answer even for an
     * app that cannot boot (that is when an agent most wants to know what it
     * can still run), and the core set is exactly what is available then.
     */
    public function commands(): CommandRegistry
    {
        if ($this->container->has(CommandRegistry::class)) {
            $registry = $this->container->get(CommandRegistry::class);
            if ($registry instanceof CommandRegistry) {
                return $registry;
            }
        }
        return CommandRegistry::core();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // The environment first, because every renderer below may need it and
        // a handler has no easy way to learn it: recorded once, here, so
        // `HttpErrors::forReport($report, $request)` renders prod as prod.
        $request = $request->withAttribute(HttpErrors::ENV_ATTRIBUTE, $this->env);

        // The body next, before routing: whether the client sent something
        // readable is a fact about the request, not about any route, and a
        // body that declares itself JSON and is not gets a 400 here rather
        // than reaching a handler as `null`.
        try {
            $request = RequestBody::parsed($request);
        } catch (LavaProblem $problem) {
            return $this->unrouted($request, $problem);
        }

        // Audience flags decide per request: when the app registered a
        // subject resolver, bind the features to this request's subject
        // BEFORE matching, so gated routes stay real 404s, never 503s.
        $features = $this->features;
        if ($this->container->has(FlagSubjectResolver::class)) {
            $resolver = $this->container->get(FlagSubjectResolver::class);
            if (!$resolver instanceof FlagSubjectResolver) {
                // `get_debug_type`, not `get_class`: this branch means the value
                // is not a FlagSubjectResolver, and it need not be an object at
                // all — `get_class` on a scalar registered under this id would
                // raise a TypeError from inside the error report, turning a
                // diagnosable misconfiguration into a blank 500.
                return $this->problemResponse(new InvalidConfig(
                    'The service registered under FlagSubjectResolver::class is '
                        . get_debug_type($resolver) . ', which does not implement FlagSubjectResolver.',
                    'Register a class implementing Lava\\Core\\Features\\FlagSubjectResolver in app/Services.php.',
                    ['registered' => get_debug_type($resolver)],
                ), $request);
            }

            // The resolver is app code, and it runs before any middleware:
            // whatever it throws is answered here, in the same media as
            // everything else, rather than leaving the app as a bare exception.
            try {
                $features = $features->forSubject($resolver->subjectFor($request));
            } catch (LavaProblem $problem) {
                return $this->problemResponse($problem, $request);
            } catch (\Throwable $throwable) {
                return $this->problemResponse(UnexpectedFailure::inRequest($request, $throwable), $request);
            }
        }

        // Everything from here answers for this subject: the router, a handler
        // that takes `Features`, and a template's `feature()` all read the one
        // bound resolver — see FeatureScope.
        return $this->scoped($features, fn (): ResponseInterface => $this->dispatch($request, $features));
    }

    private function dispatch(ServerRequestInterface $request, Features $features): ResponseInterface
    {
        $result = $this->router->match($request->getMethod(), $request->getUri()->getPath(), $features);
        if (!$result instanceof Matched) {
            // RouteNotFound | MethodNotAllowed — both are problems, rendered
            // like every other problem, with the fix in the body.
            return $this->unrouted($request, $result);
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

        // Caught OUTSIDE the pipeline, so every global and route middleware
        // meets a handler's throwable first, exactly as it meets a problem: an
        // app's own error page can still answer it. Only what escapes them all
        // is rendered here.
        try {
            return $pipeline->run(
                $request,
                [...$this->globalMiddleware, ...$route->middleware],
                static fn (ServerRequestInterface $r): ResponseInterface => $invoker->invoke($plan, $r, $args),
            );
        } catch (LavaProblem $problem) {
            return $this->problemResponse($problem, $request);
        } catch (\Throwable $throwable) {
            return $this->problemResponse(UnexpectedFailure::inRequest($request, $throwable), $request);
        }
    }

    /**
     * Run a dispatch with `$features` current in the app's {@see FeatureScope}.
     *
     * An App built by hand around a bare container — tests do this — has no
     * scope to set. Dispatch still works there; an injected `Features` is simply
     * whatever that container holds.
     *
     * @template T
     * @param \Closure(): T $work
     * @return T
     */
    private function scoped(Features $features, \Closure $work): mixed
    {
        $scope = $this->container->has(FeatureScope::class) ? $this->container->get(FeatureScope::class) : null;

        return $scope instanceof FeatureScope ? $scope->during($features, $work) : $work();
    }

    /**
     * A request that reached no handler — no route matched, the method was
     * wrong, or the body did not parse — answered through the global middleware
     * all the same.
     *
     * The problem is thrown from where the handler would have been, so each
     * global layer meets it exactly as it meets a handler's problem: a layer
     * that catches it can answer — an app with an HTML face renders its own 404
     * for a mistyped URL, not only for a missing record — and a layer that
     * answers before calling inward still does, so a sign-in gate redirects an
     * anonymous visitor before telling them whether a path exists. A layer that
     * only decorates the response it gets back gets none, exactly as for a
     * handler's problem. Built before the pipeline, as these three once were,
     * they were the only requests no middleware could see.
     *
     * Route middleware does not run: there is no route to have declared any.
     * A body that did not parse reaches the layers unparsed. What escapes them
     * renders exactly as before, in the same media as every other problem and
     * with the 405's `Allow` header — and a layer that itself throws something
     * that is not a problem renders as `unexpected_failure`.
     */
    private function unrouted(ServerRequestInterface $request, LavaProblem $problem): ResponseInterface
    {
        try {
            return (new MiddlewarePipeline($this->container))->run(
                $request,
                $this->globalMiddleware,
                static fn (ServerRequestInterface $unrouted): ResponseInterface => throw $problem,
            );
        } catch (LavaProblem $escaped) {
            return $this->problemResponse($escaped, $request);
        } catch (\Throwable $throwable) {
            return $this->problemResponse(UnexpectedFailure::inRequest($request, $throwable), $request);
        }
    }

    /**
     * A problem as the response the client gets — and, when that response
     * withholds the details, the whole problem in the log.
     *
     * Production sends a server fault without its context or source
     * ({@see HttpErrors::redacts()}), so the details have to reach someone, or a
     * production 500 would be undiagnosable. The app's `LoggerInterface` is where
     * they go: core's `LineLogger` on stderr by default, or whatever logger the
     * app registered under that id. A 4xx is never logged here — it is the
     * client's mistake, and its response already carries everything.
     */
    private function problemResponse(LavaProblem $problem, ServerRequestInterface $request): ResponseInterface
    {
        if (HttpErrors::redacts($problem->httpStatus(), $this->env)) {
            $this->logWithheld($problem);
        }

        return HttpErrors::toResponse($problem, $request, $this->env);
    }

    private function logWithheld(LavaProblem $problem): void
    {
        if (!$this->container->has(LoggerInterface::class)) {
            return;
        }

        try {
            $logger = $this->container->get(LoggerInterface::class);
            if ($logger instanceof LoggerInterface) {
                $logger->error($problem->getMessage(), [
                    'code' => $problem->code(),
                    'fix' => $problem->fix,
                    'context' => $problem->context,
                    'source' => $problem->source?->json(),
                ]);
            }
        } catch (\Throwable) {
            // A logger that cannot write must not turn a rendered 500 into a
            // blank one: the response is still the client's answer.
        }
    }
}
