<?php

declare(strict_types=1);

namespace Lava\Core\Clock;

use Psr\Clock\ClockInterface;

/**
 * The real time: what core registers as the app's `ClockInterface` unless the
 * app or a pack registered one first.
 *
 * A service that asks this for the time instead of calling `new
 * DateTimeImmutable()` can be tested at any moment it likes — a test boots the
 * app with `replace: [ClockInterface::class => new FrozenClock('2026-01-01 09:00')]`
 * — and a service that calls `time()` directly cannot be tested at any moment but
 * now. Session expiry, rate-limit windows and scheduled publishing are all that
 * second kind until they take a clock.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }
}
