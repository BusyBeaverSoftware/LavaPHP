<?php

declare(strict_types=1);

namespace Lava\Core\Testing;

use Psr\Clock\ClockInterface;

/**
 * A clock that says what a test tells it to, and moves only when told.
 *
 * Handed to an app in place of core's `SystemClock`:
 *
 *     $clock = new FrozenClock('2026-09-13 09:00:00');
 *     $app = TestApp::boot($dir, replace: [ClockInterface::class => $clock]);
 *     // … publish a post scheduled for 09:30, assert it is hidden …
 *     $clock->advance('PT45M');
 *     // … assert it is visible
 *
 * The test keeps the object, so moving it moves the time every service already
 * holding the clock reads next.
 */
final class FrozenClock implements ClockInterface
{
    private \DateTimeImmutable $now;

    /** @param \DateTimeImmutable|string $now a moment, or anything `new DateTimeImmutable()` accepts */
    public function __construct(\DateTimeImmutable|string $now = 'now')
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    /** @param \DateTimeImmutable|string $now a moment, or anything `new DateTimeImmutable()` accepts */
    public function set(\DateTimeImmutable|string $now): void
    {
        $this->now = is_string($now) ? new \DateTimeImmutable($now) : $now;
    }

    /** @param \DateInterval|string $by an interval, or an ISO 8601 duration such as `PT15M` or `P1D` */
    public function advance(\DateInterval|string $by): void
    {
        $this->now = $this->now->add(is_string($by) ? new \DateInterval($by) : $by);
    }
}
