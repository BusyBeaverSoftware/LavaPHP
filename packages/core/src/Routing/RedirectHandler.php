<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

use Lava\Core\Http\Responses;
use Lava\Core\Problem\BadRedirect;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The handler behind every `$r->redirect()` route: a redirect to the target
 * route's URL, filled from this route's params, with the query string kept.
 *
 * The target is read from the Router, where `redirect()` recorded it and
 * `finalize()` checked it, rather than closed over: a handler has to be a class
 * the handler contract can plan and `lava routes` can name, and a route's
 * target is a fact the map shows.
 *
 * The URL is always a path on this site, because `UrlGenerator` never builds
 * one that names another host (R3-B1). What boot cannot rule out is a redirect
 * whose own pattern also matches its target's URLs and is registered first: that
 * redirect is asked for its target's address and would answer with it forever,
 * so the request fails with `bad_redirect` at the redirect's line instead of
 * sending a browser round a loop (R3-B4).
 */
final class RedirectHandler
{
    public function handle(ServerRequestInterface $request, RouteArgs $args, Router $router, UrlGenerator $url): ResponseInterface
    {
        $target = $router->redirectTarget($args->routeName)
            ?? throw new \LogicException("Route '{$args->routeName}' is not a redirect: only Router::redirect() routes use RedirectHandler.");
        $route = $router->route($target['to'])
            ?? throw new \LogicException("Route '{$args->routeName}' redirects to '{$target['to']}', which finalize() should have refused.");

        $location = $url->url($target['to'], array_intersect_key($args->all(), $route->params));
        if ($location === $request->getUri()->getPath()) {
            throw BadRedirect::loops($args->routeName, $target['to'], $location, $router->declaredAt($args->routeName));
        }

        $query = $request->getUri()->getQuery();

        return Responses::redirect($query === '' ? $location : $location . '?' . $query, $target['status']);
    }
}
