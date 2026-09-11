<?php

declare(strict_types=1);

namespace Lava\Core\Routing;

/**
 * A handler's boot-computed injection plan — everything dispatch needs at
 * runtime with zero reflection: how to call the handler and what to pass for
 * each parameter, in signature order.
 */
final readonly class HandlerPlan
{
    /**
     * @param string $kind 'method' | 'function'
     * @param string|null $class set for method handlers
     * @param string|null $file where the handler is written (for `lava routes`)
     * @param list<array{kind: string, type: string, name: string}> $injects
     *        kind: 'request' | 'args' | 'service'
     */
    public function __construct(
        public string $kind,
        public ?string $class,
        public string $function,
        public ?string $file,
        public ?int $line,
        public array $injects,
    ) {
    }

    /** Human/agent-readable handler identity, e.g. App\Http\UserController::show. */
    public function describe(): string
    {
        return $this->class !== null ? "{$this->class}::{$this->function}" : "'{$this->function}'";
    }

    /** @return array<string, mixed> the `lava routes --json` handler + injects record */
    public function json(): array
    {
        return [
            'handler' => [
                'kind' => $this->kind,
                'class' => $this->class,
                'function' => $this->function,
                'file' => $this->file,
                'line' => $this->line,
            ],
            'injects' => $this->injects,
        ];
    }
}