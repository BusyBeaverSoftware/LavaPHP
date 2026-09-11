<?php

declare(strict_types=1);

namespace Lava\Core\Container;

/**
 * What actually happened when a service was resolved: which ids it resolved
 * (dependencies) and which ids resolved it (dependents). Captured from real
 * resolutions, not static analysis — `lava services` reports what the
 * container truly did.
 */
final class ResolutionTrace
{
    /** @var list<string> */
    private array $dependencies = [];

    /** @var list<string> */
    private array $dependents = [];

    private ?string $resolvedClass = null;

    public function __construct(public readonly string $id)
    {
    }

    public function dependsOn(string $id): void
    {
        if (!in_array($id, $this->dependencies, true)) {
            $this->dependencies[] = $id;
        }
    }

    public function usedBy(string $id): void
    {
        if (!in_array($id, $this->dependents, true)) {
            $this->dependents[] = $id;
        }
    }

    public function resolved(mixed $value): void
    {
        if (is_object($value)) {
            $this->resolvedClass = $value::class;
        }
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return $this->dependencies;
    }

    /** @return list<string> */
    public function dependents(): array
    {
        return $this->dependents;
    }

    public function resolvedClass(): ?string
    {
        return $this->resolvedClass;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return [
            'id' => $this->id,
            'dependencies' => $this->dependencies,
            'dependents' => $this->dependents,
        ];
    }
}