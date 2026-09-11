<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/**
 * A CLI invocation was malformed in a way the flag parser can't catch — a
 * required positional is missing (`lava features resolve` with no flag), or a
 * value doesn't fit its flag. Distinct from `unknown_command`: the command
 * exists, the arguments don't. The fix always shows the correct invocation.
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
