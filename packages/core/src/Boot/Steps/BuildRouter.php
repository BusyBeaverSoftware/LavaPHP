<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Container\Container;
use Lava\Core\Modules\ProvidesRoutes;
use Lava\Core\Problem\BadMiddleware;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Problem\SourceLocation;
use Lava\Core\Routing\HandlerInvoker;
use Lava\Core\Routing\Router;
use Lava\Core\Routing\UrlGenerator;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Runs app/Routes.php, compiles the router, and freezes everything dispatch
 * needs: gate-name validation, middleware validation, and handler injection
 * plans (the sanctioned boot-time reflection). After this step there is no
 * reflection and no code-loading left in the request path — dispatch is
 * pure lookup and call.
 */
final class BuildRouter implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->container === null || $ctx->appContext === null || $ctx->features === null) {
            return; // a fatal upstream already stopped the chain
        }

        $router = new Router();
        $ctx->router = $router;

        // app/Routes.php — THE routes file. Missing file means "no routes":
        // zero-config apps are valid (every request is then a clean 404).
        $routesFile = $ctx->appPath('Routes.php');
        if (is_file($routesFile)) {
            $loader = require $routesFile;
            if (!is_callable($loader)) {
                $ctx->problems->add(new InvalidConfig(
                    'app/Routes.php must return a callable that registers routes.',
                    'End the file with: return function (Router $r): void { … };',
                    ['file' => 'app/Routes.php'],
                    SourceLocation::of($routesFile, 1),
                ));
            } else {
                try {
                    $loader($router);
                } catch (LavaProblem $problem) {
                    // Keep going: whatever registered cleanly still compiles —
                    // one bad ->add() call must not hide the state of the rest.
                    $ctx->problems->add($problem);
                }
            }
        }

        // Module routes, after the app's own: on any path overlap the app's
        // registration wins — the app can always override a pack route.
        foreach ($ctx->modules as $module) {
            if (!$module instanceof ProvidesRoutes) {
                continue;
            }
            try {
                $module->routes($router);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            }
        }

        $ctx->globalMiddleware = $this->loadGlobalMiddleware($ctx);

        // Compile everything; bad routes are already on the report by name.
        $router->finalize($ctx->problems);

        // The router and its reversal are services like any other, registered
        // here because this is the step that builds them — and registered at
        // all so a handler or a pack can reach them BY TYPE instead of closing
        // over the boot context, which no handler or pack has.
        //
        // Order matters twice, and both are load-bearing. After app/Services.php,
        // so an app that registered these ids gets a duplicate_service naming both
        // sites rather than silently losing its own. And BEFORE the injection plans
        // below, because a plan asks the container whether each parameter's type
        // is registered: registered after them, as they once were, a handler that
        // type-hinted UrlGenerator failed its plan with a service_not_registered
        // whose fix — register it yourself — was itself a duplicate_service. A
        // pack's factory may still depend on UrlGenerator even though the pack's
        // register() ran earlier: the closure is called at resolution, not there.
        $ctx->container->singleton(Router::class, static fn (): Router => $router);
        $ctx->container->singleton(UrlGenerator::class, static fn (): UrlGenerator => new UrlGenerator($router));

        // Every distinct ->when() gate must be a defined flag that resolves in
        // this env — a typo in a gate is the classic silent 404, so it fails boot.
        foreach ($this->distinctGateNames($router) as $feature) {
            try {
                $ctx->features->resolve($feature);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            }
        }

        // Middleware resolves from the container per request; validated once here.
        foreach ($this->middlewareUsages($router, $ctx->globalMiddleware) as $class => $where) {
            try {
                self::validateMiddleware($class, $where, $ctx->container);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            }
        }

        // Injection plans for every compiled route — including gated ones: the
        // gate is per-subject and can be on for someone, so the plan must exist.
        // A service a handler takes but nothing registered is reported at the
        // route that takes it, with the pack's feature as the fix when the id is a
        // switched-off pack's (R3-B12).
        $invoker = new HandlerInvoker($ctx->container);
        foreach ($router->routes() as $route) {
            try {
                $router->attachPlan($route->name, $invoker->plan($route->handler));
            } catch (ServiceNotRegistered $problem) {
                $ctx->problems->add($problem->forRoute($route->name, $router->declaredAt($route->name), $ctx->disabledModules));
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            }
        }
    }

    /** @return list<string> class-strings from the optional app/Middleware.php */
    private function loadGlobalMiddleware(BootCtx $ctx): array
    {
        $file = $ctx->appPath('Middleware.php');
        if (!is_file($file)) {
            return [];
        }
        $list = require $file;
        if (!is_array($list)) {
            $ctx->problems->add(new InvalidConfig(
                'app/Middleware.php must return a list of middleware class-strings.',
                'End the file with: return [\App\Http\YourMiddleware::class];',
                ['file' => 'app/Middleware.php'],
                SourceLocation::of($file, 1),
            ));
            return [];
        }

        $classes = [];
        foreach ($list as $entry) {
            if (is_string($entry)) {
                $classes[] = $entry;
                continue;
            }
            $ctx->problems->add(new InvalidConfig(
                'app/Middleware.php entries must be middleware class-strings.',
                'Use class-strings: return [\App\Http\YourMiddleware::class];',
                ['file' => 'app/Middleware.php', 'entry' => is_scalar($entry) ? (string) $entry : get_debug_type($entry)],
                SourceLocation::of($file, 1),
            ));
        }
        return $classes;
    }

    /** @return list<string> every distinct ->when() name, in first-use order */
    private function distinctGateNames(Router $router): array
    {
        $names = [];
        foreach ($router->routes() as $route) {
            if ($route->feature !== null) {
                $names[$route->feature] = true;
            }
        }
        return array_keys($names);
    }

    /**
     * Every middleware class the app can reach (global list + route lists),
     * each mapped to the first place it is used — so a problem names one
     * clear location, not "somewhere".
     *
     * @param list<string> $global
     * @return array<string, string> class => where
     */
    private function middlewareUsages(Router $router, array $global): array
    {
        $usages = [];
        foreach ($global as $class) {
            $usages[$class] ??= 'app/Middleware.php (global)';
        }
        foreach ($router->routes() as $route) {
            foreach ($route->middleware as $class) {
                $usages[$class] ??= "route '{$route->name}'";
            }
        }
        return $usages;
    }

    /**
     * @throws LavaProblem the exact problem when this middleware cannot run
     */
    private static function validateMiddleware(string $class, string $where, Container $container): void
    {
        if (!class_exists($class)) {
            throw BadMiddleware::missing($class, $where);
        }
        if (!is_a($class, MiddlewareInterface::class, true)) {
            throw BadMiddleware::notPsr15($class, $where);
        }
        if (!$container->has($class)) {
            throw ServiceNotRegistered::of($class, $where, 'middleware is resolved from the container');
        }
    }
}