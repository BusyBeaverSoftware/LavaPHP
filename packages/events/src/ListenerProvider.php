<?php

declare(strict_types=1);

namespace Lava\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * The PSR-14 listener provider over `app/Listeners.php`.
 *
 * It holds the listeners themselves, resolved and checked when boot built it
 * ({@see EventsModule}), not the container: which object answers a listener id
 * is decided once, at boot, where a mistake is a problem with a fix rather
 * than an error on the request that first dispatches the event.
 */
final readonly class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @param array<string, callable(object): mixed> $listeners listener id => the listener
     */
    public function __construct(
        private ListenerMap $map,
        private array $listeners,
    ) {
    }

    /** @return iterable<callable(object): mixed> */
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->map->for($event) as $id) {
            yield $this->listeners[$id] ?? throw new \LogicException(
                "Listener '{$id}' is in " . ListenerMap::FILE . ' but was not resolved at boot.',
            );
        }
    }

    public function map(): ListenerMap
    {
        return $this->map;
    }
}
