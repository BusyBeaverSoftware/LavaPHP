<?php

declare(strict_types=1);

namespace Lava\Core\Features;

use Lava\Core\Problem\InvalidFeatureName;

/**
 * A flag DEFINITION: a name, the code default (bottom of the resolution
 * order), the pack it gates (if any), and a human/agent-readable description.
 */
final readonly class Feature
{
    public function __construct(
        public string $name,
        public Flag $default,
        public ?string $pack,
        public string $description,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $name) !== 1) {
            throw InvalidFeatureName::of($name);
        }
    }

    public static function define(string $name, Flag $default, ?string $pack = null, string $description = ''): self
    {
        return new self($name, $default, $pack, $description);
    }
}