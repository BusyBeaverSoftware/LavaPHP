<?php

declare(strict_types=1);

namespace Lava\Core\Container;

/** The public introspection record for one registration — feeds `lava services` and `lava describe`. */
final readonly class ServiceRecord
{
    public function __construct(
        public string $id,
        public ServiceKind $kind,
        public string $file,
        public int $line,
        public ?string $class,
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'file' => $this->file,
            'line' => $this->line,
            'class' => $this->class,
        ];
    }
}