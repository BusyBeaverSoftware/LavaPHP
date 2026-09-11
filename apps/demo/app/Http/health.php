<?php

declare(strict_types=1);

namespace App\Http;

use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;

/**
 * Liveness — the one route that must answer without touching anything.
 *
 * It deliberately does not query the database. A health check that fails when a
 * dependency is slow tells a load balancer to pull a pod that is serving
 * perfectly well; `GET /tasks` is the route that proves the database is
 * reachable, and it is the one a monitor should watch for that.
 */
function health(): ResponseInterface
{
    return Responses::json(['status' => 'ok']);
}
