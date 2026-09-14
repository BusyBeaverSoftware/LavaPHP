<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A route handler breaks the handler contract (no/invalid handler spec, bad signature, wrong return). */
final class BadHandler extends LavaProblem
{
    public function code(): string
    {
        return 'bad_handler';
    }

    public static function of(string $handler, string $reason, string $fix): self
    {
        return new self(
            "Route handler {$handler} is invalid: {$reason}.",
            $fix,
            ['handler' => $handler, 'reason' => $reason],
        );
    }

    /** The route was registered without chaining ->handler() — nothing would be dispatched to it. */
    public static function routeHasNone(string $routeName, string $path, ?SourceLocation $source = null): self
    {
        return new self(
            "Route '{$routeName}' ({$path}) has no handler.",
            "Chain ->handler([\App\YourController::class, 'method'])"
            . " or ->handler('App\your_function') onto the route.",
            ['route' => $routeName, 'path' => $path],
            $source,
        );
    }
}