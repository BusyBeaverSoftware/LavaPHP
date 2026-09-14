<?php

declare(strict_types=1);

// Events and listeners for the pack's own tests, one file because each is a
// few lines and they are only ever read together. Required by the tests that
// use them; an app's own events and listeners autoload as ordinary classes.

namespace Lava\Events\Tests\Support;

use Psr\EventDispatcher\StoppableEventInterface;

interface Trackable
{
}

final readonly class Shipped implements Trackable
{
    public function __construct(public string $order)
    {
    }
}

final class StoppableShipped implements StoppableEventInterface
{
    public bool $stopped = false;

    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}

final class RecordsShipment
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(Shipped $event): void
    {
        $this->seen[] = $event->order;
    }
}

final class TakesTrackable
{
    public function __invoke(Trackable $event): void
    {
    }
}

final class TakesAnything
{
    public function __invoke(object $event, int $retries = 0): void
    {
    }
}

final class NotInvokable
{
}

final class TakesNothing
{
    public function __invoke(): void
    {
    }
}

final class TakesString
{
    public function __invoke(string $event): void
    {
    }
}

final class TakesStdClass
{
    public function __invoke(\stdClass $event): void
    {
    }
}

final class TakesUnion
{
    public function __invoke(Shipped|\stdClass $event): void
    {
    }
}

final class NeedsTwo
{
    public function __invoke(Shipped $event, int $count): void
    {
    }
}
