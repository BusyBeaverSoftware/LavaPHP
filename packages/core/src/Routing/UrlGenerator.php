<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Lava\Core\Problem\BadRoutePattern;
use Lava\Core\Problem\UnknownRoute;

/**
 * Reverses the router: a route name plus params → the path that would match
 * it. Every value is validated against its param type, so a generated URL
 * can never point at a path the router wouldn't match.
 */
final class UrlGenerator
{
    public function __construct(private readonly Router $router)
    {
    }

    /**
     * @param array<string, string|int> $params
     */
    public function url(string $routeName, array $params = []): string
    {
        $route = $this->router->route($routeName)
            ?? throw UnknownRoute::of($routeName, $this->router->nearestName($routeName));

        $seen = [];
        $out = '';
        $path = $route->path;
        $cursor = 0;
        while (($open = strpos($path, '{', $cursor)) !== false) {
            $close = strpos($path, '}', $open);
            if ($close === false) {
                throw new \LogicException("Route '{$routeName}' has an uncompiled path — call Router::finalize() first.");
            }
            $out .= self::encode(substr($path, $cursor, $open - $cursor));
            $spec = substr($path, $open + 1, $close - $open - 1);
            [$name, $type] = explode(':', $spec, 2);
            $seen[$name] = true;

            if (!array_key_exists($name, $params)) {
                throw new BadRoutePattern(
                    "URL for route '{$routeName}' is missing param '{$name}'.",
                    "Pass it: \$url->url('{$routeName}', ['{$name}' => …]).",
                    ['route' => $routeName, 'param' => $name],
                );
            }
            $value = (string) $params[$name];
            $fragment = $this->router->paramRegex($type);
            if ($fragment === null || preg_match(Router::anchored($fragment), $value) !== 1) {
                throw new BadRoutePattern(
                    "Value '{$value}' for param '{$name}' does not match type '{$type}' of route '{$routeName}'.",
                    "Use a value the route would match — the type's pattern is '{$fragment}'.",
                    ['route' => $routeName, 'param' => $name, 'value' => $value, 'type' => $type],
                );
            }
            $out .= self::encode($value);
            $cursor = $close + 1;
        }
        $out .= self::encode(substr($path, $cursor));

        $extra = array_diff_key($params, $seen);
        if ($extra !== []) {
            throw new BadRoutePattern(
                "Route '{$routeName}' has no param '" . array_key_first($extra) . "'.",
                "Its params are: " . ($seen === [] ? '(none)' : implode(', ', array_keys($seen))) . '.',
                ['route' => $routeName, 'extra_params' => array_keys($extra)],
            );
        }

        // A path whose second character is a slash or a backslash is not a path
        // to a browser: `//evil.example/x` and `/\evil.example` both name another
        // host. A value can put either there, a `path` value starting with `/` or
        // a `str` value starting with `\`, so that character is percent-encoded
        // and the URL stays on this site (Lava Notes, R3-B1).
        if (strlen($out) > 1 && ($out[1] === '/' || $out[1] === '\\')) {
            $out = '/' . rawurlencode($out[1]) . substr($out, 2);
        }

        return $out;
    }

    /**
     * A value or a static segment as it goes into a URL: percent-encoded, with
     * `/` left alone.
     *
     * The value was already validated against its param type, so a spanning
     * type's `/` is part of the value the router will match, and encoding it
     * would stop the URL matching (Lava Notes, R3-B3). Everything else that a
     * URI reserves — a space, `?`, `#`, `%`, a non-ASCII byte — is encoded, so
     * the path a browser sends decodes back to exactly this value. `App`
     * decodes the path once before matching, which is the other half.
     */
    private static function encode(string $text): string
    {
        $encoded = str_replace('%2F', '/', rawurlencode($text));

        // A `.` or `..` that is a whole segment is not a name, it is an
        // instruction to the client: `/a/../b` resolves to `/b`, a path this
        // route would not match, which is the one thing url() promises cannot
        // happen (security review, F6).
        return implode('/', array_map(
            static fn (string $segment): string => $segment === '.' ? '%2E' : ($segment === '..' ? '%2E%2E' : $segment),
            explode('/', $encoded),
        ));
    }
}