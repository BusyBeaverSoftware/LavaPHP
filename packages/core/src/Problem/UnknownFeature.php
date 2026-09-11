<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A flag name was never defined anywhere. Undefined flags are NEVER silently false. */
final class UnknownFeature extends LavaProblem
{
    public function code(): string
    {
        return 'unknown_feature';
    }

    public static function of(string $name, ?string $nearest = null): self
    {
        return new self(
            "Feature '{$name}' is not defined anywhere.",
            $nearest !== null
                ? "Did you mean '{$nearest}'? It is defined in config/features.php."
                : "Define it in config/features.php under 'define', or remove every reference to '{$name}'.",
            array_filter(['name' => $name, 'nearest' => $nearest], static fn ($v) => $v !== null),
        );
    }
}