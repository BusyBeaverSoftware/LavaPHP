<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/**
 * The fluent half of route registration. Returned by Router::get()/post()/add();
 * every method mutates in place and returns $this. The builder is finished
 * when Router::finalize() compiles it — routes are never half-registered.
 */
final class RouteBuilder
{
    /** @var array{0: string, 1: string}|string|null */
    private array|string|null $handler = null;

    /** @var list<string> */
    private array $middleware = [];

    private ?string $feature = null;

    /**
     * @param list<Method> $methods
     */
    public function __construct(
        private readonly string $name,
        private readonly string $path,
        private readonly array $methods,
    ) {
    }

    /**
     * @param array{0: string, 1: string}|string $handler [ClassName::class, 'method'] or 'function_name'
     */
    public function handler(array|string $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    /** PSR-15 middleware class-strings, outermost route layer first (all inside the global middleware). */
    public function middleware(string ...$classes): self
    {
        foreach ($classes as $class) {
            $this->middleware[] = $class;
        }
        return $this;
    }

    /** Per-request audience gate: the route 404s for subjects the flag is off for. */
    public function when(string $feature): self
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $feature) !== 1) {
            throw \Lava\Core\Problem\InvalidFeatureName::of($feature);
        }
        $this->feature = $feature;
        return $this;
    }

    /**
     * Everything finalize() needs. Internal to the Router pairing — the only
     * reader is Router::finalize().
     *
     * @return array{name: string, path: string, methods: list<Method>, handler: array{0: string, 1: string}|string|null, middleware: list<string>, feature: string|null}
     */
    public function parts(): array
    {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'methods' => $this->methods,
            'handler' => $this->handler,
            'middleware' => $this->middleware,
            'feature' => $this->feature,
        ];
    }
}