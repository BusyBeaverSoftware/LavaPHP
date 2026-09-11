<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** URL generation asked for a route name that doesn't exist (with the nearest registered name as the hint). */
final class UnknownRoute extends LavaProblem
{
    public function code(): string
    {
        return 'unknown_route';
    }

    public static function of(string $name, ?string $nearest = null): self
    {
        return new self(
            "Route '{$name}' is not registered.",
            $nearest !== null
                ? "Did you mean '{$nearest}'? Run: lava routes --json to list every registered name."
                : 'Run: lava routes --json to list every registered name.',
            array_filter(['name' => $name, 'nearest' => $nearest], static fn ($v) => $v !== null),
        );
    }
}