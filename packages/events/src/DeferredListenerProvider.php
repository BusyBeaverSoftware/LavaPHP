<?php

declare(strict_types=1);

namespace Lava\Events;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * The provider `EventDispatcher` is built with: the pack's `ListenerProvider`,
 * fetched on the first dispatch rather than when the dispatcher is built.
 *
 * Building the provider builds every listener. A dispatcher that took the
 * provider at construction therefore added `EventDispatcher -> ListenerProvider
 * -> every listener` to the construction graph, and a listener whose own
 * dependencies reach the dispatcher (a service that dispatches the event, a
 * renderer whose Twig extension dispatches) closed a cycle the app never
 * wrote: boot failed with `circular_service` (Lava Notes, R3-B2).
 *
 * Only that edge is deferred. Boot still builds `ListenerProvider` in its
 * sweep, so every listener is resolved and checked before the app serves
 * anything, and the first dispatch picks up the instance boot built.
 *
 * @internal the pack's own wiring: take `EventDispatcher` to dispatch, or `ListenerProvider` for the provider
 */
final class DeferredListenerProvider implements ListenerProviderInterface
{
    private ?ListenerProvider $provider = null;

    /** @param \Closure(): ListenerProvider $fetch */
    public function __construct(private readonly \Closure $fetch)
    {
    }

    /** @return iterable<callable(object): mixed> */
    public function getListenersForEvent(object $event): iterable
    {
        $this->provider ??= ($this->fetch)();

        return $this->provider->getListenersForEvent($event);
    }
}
