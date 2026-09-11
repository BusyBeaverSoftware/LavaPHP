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

    public function code(): string
    {
        return 'bad_usage';
    }
}
