<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Lava\Core\Features\Features;
use Lava\Core\Problem\BadHandler;
use Lava\Core\Problem\BadRoutePattern;
use Lava\Core\Problem\DuplicateRouteName;
use Lava\Core\Problem\MethodNotAllowed;
use Lava\Core\Problem\ProblemReport;
use Lava\Core\Problem\RouteNotFound;
use Lava\Core\Problem\SourceLocation;

/**
 * The route registry and matcher. Registration is explicit and names are
 * mandatory; compilation happens once at finalize(); matching is a linear
 * scan over compiled regexes in registration order (deterministic, fine to
 * several hundred routes — tree optimization is a non-goal).
 *
 * Gating: a route with a ->when() feature is ABSENT from matching whenever
 * the flag is off for the current subject resolver — a real 404, never a 503.
 * With no Features in hand, gated routes are off (fail-closed).
 */
final class Router
{
    private const BUILTIN_PATTERNS = [
        'int' => '\d+',
        'str' => '[^/]+',
        'uuid' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}',
        'path' => '.+',
    ];

    /** @var array<string, string> param type name => regex fragment */
    private array $patterns = self::BUILTIN_PATTERNS;

    /** @var array<string, RouteBuilder> pending routes by name, in registration order */
    private array $builders = [];

    /** @var array<string, SourceLocation> route name => first registration site */
    private array $declaredAt = [];

    /** @var array<string, Route> compiled routes by name, in registration order */
    private array $routes = [];

    /** @var array<string, HandlerPlan> route name => handler injection plan */
    private array $plans = [];

    private bool $finalized = false;

    /** Registers a custom param type usable as {name:type} in route paths. */
    public function pattern(string $name, string $regex): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new BadRoutePattern(
                "Param type name '{$name}' is invalid.",
                "Param type names are snake_case — e.g. \$r->pattern('handle', '[a-z0-9_]{2,32}').",
                ['type' => $name],
            );
        }
        if (isset($this->patterns[$name])) {
            $isBuiltin = isset(self::BUILTIN_PATTERNS[$name]);
            throw new BadRoutePattern(
                "Param type '{$name}' is already defined" . ($isBuiltin ? ' as a builtin type.' : '.'),
                $isBuiltin
                    ? "Choose a different name — builtins are: " . implode(', ', array_keys(self::BUILTIN_PATTERNS)) . '.'
                    : "Remove one of the two \$r->pattern('{$name}', …) calls.",
                ['type' => $name, 'builtin' => $isBuiltin],
            );
        }
        // Probe-compile the fragment by matching against ''. The @ silences
        // preg's own raw diagnostic for invalid fragments — the BadRoutePattern
        // below is the report we want, in the user's grammar with a fix.
        if ($regex === '' || @preg_match(self::anchored($regex), '') === false) {
            throw new BadRoutePattern(
                "Param type '{$name}' has an invalid regex fragment '{$regex}'.",
                "Provide a valid regex fragment without delimiters or anchors — e.g. '[a-z0-9_]{2,32}'.",
                ['type' => $name, 'regex' => $regex],
            );
        }
        $this->patterns[$name] = $regex;
    }

    /** The generic registration: every method stated explicitly. */
    public function add(string $path, string $name, Method ...$methods): RouteBuilder
    {
        if ($this->finalized) {
            throw new \LogicException('The router is finalized; routes can no longer be added.');
        }
        if (!str_starts_with($path, '/')) {
            throw BadRoutePattern::of($path, 'paths must start with /', "Write the path as '/{$path}'");
        }
        if (preg_match('/^[a-z][a-z0-9_.]*$/', $name) !== 1) {
            throw new BadRoutePattern(
                "Route name '{$name}' is invalid.",
                "Route names are lowercase and dot-separated — e.g. 'users.show'.",
                ['name' => $name],
            );
        }
        if ($methods === []) {
            throw BadRoutePattern::of(
                $path,
                'no HTTP method declared',
                "Pass the methods: \$r->add('{$path}', '{$name}', Method::Get, …).",
            );
        }
        if (isset($this->builders[$name]) || isset($this->routes[$name])) {
            throw DuplicateRouteName::of($name, $this->declaredAt[$name], self::caller());
        }

        $builder = new RouteBuilder($name, $path, array_values($methods));
        $this->builders[$name] = $builder;
        $this->declaredAt[$name] = self::caller();
        return $builder;
    }

    public function get(string $path, string $name): RouteBuilder
    {
        return $this->add($path, $name, Method::Get);
    }

    public function post(string $path, string $name): RouteBuilder
    {
        return $this->add($path, $name, Method::Post);
    }

    public function put(string $path, string $name): RouteBuilder
    {
        return $this->add($path, $name, Method::Put);
    }

    public function patch(string $path, string $name): RouteBuilder
    {
        return $this->add($path, $name, Method::Patch);
    }

    public function delete(string $path, string $name): RouteBuilder
    {
        return $this->add($path, $name, Method::Delete);
    }

    /**
     * Compiles every pending builder into a Route. Bad routes become problems
     * on the shared report and are skipped — one bad route never hides the
     * others or blocks the rest of boot.
     */
    public function finalize(ProblemReport $problems): void
    {
        foreach ($this->builders as $name => $builder) {
            $parts = $builder->parts();
            try {
                if ($parts['handler'] === null) {
                    throw BadHandler::routeHasNone($name, $parts['path']);
                }
                [$regex, $params] = $this->compile($parts['path']);

                // Each fragment compiled on its own in pattern(); together they
                // can still clash, and a route regex that does not compile would
                // warn and miss on every request instead of failing here, once.
                if (@preg_match('#^' . $regex . '$#', '') === false) {
                    throw BadRoutePattern::of(
                        $parts['path'],
                        'its compiled pattern is not a valid regex',
                        'Check the custom types it uses: a fragment must not name a group of its own, and must be valid on its own.',
                    );
                }
            } catch (\Lava\Core\Problem\LavaProblem $problem) {
                $problems->add($problem);
                continue;
            }
            $this->routes[$name] = new Route(
                $name,
                $parts['path'],
                $parts['methods'],
                $parts['handler'],
                $parts['middleware'],
                $parts['feature'],
                $regex,
                $params,
            );
        }

        $this->builders = [];
        $this->finalized = true;
    }

    /**
     * Matches a request against the compiled routes, in registration order.
     * Gated routes whose flag is off are skipped entirely — off means absent.
     *
     * @return Matched|RouteNotFound|MethodNotAllowed the two misses are problems,
     *         so they render identically in every medium.
     */
    public function match(string $method, string $path, ?Features $features = null): Matched|RouteNotFound|MethodNotAllowed
    {
        if (!$this->finalized && $this->builders !== []) {
            throw new \LogicException('Router::match() called before finalize() — boot must compile the routes first.');
        }

        $methodEnum = Method::tryFrom($method);
        $pathMatched = false;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (preg_match('#^' . $route->regex . '$#', $path, $captures) !== 1) {
                continue;
            }
            if ($route->feature !== null
                && ($features === null || !$features->on($route->feature))) {
                continue; // the gate is off — the route is absent
            }
            $pathMatched = true;
            if ($methodEnum === null || !in_array($methodEnum, $route->methods, true)) {
                foreach ($route->methodNames() as $allowedMethod) {
                    $allowed[$allowedMethod] = true;
                }
                continue;
            }

            $args = [];
            foreach (array_keys($route->params) as $param) {
                $value = $captures[$param];
                $args[$param] = $route->params[$param] === 'int' ? (int) $value : $value;
            }
            return new Matched($route, new RouteArgs($route->name, $args));
        }

        if ($pathMatched) {
            return MethodNotAllowed::of($method, $path, array_keys($allowed));
        }
        return RouteNotFound::of($method, $path);
    }

    public function route(string $name): ?Route
    {
        return $this->routes[$name] ?? null;
    }

    /** @return list<Route> in registration order */
    public function routes(): array
    {
        return array_values($this->routes);
    }

    /** @return list<string> every registered name, in registration order */
    public function names(): array
    {
        return array_keys($this->routes);
    }

    /** Nearest registered name by edit distance — the typo hint for URL generation. */
    public function nearestName(string $name): ?string
    {
        $best = null;
        $bestDistance = 4;
        foreach (array_keys($this->routes) as $candidate) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return $best;
    }

    /** Boot-time: the handler's injection plan, attached by HandlerInvoker once per route. */
    public function attachPlan(string $name, HandlerPlan $plan): void
    {
        if (!isset($this->routes[$name])) {
            throw new \LogicException("No route named '{$name}' — plans attach to finalized routes.");
        }
        $this->plans[$name] = $plan;
    }

    /** The handler's injection plan for a route — attached at boot, never computed at runtime. */
    public function plan(string $name): HandlerPlan
    {
        return $this->plans[$name]
            ?? throw new \LogicException("Route '{$name}' has no injection plan — BuildRouter must attach one.");
    }

    public function paramRegex(string $type): ?string
    {
        return $this->patterns[$type] ?? null;
    }

    /**
     * A fragment as a whole-value regex, `#^(?:…)$#`, with its `#` escaped.
     *
     * pattern(), compile(), match() and URL generation all use this one
     * delimiter (Lava Notes, R2-B2). They used to mix two: a fragment was
     * checked and turned into URLs inside `/…/`, where a `/` ended the pattern
     * (so `[a-z]+(?:/[a-z]+)*` was refused and core's own `str`, `[^/]+`, could
     * not generate a URL), and matched inside `#…#`, where a `#` did.
     */
    public static function anchored(string $fragment): string
    {
        return '#^(?:' . self::escapeDelimiter($fragment) . ')$#';
    }

    /** Every `#` a backslash does not already escape, escaped. */
    private static function escapeDelimiter(string $fragment): string
    {
        return preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)#/', '$1\\#', $fragment) ?? $fragment;
    }

    /**
     * Compiles a path into a regex and its param map.
     *
     * @return array{0: string, 1: array<string, string>} [regex without delimiters, params]
     */
    private function compile(string $path): array
    {
        $regex = '';
        $params = [];
        $cursor = 0;
        while (($open = strpos($path, '{', $cursor)) !== false) {
            $close = strpos($path, '}', $open);
            if ($close === false) {
                throw BadRoutePattern::of($path, 'unclosed placeholder', 'Close it: {name:type}');
            }
            $regex .= preg_quote(substr($path, $cursor, $open - $cursor), '#');
            $spec = substr($path, $open + 1, $close - $open - 1);
            $colon = strpos($spec, ':');
            if ($colon === false) {
                throw BadRoutePattern::of(
                    $path,
                    "param '{$spec}' has no explicit type",
                    "Write {name:type} — the types are int, str, uuid, path, plus any \$r->pattern() type",
                );
            }
            $name = substr($spec, 0, $colon);
            $type = substr($spec, $colon + 1);
            if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
                throw BadRoutePattern::of($path, "param name '{$name}' must be snake_case", "Write {<snake_case>:{$type}}");
            }
            if (isset($params[$name])) {
                throw BadRoutePattern::of($path, "param '{$name}' appears twice", 'Use a distinct name for each placeholder');
            }
            $fragment = $this->patterns[$type] ?? null;
            if ($fragment === null) {
                throw BadRoutePattern::of(
                    $path,
                    "unknown param type '{$type}'",
                    "The types are int, str, uuid, path — register customs with \$r->pattern('{$type}', '…')",
                );
            }
            $regex .= '(?P<' . $name . '>' . self::escapeDelimiter($fragment) . ')';
            $params[$name] = $type;
            $cursor = $close + 1;
        }
        $regex .= preg_quote(substr($path, $cursor), '#');

        return [$regex, $params];
    }

    private static function caller(): SourceLocation
    {
        // Walk past this class's own frames — caller(), add(), and the
        // get/post/… convenience wrappers all live in this file, and each
        // wrapper adds a frame, so no fixed index works. The first frame from
        // another file is the $r->get(...) call site in app/Routes.php; a
        // closure's own frame (which reports where BuildRouter INVOKED it,
        // not a user line) is always deeper than that, never first.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || $file === __FILE__) {
                continue;
            }
            return SourceLocation::of($file, $frame['line'] ?? 0);
        }
        return SourceLocation::of('unknown', 0);
    }
}