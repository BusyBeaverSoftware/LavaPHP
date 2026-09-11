<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * No route matched — the framework's 404, as a problem so it renders
 * identically in every medium (HTTP diagnostics page, JSON, CLI).
 */
final class RouteNotFound extends LavaProblem
{
    public function code(): string
    {
        return 'route_not_found';
    }

    public static function of(string $method, string $path): self
    {
        return new self(
            "No route matches {$method} {$path}.",
            'Run: lava routes --json — check the exact path, method, and any feature gate (gated routes are absent while their flag is off).',
            ['method' => $method, 'path' => $path],
        );
    }
}