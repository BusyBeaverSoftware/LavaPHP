<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Problem\SourceLocation;

/** Two routes share a name. Route names are the stable addressing scheme (URL generation, redirects) — duplicates are fatal. */
final class DuplicateRouteName extends LavaProblem
{
    public function code(): string
    {
        return 'duplicate_route_name';
    }

    public static function of(string $name, SourceLocation $first, SourceLocation $second): self
    {
        return new self(
            "Route name '{$name}' is registered twice.",
            "Remove the registration at {$second} (the one at {$first} was first), or use a distinct name.",
            ['name' => $name, 'first_registered_at' => (string) $first, 'second_registered_at' => (string) $second],
            $second,
        );
    }
}