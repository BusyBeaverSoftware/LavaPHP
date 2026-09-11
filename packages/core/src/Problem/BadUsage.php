<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A CLI invocation was malformed in a way the flag parser can't catch — a
 * required positional is missing (`lava features resolve` with no flag), a
 * value doesn't fit its flag, or a flag is not one the command declares.
 * Distinct from `unknown_command`: the command exists, the arguments don't.
 * The fix always shows an invocation that works — the usage line, or `--help`
 * with the flags the command accepts.
 */
final class BadUsage extends LavaProblem
{
    public static function missing(string $argument, string $usage): self
    {
        return new self(
            "Missing required argument <{$argument}>.",
            "Run: {$usage}",
            ['missing' => $argument],
        );
    }

    /**
     * A flag that is neither declared by the command nor read by the kernel.
     *
     * The accepted list is the command's OWN flags, not the universal set, and
     * that is the useful half rather than an omission: `--json`, `--quiet` and
     * `--env` can never reach this error, because the kernel reads them on
     * every command's behalf — and `--help`, the fourth, is the command the fix
     * names next. So the list is exactly the set of flags the caller could have
     * meant, which is what makes printing it worth a line for what is almost
     * always a typo.
     *
     * @param list<string> $accepted the command's declared flags, in any order
     */
    public static function unknownFlag(string $flag, string $command, array $accepted): self
    {
        sort($accepted);

        $list = $accepted === [] ? '' : ' (it accepts ' . implode(', ', array_map(
            static fn (string $name): string => '--' . $name,
            $accepted,
        )) . ')';

        return new self(
            "Unknown flag '--{$flag}'.",
            "Run: lava {$command} --help{$list}",
            ['flag' => $flag, 'command' => $command, 'accepted' => $accepted],
        );
    }

    public static function invalid(string $flag, string $value, string $expected, string $usage): self
    {
        return new self(
            "Invalid value '{$value}' for --{$flag}: {$expected}.",
            "Run: {$usage}",
            ['flag' => $flag, 'value' => $value],
        );
    }

    /**
     * The same refusal for a positional argument, which is a different thing to
     * type and so a different thing to report.
     *
     * Kept separate from {@see invalid()} rather than parameterised, because the
     * value that matters here is the NAME: an agent told "invalid value for
     * --description" tries `--description=...` next and gets a second, different
     * failure. Spelling it `<description>` matches the usage line it is given,
     * so the next attempt is the one that works.
     */
    public static function invalidArgument(string $argument, string $value, string $expected, string $usage): self
    {
        return new self(
            "Invalid value '{$value}' for <{$argument}>: {$expected}.",
            "Run: {$usage}",
            ['argument' => $argument, 'value' => $value],
        );
    }

    public function code(): string
    {
        return 'bad_usage';
    }
}
