<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * `lava <typo>` — the invocation named a command this process doesn't have.
 * The nearest registered name is offered so the fix is a copy-paste, not a
 * `lava list` round trip.
 */
final class UnknownCommand extends LavaProblem
{
    public static function of(string $name, ?string $nearest): self
    {
        return new self(
            "No command named '{$name}'.",
            $nearest !== null ? "Run: lava {$nearest} --json" : 'Run: lava list --json',
            $nearest !== null ? ['command' => $name, 'nearest' => $nearest] : ['command' => $name],
        );
    }

    public function code(): string
    {
        return 'unknown_command';
    }
}
