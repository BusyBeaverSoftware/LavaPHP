<?php

declare(strict_types=1);

namespace Lava\Core\Features;

use Lava\Core\Problem\DuplicateFeature;

/** All defined flags, in declaration order. Duplicate names are fatal. */
final class FeatureSet
{
    /** @var array<string, Feature> insertion-ordered */
    private array $features = [];

    /** @var array<string, string> name => where it was declared */
    private array $declaredAt = [];

    public function add(Feature $feature, string $declaredAt): void
    {
        if (isset($this->features[$feature->name])) {
            throw DuplicateFeature::of($feature->name, $this->declaredAt[$feature->name], $declaredAt);
        }
        $this->features[$feature->name] = $feature;
        $this->declaredAt[$feature->name] = $declaredAt;
    }

    public function get(string $name): ?Feature
    {
        return $this->features[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->features[$name]);
    }

    /** @return list<Feature> */
    public function all(): array
    {
        return array_values($this->features);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->features);
    }

    public function declaredAt(string $name): ?string
    {
        return $this->declaredAt[$name] ?? null;
    }

    /**
     * Nearest defined name by edit distance, or null when nothing is close
     * enough to be a plausible typo (distance > 3).
     */
    public function nearest(string $name): ?string
    {
        $best = null;
        $bestDistance = 4;
        foreach ($this->features as $candidate => $feature) {
            $distance = levenshtein($name, $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        return $best;
    }
}