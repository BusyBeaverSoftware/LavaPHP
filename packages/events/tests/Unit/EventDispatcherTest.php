<?php

declare(strict_types=1);

namespace Lava\Events\Tests\Unit;

use Lava\Events\EventDispatcher;
use Lava\Events\ListenerMap;
use Lava\Events\ListenerProvider;
use Lava\Events\Tests\Support\Shipped;
use Lava\Events\Tests\Support\StoppableShipped;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/Support/Fixtures.php';

/** The dispatcher's PSR-14 obligations: order, stopping, and exceptions that leave. */
final class EventDispatcherTest extends TestCase
{
    public function testListenersRunInOrderAndTheSameEventComesBack(): void
    {
        $calls = new \ArrayObject();
        $provider = new ListenerProvider(new ListenerMap([Shipped::class => ['first', 'second']]), [
            'first' => static function (Shipped $event) use ($calls): void {
                $calls[] = "first {$event->order}";
            },
            'second' => static function (Shipped $event) use ($calls): void {
                $calls[] = "second {$event->order}";
            },
        ]);
        $event = new Shipped('A1');

        self::assertSame($event, (new EventDispatcher($provider))->dispatch($event));
        self::assertSame(['first A1', 'second A1'], $calls->getArrayCopy());
    }

    public function testAStoppedEventReachesNoFurtherListener(): void
    {
        $calls = new \ArrayObject();
        $provider = new ListenerProvider(new ListenerMap([StoppableShipped::class => ['stop', 'after']]), [
            'stop' => static function (StoppableShipped $event) use ($calls): void {
                $calls[] = 'stop';
                $event->stopped = true;
            },
            'after' => static function () use ($calls): void {
                $calls[] = 'after';
            },
        ]);
        $dispatcher = new EventDispatcher($provider);

        $dispatcher->dispatch(new StoppableShipped());
        self::assertSame(['stop'], $calls->getArrayCopy());

        $calls->exchangeArray([]);
        $stopped = new StoppableShipped();
        $stopped->stopped = true;
        $dispatcher->dispatch($stopped);
        self::assertSame([], $calls->getArrayCopy(), 'Asked before the first listener as well.');
    }

    public function testAListenersExceptionLeavesDispatch(): void
    {
        $provider = new ListenerProvider(new ListenerMap([Shipped::class => ['fails']]), [
            'fails' => static function (): void {
                throw new \RuntimeException('the listener failed');
            },
        ]);

        $this->expectExceptionMessage('the listener failed');
        (new EventDispatcher($provider))->dispatch(new Shipped('A1'));
    }
}
