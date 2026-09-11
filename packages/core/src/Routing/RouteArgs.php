<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/**
 * Typed accessors for the matched route's params. Values were already
 * validated against their param types by the compiled regex — the accessors
 * only convert (int) or pass through (str, uuid).
 */
final readonly class RouteArgs
{
    /**
     * @param array<string, string|int> $args
     */
    public function __construct(
        public string $routeName,
        private readonly array $args,
    ) {
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->args);
    }

    /** @return array<string, string|int> every param, in path order */
    public function all(): array
    {
        return $this->args;
    }

    public function int(string $name): int
    {
        $value = $this->raw($name);
        if (is_int($value)) {
            return $value;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }
        throw new \InvalidArgumentException("Route param '{$name}' is not an integer: '{$value}'.");
    }

    public function str(string $name): string
    {
        $value = $this->raw($name);
        if (is_int($value)) {
            return (string) $value;
        }
        return $value;
    }

    /** UUIDs are regex-validated at match time; this only returns the string. */
    public function uuid(string $name): string
    {
        return $this->str($name);
    }

    private function raw(string $name): string|int
    {
        if (!array_key_exists($name, $this->args)) {
            throw new \InvalidArgumentException(
                "Route '{$this->routeName}' has no param '{$name}'. Params: "
                . implode(', ', array_keys($this->args)) . '.',
            );
        }
        return $this->args[$name];
    }
}