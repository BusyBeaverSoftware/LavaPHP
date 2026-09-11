<?php

declare(strict_types=1);

namespace Lava\Core\Features;

/** The result of resolving one flag — with the complete layer-by-layer trace. */
final readonly class Resolution
{
    /**
     * @param list<array{layer: string, setting: string}> $trace every layer consulted, in order
     */
    public function __construct(
        public string $name,
        public bool $enabled,
        public FlagSource $source,
        public string $setting,
        public array $trace,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'name' => $this->name,
            'enabled' => $this->enabled,
            'source' => $this->source->value,
            'setting' => $this->setting,
            'trace' => $this->trace,
        ];
    }
}