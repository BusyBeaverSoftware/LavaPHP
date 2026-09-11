<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Console\Command;

/**
 * Two commands claim the same name.
 *
 * A collision would be silent shadowing — the last registration wins and the
 * first is unreachable, with nothing anywhere saying so. That is the exact
 * failure mode the framework bans: `lava routes` must mean the same thing in
 * every app, and an agent that learned a command name must not have it
 * quietly stop working because a pack claimed it.
 *
 * Both sides are named because the fix is a choice: rename one, or drop it.
 */
final class DuplicateCommand extends LavaProblem
{
    public static function of(string $name, Command $existing, Command $incoming): self
    {
        $existingPack = $existing->pack();
        $incomingPack = $incoming->pack();

        // Naming the pack twice reads as a bug in the message, not the app:
        // "provided by core; core provides it too". Both commands claim the
        // same pack when the incoming one never overrode pack() — which is
        // itself a likely cause, so the fix points at it.
        $samePack = $existingPack === $incomingPack;

        return new self(
            $samePack
                ? "Command '{$name}' is already provided by {$existingPack}, and was registered again."
                : "Command '{$name}' is already provided by {$existingPack}; {$incomingPack} provides it too.",
            $samePack
                ? "Remove the second registration of '{$name}' — if it belongs to a pack, that command must "
                    . 'override pack() to name it. Command names are unique across an app, and the first '
                    . 'registration is the one that runs.'
                : "Rename or remove '{$name}' in {$incomingPack} — command names are unique across an app, "
                    . 'and the first registration is the one that runs.',
            [
                'name' => $name,
                'existing_pack' => $existingPack,
                'incoming_pack' => $incomingPack,
            ],
        );
    }

    public function code(): string
    {
        return 'duplicate_command';
    }
}
