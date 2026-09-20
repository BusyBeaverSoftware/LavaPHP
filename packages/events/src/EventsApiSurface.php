<?php

declare(strict_types=1);

namespace Lava\Events;

use Lava\Core\Map\ApiSurface;

/** `lavaphp/events`'s public surface: the dispatcher an app takes, and the registry it reads. */
final class EventsApiSurface extends ApiSurface
{
    public function pack(): string
    {
        return 'events';
    }

    public function package(): string
    {
        return 'lavaphp/events';
    }

    public function feature(): string
    {
        return 'events';
    }

    public function namespacePrefix(): string
    {
        return 'Lava\\Events\\';
    }

    public function sourceRoot(): string
    {
        return __DIR__;
    }

    public function groups(): array
    {
        return [
            '(root)' => 'the dispatcher, the listener registry and the provider behind it',
        ];
    }

    public function exclusions(): array
    {
        return [
            'Problem/' => 'every problem is catalogued in docs/problem-codes.md, under its own drift guard',
            'Console/' => '`lava list` names every command with its flags and the schema its envelope claims',
        ];
    }

    public function examples(): array
    {
        return [
            \Lava\Events\EventDispatcher::class => <<<'PHP'
                use Lava\Events\EventDispatcher;

                final class Publisher
                {
                    public function __construct(private readonly EventDispatcher $events)
                    {
                    }

                    public function publish(int $id): void
                    {
                        // Synchronous, in file order, and the same event object comes
                        // back — so a listener that sets a value on it is the filter.
                        $this->events->dispatch(new App\Blog\PostPublished($id));
                    }
                }
                PHP,

            \Lava\Events\ListenerMap::class => <<<'PHP'
                // app/Listeners.php is the whole registry: nothing registers a
                // listener anywhere else, so this file is what runs.
                use Lava\Events\ListenerMap;

                $map = ListenerMap::load(dirname(__DIR__));
                $forThisEvent = $map->for(new App\Blog\PostPublished(7));
                $everything = $map->all();
                PHP,
        ];
    }
}
