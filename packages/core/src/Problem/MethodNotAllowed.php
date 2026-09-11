<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * The path matched but the method didn't — the framework's 405, as a problem.
 * The allowed methods are in the context and the fix names them.
 */
final class MethodNotAllowed extends LavaProblem
{
    public function code(): string
    {
        return 'method_not_allowed';
    }

    /** @param list<string> $allowed uppercase method names */
    public static function of(string $method, string $path, array $allowed): self
    {
        return new self(
            "Method {$method} is not allowed for {$path}.",
            'This path accepts: ' . implode(', ', $allowed) . '.'
            . ' Add the method to the route, or use one of the accepted methods.',
            ['method' => $method, 'path' => $path, 'allowed' => $allowed],
        );
    }
}