<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * `lava describe <selector>` was given a name that matches no route, service,
 * flag, env var, or command.
 *
 * The context carries the candidate set by kind, so an agent that guessed a
 * selector can see every namespace it could have meant without a second call —
 * `lava describe` is the one command whose whole job is disambiguation.
 */
final class UnknownSelector extends LavaProblem
{
    /** @param array<string, mixed> $context */
    public static function of(string $selector, ?string $nearest, array $context): self
    {
        // With a near miss, the app almost certainly declares what was meant. With
        // none, the likeliest cause is that the name belongs to the FRAMEWORK
        // rather than to this app — `describe` answers for the app's routes,
        // services, flags, env vars and commands, and `lava api` for everything
        // the framework itself offers.
        $fix = $nearest !== null
            ? "Run: lava describe {$nearest}"
            : "Run: lava api --search={$selector}  (this app declares nothing by that name; that searches the framework's own API, and `lava list` lists the app's commands).";

        return new self(
            $nearest !== null
                ? "Nothing is named '{$selector}' — did you mean '{$nearest}'?"
                : "Nothing is named '{$selector}'.",
            $fix,
            ['selector' => $selector, 'nearest' => $nearest] + $context,
        );
    }

    public function code(): string
    {
        return 'unknown_selector';
    }
}
