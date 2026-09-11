<?php

declare(strict_types=1);

namespace Lava\Core\Container;

use Lava\Core\Problem\CircularService;
use Lava\Core\Problem\DuplicateService;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Core\Problem\SourceLocation;
use Psr\Container\ContainerInterface;

/**
 * The explicit-wiring container. There is NO auto-wiring: every id is
 * registered by visible code (app/Services.php or a module's register()),
 * every factory closure says exactly what it constructs. Re-registering an
 * id is fatal — "override" semantics is the silent-magic class this
 * framework bans.
 *
 * Two read-only introspection uses are allowed and documented: a factory
 * closure's file:line (for diagnostics) and the caller's file:line for
 * value/alias registrations. Nothing is ever constructed by reflection.
 */
final class Container implements ContainerInterface
{
    /** @var array<string, Registration> insertion-ordered */
    private array $registrations = [];

    /** @var array<string, mixed> singleton instances by id */
    private array $instances = [];

    /** @var list<string> ids currently being resolved (cycle detection) */
    private array $chain = [];

    /** @var list<string> ids whose factories are currently running (dependency attribution) */
    private array $owners = [];

    /** @var array<string, ResolutionTrace> */
    private array $traces = [];

    public function singleton(string $id, \Closure $factory): void
    {
        $this->register($id, ServiceKind::Singleton, $factory, null, self::closureLocation($factory));
    }

    public function factory(string $id, \Closure $factory): void
    {
        $this->register($id, ServiceKind::Factory, $factory, null, self::closureLocation($factory));
    }

    public function value(string $id, mixed $value): void
    {
        $this->register($id, ServiceKind::Value, null, $value, self::callerLocation());
    }

    /** The abstract id resolves to whatever the concrete id resolves to. */
    public function alias(string $abstract, string $concrete): void
    {
        $this->register($abstract, ServiceKind::Alias, null, $concrete, self::callerLocation());
    }

    public function get(string $id): mixed
    {
        $id = $this->resolveAliases($id);
        $registration = $this->registrations[$id]
            ?? throw ServiceNotRegistered::of($id, $this->referencedFrom());

        if ($registration->kind === ServiceKind::Value) {
            $this->attribute($id);
            return $registration->value;
        }

        if ($registration->kind === ServiceKind::Singleton && array_key_exists($id, $this->instances)) {
            $this->attribute($id);
            return $this->instances[$id];
        }

        if (in_array($id, $this->chain, true)) {
            throw CircularService::of([...$this->chain, $id]);
        }

        $this->chain[] = $id;
        $this->owners[] = $id;
        $this->traces[$id] ??= new ResolutionTrace($id);
        // Value registrations returned above; every remaining kind carries a
        // factory. The guard makes that invariant explicit rather than assumed.
        if ($registration->factory === null) {
            throw new \LogicException("Registration '{$id}' has no factory.");
        }
        try {
            $value = ($registration->factory)($this);
        } finally {
            array_pop($this->owners);
            array_pop($this->chain);
            $this->attribute($id);
        }
        $this->traces[$id]->resolved($value);
        if ($registration->kind === ServiceKind::Singleton) {
            $this->instances[$id] = $value;
        }
        return $value;
    }

    public function has(string $id): bool
    {
        $id = $this->resolveAliases($id);
        return isset($this->registrations[$id]);
    }

    /** @return list<string> every id (registrations and aliases), in registration order */
    public function ids(): array
    {
        return array_keys($this->registrations);
    }

    public function describe(string $id): ServiceRecord
    {
        $id = $this->resolveAliases($id);
        $registration = $this->registrations[$id]
            ?? throw ServiceNotRegistered::of($id, $this->referencedFrom());
        // No trace yet when describe() runs before the first resolution — a
        // legal path (lava services inspects registrations eagerly), and the
        // ?? keeps the missing key itself from warning.
        return new ServiceRecord(
            $id,
            $registration->kind,
            $registration->declaredAt->file,
            $registration->declaredAt->line,
            ($this->traces[$id] ?? null)?->resolvedClass(),
        );
    }

    /** @return array<string, ResolutionTrace> by id — real resolution history */
    public function traces(): array
    {
        return $this->traces;
    }

    private function register(string $id, ServiceKind $kind, ?\Closure $factory, mixed $value, SourceLocation $at): void
    {
        if (isset($this->registrations[$id])) {
            throw DuplicateService::of($id, $this->registrations[$id]->declaredAt, $at);
        }
        $this->registrations[$id] = new Registration($id, $kind, $factory, $value, $at);
    }

    private function resolveAliases(string $id): string
    {
        $seen = [];
        while (true) {
            $registration = $this->registrations[$id] ?? null;
            if ($registration === null || $registration->kind !== ServiceKind::Alias) {
                return $id;
            }
            if (isset($seen[$id])) {
                throw CircularService::of([...array_keys($seen), $id]);
            }
            $seen[$id] = true;
            $id = (string) $registration->value;
        }
    }

    /** Records the dependency edge between the running factory (if any) and the id it just resolved. */
    private function attribute(string $id): void
    {
        $owner = $this->owners[count($this->owners) - 1] ?? null;
        if ($owner === null || $owner === $id) {
            return;
        }
        $this->traces[$owner] ??= new ResolutionTrace($owner);
        $this->traces[$owner]->dependsOn($id);
        $this->traces[$id] ??= new ResolutionTrace($id);
        $this->traces[$id]->usedBy($owner);
    }

    private function referencedFrom(): ?string
    {
        $owner = $this->owners[count($this->owners) - 1] ?? null;
        if ($owner === null) {
            return null;
        }
        $registration = $this->registrations[$owner] ?? null;
        return $registration !== null ? (string) $registration->declaredAt : $owner;
    }

    private static function closureLocation(\Closure $factory): SourceLocation
    {
        $reflection = new \ReflectionFunction($factory);
        return SourceLocation::of(
            $reflection->getFileName() ?: 'unknown',
            $reflection->getStartLine() ?: 0,
        );
    }

    private static function callerLocation(): SourceLocation
    {
        // Walk past this class's own frames (callerLocation, value, alias):
        // the first frame whose file is not Container.php is the user's
        // registration call site. A wiring closure's own frame — which
        // reports where WireAppServices INVOKED it, not a user line — is
        // always deeper than that, never first.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5) as $frame) {
            $file = $frame['file'] ?? null;
            if ($file === null || $file === __FILE__) {
                continue;
            }
            return SourceLocation::of($file, $frame['line'] ?? 0);
        }
        return SourceLocation::of('unknown', 0);
    }
}