<?php

declare(strict_types=1);

namespace Lava\Events;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * The pack's PSR-14 dispatcher: each listener the provider gives, in order,
 * synchronously, and the same event object back.
 *
 * As PSR-14 requires, a stoppable event is asked before every listener whether
 * propagation has stopped, and a listener's exception is not caught: it leaves
 * `dispatch()` exactly as it would leave a direct call, so a handler's global
 * middleware and error page see it.
 *
 * Registered under this class, not under `EventDispatcherInterface`: the PSR id
 * is left for an app that wants a dispatcher of its own, as lavaphp/http-client
 * leaves `ClientInterface`.
 */
final readonly class EventDispatcher implements EventDispatcherInterface
{
    public function __construct(private ListenerProviderInterface $listeners)
    {
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners->getListenersForEvent($event) as $listener) {
            if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                break;
            }
            $listener($event);
        }

        return $event;
    }
}
