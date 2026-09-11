<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/**
 * The matched route plus its typed args. Matching is a pure pattern
 * operation: it works on any finalized router. The handler's injection plan
 * stays on the Router (BuildRouter attaches one per compiled route) and is
 * fetched by name at dispatch time.
 */
final readonly class Matched
{
    public function __construct(
        public Route $route,
        public RouteArgs $args,
    ) {
    }
}