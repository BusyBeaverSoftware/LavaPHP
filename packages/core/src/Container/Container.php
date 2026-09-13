<?php

declare(strict_types=1);

namespace Lava\Core\Container;

use Lava\Core\Problem\BadReplacement;
use Lava\Core\Problem\CircularService;
use Lava\Core\Problem\DuplicateService;
use Lava\Core\Problem\LavaProblem;
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
 * Three read-only introspection uses are allowed and documented: a factory
 * closure's file:line and its declared return type (for diagnostics and the
 * project map), and the caller's file:line for value/alias registrations.
 * Nothing is ever constructed by reflection.
 *
 * **Replacements are a test's, and only a test's.** A container built with
 * replacements answers `get()` for those ids with the given values instead of
 * running the registration. Nothing an app writes can add one: the map is fixed
 * when the kernel constructs the container, from the `replace:` a test passed to
 * {@see \Lava\Core\Testing\TestApp::boot()}, and app/Services.php receives a
 * container that already exists. Registration itself is unchanged — the id is
 * still registered exactly once, by the code that owns it — so a replacement for
 * an id nobody registers, or of the wrong type, is a boot problem
 * ({@see replacementProblems()}) rather than a fake that silently stands in for
 * nothing.
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

    /**
     * @param array<string, mixed> $replacements id => the value `get()` returns for it
     */
    public function __construct(private readonly array $replacements = [])
    {
    }

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
        // A replacement answers for the id a caller asked for, before aliases
        // are followed — replacing `LoggerInterface` must replace what a
        // service type-hinting `LoggerInterface` receives, whatever it aliases.
        if (array_key_exists($id, $this->replacements)) {
            $this->attribute($id);
            return $this->replacements[$id];
        }

        $id = $this->resolveAliases($id);
        if (array_key_exists($id, $this->replacements)) {
            $this->attribute($id);
            return $this->replacements[$id];
        }

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

    /**
     * The type a singleton or factory declares it builds — its closure's return
     * type, as written — or null for a value, an alias, or a closure that
     * declares none.
     *
     * {@see describe()} reports the class a resolution actually produced, which
     * is the right answer for `lava services` and the wrong one for a committed
     * document: a factory that returns `SmtpMailer` in prod and `NullMailer` in
     * dev resolves to a different class in each environment, and a map built from
     * that moved its fingerprint with `--env`. The declaration does not move.
     */
    public function declaredType(string $id): ?string
    {
        $registration = $this->registrations[$id]
            ?? throw ServiceNotRegistered::of($id, $this->referencedFrom());
        if ($registration->factory === null) {
            return null;
        }

        return (new \ReflectionFunction($registration->factory))->getReturnType()?->__toString();
    }

    /**
     * What is wrong with the replacements this container was built with: one
     * problem for each id nothing registered, and one for each value that is not
     * an instance of the class or interface its id names.
     *
     * Checked once every registration exists (ValidateWiring asks), because only
     * then is "nothing registers it" true rather than "not yet".
     *
     * @return list<LavaProblem>
     */
    public function replacementProblems(): array
    {
        $problems = [];
        foreach ($this->replacements as $id => $value) {
            if (!$this->has($id)) {
                $problems[] = BadReplacement::unregistered($id);
                continue;
            }
            if ((class_exists($id) || interface_exists($id)) && !$value instanceof $id) {
                $problems[] = BadReplacement::wrongType($id, get_debug_type($value));
            }
        }

        return $problems;
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
            // `alias()` takes a string, so this holds one. The guard is the
            // same kind as the factory check in get(): it makes the invariant
            // explicit where it is relied on, instead of a cast that would turn
            // a wrong registration into the id "Array" or "1" and send the
            // reader hunting for a service that was never registered.
            if (!is_string($registration->value)) {
                throw new \LogicException(
                    "Alias '{$id}' does not name a string id (it holds "
                    . get_debug_type($registration->value) . ').',
                );
            }
            $id = $registration->value;
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
