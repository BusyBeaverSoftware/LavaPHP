<?php

declare(strict_types=1);

namespace Lava\View;

use Lava\Core\Features\Features;
use Lava\Core\Routing\UrlGenerator;
use Lava\View\Problem\BadViewCall;
use Twig\TwigFunction;

/**
 * The functions a template may call — and the whole list is here, in one array,
 * because a registry that can grow from anywhere is a registry nobody can read.
 *
 * Twig lets a bundle, an extension, or a stray `addFunction()` call put
 * anything into the template namespace. This pack does the opposite: two
 * functions, both declared in this file, both backed by a service the framework
 * already has. An agent asked "what can I call from a template?" has one file
 * to open and one array to read, and `lava describe` has something finite to
 * describe. Adding a third is a deliberate edit to this method — not something
 * that happens to an app.
 *
 * **Why these two.** They are the two facts a template cannot derive on its
 * own and should never hardcode:
 *
 *  - `url()` turns a route NAME into a path. Hardcoding `/users/7` in a template
 *    means renaming the route's path silently breaks every link, and nothing
 *    warns — the route still matches, the page still renders, and the link
 *    404s. Going through the router means a bad link is impossible: an unknown
 *    name throws `unknown_route` and a missing param throws `bad_route_pattern`,
 *    both at render time with the route named.
 *  - `feature()` answers whether a flag is on. This is what makes gated UI
 *    honest — the template hides the button the same way the router 404s the
 *    route, from the same resolver and the same config. A typo'd flag name is
 *    `unknown_feature` with the nearest real name, not a silently hidden
 *    button.
 *
 * **Both are strict about their arguments.** A template is user-authored code
 * and gets the same treatment as a handler: the wrong shape is a `LavaProblem`
 * naming the template and the fix, never a PHP `TypeError` from inside a
 * compiled template in `var/`. See {@see BadViewCall} for why that distinction
 * is worth four factories.
 */
final class ViewFunctions
{
    /**
     * Every function this pack adds to Twig, in the order they are documented.
     *
     * @return list<TwigFunction>
     */
    public static function registry(UrlGenerator $url, Features $features): array
    {
        return [
            new TwigFunction(
                'url',
                // Declared `mixed` and narrowed by hand rather than typed
                // `string`: Twig calls this from compiled template code with
                // whatever the author wrote, so a typed signature would produce
                // a TypeError at the call site instead of the problem below.
                static fn (mixed $route, mixed $params = []): string => self::url($url, $route, $params),
            ),
            new TwigFunction(
                'feature',
                static fn (mixed $name): bool => self::feature($features, $name),
            ),
        ];
    }

    private static function url(UrlGenerator $url, mixed $route, mixed $params): string
    {
        if (!is_string($route)) {
            throw BadViewCall::routeName($route);
        }
        if (!is_array($params)) {
            throw BadViewCall::urlParams($route, $params);
        }

        return $url->url($route, self::params($route, $params));
    }

    private static function feature(Features $features, mixed $name): bool
    {
        if (!is_string($name)) {
            throw BadViewCall::featureName($name);
        }

        return $features->on($name);
    }

    /**
     * Template params, narrowed to what a URL can hold.
     *
     * A route param is always a string once it is in a path, so the only values
     * that can become one are strings and integers. Everything else — an
     * object, a null, a float, a bool — is a mistake in the template, and the
     * four factories in {@see BadViewCall} exist to name which one.
     *
     * @param array<mixed> $params
     * @return array<string, string|int>
     */
    private static function params(string $route, array $params): array
    {
        $narrowed = [];
        foreach ($params as $key => $value) {
            if (!is_string($key)) {
                // A list was passed where a map belongs: `{id}` is a Twig hash,
                // `[7]` is not, and the difference is invisible in a diff.
                throw BadViewCall::urlParams($route, $params);
            }
            if (!is_string($value) && !is_int($value)) {
                throw BadViewCall::urlParam($route, $key, $value);
            }
            $narrowed[$key] = $value;
        }

        return $narrowed;
    }
}
