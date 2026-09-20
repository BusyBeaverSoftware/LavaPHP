<?php

declare(strict_types=1);

namespace Lava\Events;

use Lava\Core\Boot\App;
use Lava\Core\Boot\AppContext;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Container\Container;
use Lava\Core\Container\ServiceKind;
use Lava\Core\Map\MapSection;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesCommands;
use Lava\Core\Modules\ProvidesMapSection;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\ManyProblems;
use Lava\Core\Problem\ServiceNotRegistered;
use Lava\Events\Console\EventsCommand;
use Lava\Events\Problem\BadListener;
use Lava\Events\Problem\FactoryListener;
use Lava\Events\Problem\ListenerOrderConflict;

/**
 * lavaphp/events' entry point.
 *
 * Three ids. `ListenerMap` is `app/Listeners.php` as read at register time, so
 * a file of the wrong shape, naming an event class that does not exist, or
 * giving one listener two phases, fails the boot there. `ListenerProvider`
 * resolves every listener from the container and checks that it can take the
 * events it is listed for; its factory runs in ValidateWiring, after
 * app/Services.php has registered the listeners, so a listener nobody
 * registered is `service_not_registered` and one with the wrong signature is
 * `bad_listener`, both at boot. `EventDispatcher` dispatches through the
 * provider, fetched on first dispatch.
 *
 * Checking a listener's signature reads its `__invoke()` with reflection, at
 * boot and read-only: the third place in the framework that does so, beside a
 * handler's injection plan and a factory's declared type (conventions.md, "The
 * reflection boundary"). A listener is still constructed by its own factory;
 * reflection only reads what it takes.
 */
final class EventsModule implements Module, ProvidesCommands, ProvidesMapSection
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lavaphp/events', 'events');
    }

    public function register(Container $container, AppContext $ctx): void
    {
        $map = ListenerMap::load($ctx->appDir);
        $file = $map->file ?? $ctx->appDir . '/' . ListenerMap::FILE;
        $problems = [];
        foreach (array_keys($map->all()) as $event) {
            if (!class_exists($event) && !interface_exists($event)) {
                // Every key the file gets wrong, not the first: one boot should
                // name them all (Lava Notes, R3-B9).
                $problems[] = BadListener::unknownEvent($event, $file);
            }
        }
        foreach ($map->orderConflicts() as $conflict) {
            // A listener given two phases has no place in the order. Checked
            // here beside the keys: both are facts about the file alone, so
            // neither waits for the container.
            $problems[] = ListenerOrderConflict::of($conflict['listener'], $conflict['byPhase'], $file);
        }
        if ($problems !== []) {
            ManyProblems::raise($problems);
        }

        $container->singleton(ListenerMap::class, static fn (): ListenerMap => $map);

        $container->singleton(ListenerProvider::class, static function (Container $c) use ($map, $file): ListenerProvider {
            $listeners = [];
            $problems = [];
            foreach ($map->all() as $event => $ids) {
                foreach ($ids as $id) {
                    // Every listener is tried, and what each one got wrong is
                    // kept: the registry is one file a reader fixes in one pass,
                    // so one boot reports all of it (Lava Notes, R3-B9).
                    try {
                        if (!$c->has($id)) {
                            throw self::unregistered($id, $file);
                        }
                        self::checkShared($c, $id, $event, $file);
                        $listener = $c->get($id);
                        self::checkTakes($listener, $id, $event, $file);
                        if (is_callable($listener)) {
                            $listeners[$id] = $listener;
                        }
                    } catch (LavaProblem $problem) {
                        $problems[] = $problem;
                    }
                }
            }
            if ($problems !== []) {
                ManyProblems::raise($problems);
            }

            return new ListenerProvider($map, $listeners);
        });

        $container->singleton(EventDispatcher::class, static function (Container $c): EventDispatcher {
            // The provider is fetched on the first dispatch, not here: building
            // it builds every listener, and a listener may depend on a service
            // that takes this dispatcher (Lava Notes, R3-B2).
            return new EventDispatcher(new DeferredListenerProvider(static function () use ($c): ListenerProvider {
                $provider = $c->get(ListenerProvider::class);
                if (!$provider instanceof ListenerProvider) {
                    throw InvalidConfig::wrongService(ListenerProvider::class, ListenerProvider::class, $provider);
                }

                return $provider;
            }));
        });
    }

    public function commands(CommandRegistry $registry): void
    {
        $registry->add(new EventsCommand());
    }

    public function mapSection(App $app): ?MapSection
    {
        $map = $app->container->get(ListenerMap::class);
        if (!$map instanceof ListenerMap || $map->all() === []) {
            return null;
        }

        $rows = [];
        // The order each key's events RUN, the same view `lava events` prints.
        foreach ($map->ordered() as $event => $listeners) {
            $rows[] = [$event, implode(', ', array_map(Phase::label(...), $listeners))];
        }

        return new MapSection(
            'Events',
            'Each event class or interface in app/Listeners.php and the listeners it reaches, in the order they run. '
                . 'An event reaches the listeners of every entry it is an instance of; dispatch with Lava\Events\EventDispatcher. '
                . 'A listener marked (first) or (last) runs before or after the unmarked ones, whichever entry names it.',
            ['event', 'listeners'],
            $rows,
        );
    }

    /**
     * A listener id nothing registered, sourced at the listeners file.
     * `ServiceNotRegistered::of()` names the file only in its context, as a
     * relative path, so the problem is rebuilt with the absolute file an
     * editor can open (Lava Notes, R3-B9).
     */
    private static function unregistered(string $id, string $file): ServiceNotRegistered
    {
        $problem = ServiceNotRegistered::of($id, ListenerMap::FILE, 'a listener is resolved from the container');

        // Fully qualified: a `use` line above would move the registration lines committed maps record.
        return new ServiceNotRegistered($problem->getMessage(), $problem->fix, $problem->context, \Lava\Core\Problem\SourceLocation::of($file, 1));
    }

    /**
     * Refuses a listener registered with `factory()`.
     *
     * `describe()` follows an alias, so an alias to a factory is caught too; the
     * problem names the id the FILE lists, which is the line a reader edits.
     *
     * @throws FactoryListener when the registration is a factory
     */
    private static function checkShared(Container $c, string $id, string $event, string $file): void
    {
        $record = $c->describe($id);
        if ($record->kind === ServiceKind::Factory) {
            throw FactoryListener::of($id, $event, $record->file . ':' . $record->line, $file);
        }
    }

    /**
     * Whether a resolved listener can take the event it is listed for: an
     * `__invoke()` whose first parameter is typed as the event, a parent, an
     * interface it implements, or `object`, and whose other parameters are
     * optional.
     *
     * @throws BadListener when it cannot
     */
    private static function checkTakes(mixed $listener, string $id, string $event, string $file): void
    {
        if (!is_object($listener) || !method_exists($listener, '__invoke')) {
            throw BadListener::notInvokable($id, $event, get_debug_type($listener), $file);
        }

        $parameters = (new \ReflectionMethod($listener, '__invoke'))->getParameters();
        $first = $parameters[0] ?? null;
        if ($first === null) {
            throw BadListener::cannotTake($id, $event, '__invoke() takes no parameter', $file);
        }

        $type = $first->getType();
        if (!$type instanceof \ReflectionNamedType) {
            throw BadListener::cannotTake($id, $event, $type === null ? 'its parameter has no type' : "its parameter's type is {$type}, not one class", $file);
        }
        $name = $type->getName();
        if ($name !== 'object' && ($type->isBuiltin() || !is_a($event, $name, true))) {
            throw BadListener::cannotTake($id, $event, "its parameter is typed {$name}", $file);
        }

        foreach (array_slice($parameters, 1) as $parameter) {
            if (!$parameter->isOptional()) {
                throw BadListener::cannotTake($id, $event, "it also requires \${$parameter->getName()}, which dispatch never passes", $file);
            }
        }
    }
}
