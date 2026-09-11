<?php

declare(strict_types=1);

namespace App\Http;

use App\Upstream\Upstream;
use Lava\Core\Http\Responses;
use Psr\Http\Message\ResponseInterface;

/**
 * The one route that leaves this process.
 *
 * The handler is one statement and has no error branch, and that is the point
 * worth reading. A failure inside the client — a refused connection, a 500 from
 * the upstream, a body that is not JSON — arrives as a `LavaProblem`, and
 * `App::handle()` renders it through the same media a rejected request or a
 * broken boot uses: JSON with the code, the fix and the context for a client,
 * the diagnostics page for a browser. So there is nothing for this handler to
 * do with a failure, and a handler that caught one to re-describe it would be
 * inventing a fourth vocabulary for something already named twice.
 *
 * Compare {@see TasksController::show}, which converts a problem explicitly:
 * that one *constructs* the problem, so it has to say which response it wants.
 * This one receives one, so it does not.
 */
final class UpstreamController
{
    public function health(Upstream $upstream): ResponseInterface
    {
        return Responses::json($upstream->health());
    }
}
