<?php

declare(strict_types=1);

namespace Lava\Core\Problem;

/** A flag name is defined twice (config 'define' + pack declarations are all checked). */
final class DuplicateFeature extends LavaProblem
{
    public function code(): string
    {
        return 'duplicate_feature';
    }

    public static function of(string $name, string $firstAt, string $secondAt): self
    {
        return new self(
            "Feature '{$name}' is defined twice.",
            "Remove the definition at {$secondAt} (the one at {$firstAt} was first).",
            ['name' => $name, 'first_defined_at' => $firstAt, 'second_defined_at' => $secondAt],
        );
    }
}