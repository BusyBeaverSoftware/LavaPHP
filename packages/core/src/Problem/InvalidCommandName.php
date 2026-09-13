<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;

/**
 * A command's name cannot be a command name.
 *
 * The name is more than what a caller types: every `--json` envelope names its
 * contract `lava.<name>/N`, with colons turned into dots, and the envelope's own
 * schema admits only lowercase letters, digits and dots there. So
 * `blog:publish-due` ran, and every envelope it emitted failed the pattern of the
 * contract it claimed.
 *
 * **A warning, not a fatal.** Commands are registered on every boot, the web
 * request's included, so a fatal here would take a working website down over a
 * CLI naming rule. The command still registers and runs; `lava check` reports the
 * rename, and `lava check --strict` fails until it is made.
 */
final class InvalidCommandName extends LavaProblem
{
    public function severity(): Severity
    {
        return Severity::Warn;
    }

    public static function of(Command $command): self
    {
        $name = $command->name();
        $suggestion = self::suggestion($name);

        return new self(
            "Command name '{$name}' (from {$command->pack()}) is not a valid command name: it becomes the "
                . "`lava.<name>/N` schema id of every --json envelope, which admits only lowercase letters, digits and dots.",
            $suggestion !== null
                ? "Rename it to '{$suggestion}' — lowercase letters and digits, with colons between words."
                : 'Rename it to lowercase letters and digits, starting with a letter, with colons between words (`blog:publish`).',
            ['name' => $name, 'pack' => $command->pack(), 'pattern' => CommandRegistry::NAME_PATTERN],
        );
    }

    /** The nearest valid name — `Report:Daily-Stats` → `report:daily:stats` — or null when none is close. */
    private static function suggestion(string $name): ?string
    {
        $words = preg_split('/[^a-z0-9]+/', strtolower($name), -1, PREG_SPLIT_NO_EMPTY);
        $candidate = implode(':', $words === false ? [] : $words);

        return preg_match(CommandRegistry::NAME_PATTERN, $candidate) === 1 ? $candidate : null;
    }

    public function code(): string
    {
        return 'invalid_command_name';
    }
}
