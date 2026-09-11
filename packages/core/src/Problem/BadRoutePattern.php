<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A route path is not a valid pattern: bad placeholder syntax, unknown param type, duplicate param. */
final class BadRoutePattern extends LavaProblem
{
    public function code(): string
    {
        return 'bad_route_pattern';
    }

    public static function of(string $path, string $reason, string $fix, ?SourceLocation $source = null): self
    {
        return new self(
            "Route path '{$path}' is invalid: {$reason}.",
            $fix,
            ['path' => $path, 'reason' => $reason],
            $source,
        );
    }
}