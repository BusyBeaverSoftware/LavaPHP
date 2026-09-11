<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/**
 * Deployment overrides, each tagged with the layer it came from and where
 * that layer was declared. Layers are kept per name, in application order —
 * config first, then env — so the resolution trace can show every layer
 * that was consulted, not just the winner.
 */
final class FeatureSettings
{
    /** @var array<string, list<array{source: FlagSource, flag: Flag, at: string}>> */
    private array $layers = [];

    public function set(string $name, Flag $flag, FlagSource $source, string $declaredAt): void
    {
        $this->layers[$name][] = ['source' => $source, 'flag' => $flag, 'at' => $declaredAt];
    }

    /** @return list<array{source: FlagSource, flag: Flag, at: string}> in application order */
    public function layers(string $name): array
    {
        return $this->layers[$name] ?? [];
    }

    /** @return list<string> names that have at least one override */
    public function names(): array
    {
        return array_keys($this->layers);
    }

    public function declaredAt(string $name): ?string
    {
        $layers = $this->layers($name);
        return $layers === [] ? null : $layers[count($layers) - 1]['at'];
    }
}