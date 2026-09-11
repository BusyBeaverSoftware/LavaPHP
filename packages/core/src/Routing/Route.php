<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/**
 * One compiled route: the immutable record the router matches against.
 * The injection plan is NOT stored here — the router keeps plans by route
 * name (attached once at boot by HandlerInvoker) so the record stays a pure
 * pattern-matching fact.
 */
final readonly class Route
{
    /**
     * @param list<Method> $methods
     * @param array|string $handler [ClassName::class, 'method'] or 'function_name'
     * @param list<string> $middleware PSR-15 middleware class-strings, innermost first
     * @param string|null $feature per-request gate (->when()), null when ungated
     * @param string $regex compiled pattern, without delimiters
     * @param array<string, string> $params param name => type name
     */
    public function __construct(
        public string $name,
        public string $path,
        public array $methods,
        public array|string $handler,
        public array $middleware,
        public ?string $feature,
        public string $regex,
        public array $params,
    ) {
    }

    public function allows(string $method): bool
    {
        $enum = Method::tryFrom($method);
        return $enum !== null && in_array($enum, $this->methods, true);
    }

    /** @return list<string> uppercase method names */
    public function methodNames(): array
    {
        return array_map(static fn (Method $m): string => $m->value, $this->methods);
    }

    /** @return array<string, mixed> the `lava routes` record (state is added by the CLI) */
    public function json(): array
    {
        return [
            'name' => $this->name,
            'methods' => $this->methodNames(),
            'path' => $this->path,
            'handler' => $this->handler,
            'middleware' => $this->middleware,
            'feature' => $this->feature,
        ];
    }
}