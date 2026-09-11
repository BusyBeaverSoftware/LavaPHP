<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A Flag was constructed or parsed with an invalid value (bad percentage, unparsable syntax, nested per-env). */
final class InvalidFlagValue extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_flag_value';
    }

    public const GRAMMAR = 'on | off | rollout:<0-100> | users:<id,id,…> | env:<name=on|off|…>';

    public static function of(string $value, string $reason): self
    {
        return new self(
            "Invalid flag value '{$value}': {$reason}.",
            'Use one of: ' . self::GRAMMAR,
            ['value' => $value, 'reason' => $reason],
        );
    }
}