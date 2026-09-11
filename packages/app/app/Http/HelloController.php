<?php

declare(strict_types=1);

namespace App\Http;

use App\Greeter;
use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Psr\Http\Message\ResponseInterface;

/**
 * The shape of every controller in this app.
 *
 * A handler class is constructed with NO arguments and its dependencies arrive
 * as typed METHOD parameters. That is the whole point: the dependency story of
 * a route is then visible in `lava routes --json`, and boot checks it before a
 * single request arrives. A constructor argument would hide it — so boot
 * refuses one and reports `bad_handler`.
 *
 * Every parameter is always injected, and only these three kinds are legal:
 *
 *   - `ServerRequestInterface` / `RequestInterface` — the request itself;
 *   - `RouteArgs` — the matched route's typed parameters;
 *   - a registered container id — a service from `app/Services.php`.
 *
 * Scalars, unions, defaults and variadics are rejected at boot, each with the
 * signature to write instead. The `: ResponseInterface` return type is
 * likewise required, so a handler that forgets to return a response is a boot
 * failure rather than a 500 discovered by a user.
 */
final class HelloController
{
    /** `/hello/{name:str}` — the route param and a service, both by type. */
    public function show(RouteArgs $args, Greeter $greeter): ResponseInterface
    {
        return Responses::json(['hello' => $greeter->greet($args->str('name'))]);
    }

    /**
     * Reached only while the `beta_greeting` flag is on — otherwise this method
     * is never dispatched and the route answers a real 404.
     */
    public function beta(Greeter $greeter): ResponseInterface
    {
        return Responses::json(['hello' => $greeter->greet('beta')]);
    }
}
