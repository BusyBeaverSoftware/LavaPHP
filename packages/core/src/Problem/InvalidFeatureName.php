<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A feature name doesn't match the naming rule. */
final class InvalidFeatureName extends LavaProblem
{
    public function code(): string
    {
        return 'invalid_feature_name';
    }

    public static function of(string $name): self
    {
        return new self(
            "Feature name '{$name}' is invalid.",
            "Feature names are snake_case matching [a-z][a-z0-9_]* — for example 'beta_ui'.",
            ['name' => $name],
        );
    }
}