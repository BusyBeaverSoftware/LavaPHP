<?php

declare(strict_types=1);

namespace Lava\Events;

/**
 * Where a listener runs: `first`, `default` or `last`.
 *
 * ```php
 * // app/Listeners.php
 * return [
 *     App\Tasks\TaskCompleted::class => [
 *         Phase::first(App\Tasks\CheckQuota::class),
 *         App\Tasks\LogCompletion::class,
 *         Phase::last(App\Tasks\AuditTrail::class),
 *     ],
 * ];
 * ```
 *
 * A bare id is the default phase, so a file that names no phase runs exactly as
 * it always has, and an app upgrading changes nothing.
 *
 * Three named phases rather than numbers, because a number has no wrong value:
 * `10` before `-20` is never checkable, and this pack's character is that every
 * mistake in the registry is a boot problem with a fix. Two phases given to one
 * listener is {@see Problem\ListenerOrderConflict} at boot, and a misspelling
 * cannot survive either — `Phase::frist()` is an `\Error` inside the file, which
 * {@see ListenerMap::load()} reports as `invalid_listeners_file` at that line.
 *
 * The phase belongs to the listener, not to the entry: an id keeps its phase
 * under every key that names it, which is what lets a `last` listener under an
 * interface run after a default one under a class. Within a phase the order is
 * unchanged — every matching key in file order, each listener at its first
 * occurrence — so moving a line is still how order inside a phase is set.
 */
final readonly class Phase
{
    public const FIRST = 'first';
    public const DEFAULT = 'default';
    public const LAST = 'last';

    /** @var list<string> every phase, in the order they run */
    public const ALL = [self::FIRST, self::DEFAULT, self::LAST];

    private function __construct(
        public string $id,
        public string $phase,
    ) {
    }

    /**
     * Runs before every default-phase listener of the same event.
     *
     * The id is any container id, not only a class name: `app.record` is a
     * listener like any other.
     */
    public static function first(string $id): self
    {
        return new self($id, self::FIRST);
    }

    /** Runs where the file puts it — what a bare id already means. */
    public static function default(string $id): self
    {
        return new self($id, self::DEFAULT);
    }

    /** Runs after every default-phase listener of the same event. */
    public static function last(string $id): self
    {
        return new self($id, self::LAST);
    }

    /**
     * How one entry of {@see ListenerMap::ordered()} reads in a table: the id,
     * with its phase in brackets unless it is the default. `lava events` and
     * the map's Events section both render it through this, from that one
     * view, so the two cannot disagree.
     *
     * @param array{listener: string, phase: string} $listener
     */
    public static function label(array $listener): string
    {
        return $listener['phase'] === self::DEFAULT
            ? $listener['listener']
            : "{$listener['listener']} ({$listener['phase']})";
    }

    /** What a phase sorts by: `first` lowest, `last` highest. */
    public static function rank(string $phase): int
    {
        $rank = array_search($phase, self::ALL, true);

        return $rank === false ? 1 : $rank;
    }
}
