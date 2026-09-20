<?php

declare(strict_types=1);

namespace Lava\Events;

use Lava\Events\Problem\InvalidListenersFile;

/**
 * `app/Listeners.php`, read once at boot: which listeners each event reaches,
 * in the order they run.
 *
 * ```php
 * // app/Listeners.php
 * return [
 *     App\Tasks\TaskCompleted::class => [App\Tasks\LogCompletion::class, Phase::last(App\Tasks\AuditTrail::class)],
 * ];
 * ```
 *
 * A key is an event class or an interface; a value is a container id, a
 * {@see Phase} wrapping one, or a list of either. An event reaches the
 * listeners of every key it is an instance of, and a listener named under two
 * such keys runs once. The file is the whole registry: there are no string hook
 * names and nothing registers a listener anywhere else, so what `lava events`
 * and the map show is everything that will run.
 *
 * **The order, stated once.** Phase rank first — `first`, then `default`, then
 * `last` — and within a phase the order this file has always had: every
 * matching key in file order, each listener at its first occurrence. The sort
 * is stable, so listeners of one phase keep their file order, and a file that
 * names no phase is ordered exactly as before. A phase is the listener's own,
 * so an id carries it under every key that names it; two phases for one id is
 * refused at boot ({@see orderConflicts()}).
 */
final readonly class ListenerMap
{
    public const FILE = 'app/Listeners.php';

    /**
     * @param array<string, list<string>> $listeners event class => listener ids, in file order
     * @param string|null $file the file they were read from, null when the app has none
     * @param array<string, list<array{phase: string, event: string}>> $declarations listener id => every phase the file gave it, in file order
     */
    public function __construct(
        private array $listeners,
        public ?string $file = null,
        private array $declarations = [],
    ) {
    }

    /**
     * The app's listeners, or none when it has no `app/Listeners.php`.
     *
     * @throws InvalidListenersFile when the file does not parse, or does not return a map of names to ids
     */
    public static function load(string $appDir): self
    {
        $file = $appDir . '/' . self::FILE;
        if (!is_file($file)) {
            return new self([]);
        }

        // Required in a closure with no scope, so the file sees nothing of this
        // class. An `\Error` from it is the file's mistake: left to escape, it
        // would reach boot as the module failing to construct (R3-B8). A
        // misspelled phase — `Phase::frist()` — arrives here too, at its line.
        try {
            $returned = (static fn (string $path): mixed => require $path)($file);
        } catch (\Error $error) {
            throw InvalidListenersFile::unreadable($file, $error);
        }
        if (!is_array($returned)) {
            throw InvalidListenersFile::notAMap($file, get_debug_type($returned));
        }

        $listeners = [];
        $declarations = [];
        foreach ($returned as $event => $entries) {
            if (!is_string($event) || $event === '') {
                throw InvalidListenersFile::notAnEvent($file, $event);
            }
            $list = is_string($entries) || $entries instanceof Phase ? [$entries] : $entries;
            if (!is_array($list) || $list === [] || !array_is_list($list)) {
                throw InvalidListenersFile::notListeners($file, $event, get_debug_type($entries));
            }
            foreach ($list as $entry) {
                $id = $entry instanceof Phase ? $entry->id : $entry;
                if (!is_string($id) || $id === '') {
                    throw InvalidListenersFile::notListeners($file, $event, 'a list holding ' . get_debug_type($entry));
                }
                $listeners[$event][] = $id;
                $declarations[$id][] = ['phase' => $entry instanceof Phase ? $entry->phase : Phase::DEFAULT, 'event' => $event];
            }
        }

        return new self($listeners, $file, $declarations);
    }

    /** @return array<string, list<string>> event class => listener ids, in file order */
    public function all(): array
    {
        return $this->listeners;
    }

    /** The phase the file gave a listener; `default` for one it never wrapped. */
    public function phaseOf(string $id): string
    {
        return $this->declarations[$id][0]['phase'] ?? Phase::DEFAULT;
    }

    /**
     * Listeners the file gave more than one phase, which nothing can order.
     *
     * Computed here because this is where the file was read; raised as problems
     * by {@see EventsModule::register()}, with every other thing the file got
     * wrong, so one boot reports all of it.
     *
     * @return list<array{listener: string, byPhase: array<string, list<string>>}>
     */
    public function orderConflicts(): array
    {
        $conflicts = [];
        foreach ($this->declarations as $id => $given) {
            $byPhase = [];
            foreach ($given as $one) {
                if (!in_array($one['event'], $byPhase[$one['phase']] ?? [], true)) {
                    $byPhase[$one['phase']][] = $one['event'];
                }
            }
            if (count($byPhase) > 1) {
                $conflicts[] = ['listener' => (string) $id, 'byPhase' => $byPhase];
            }
        }

        return $conflicts;
    }

    /** @return list<string> the listener ids `$event` reaches, in the order they run, each once */
    public function for(object $event): array
    {
        return $this->forClass($event::class);
    }

    /**
     * The same, for an event named rather than held: what an event of this
     * class or interface would run, in order.
     *
     * `lava events` and the map read this, so what they print is the order
     * dispatch takes rather than the order the file happens to list.
     *
     * @return list<string>
     */
    public function forClass(string $class): array
    {
        $ids = [];
        foreach ($this->listeners as $key => $listeners) {
            if (!is_a($class, $key, true)) {
                continue;
            }
            foreach ($listeners as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        // Stable since PHP 8.0, which is what keeps file order inside a phase.
        usort($ids, fn (string $a, string $b): int => Phase::rank($this->phaseOf($a)) <=> Phase::rank($this->phaseOf($b)));

        return $ids;
    }

    /**
     * Every key with the listeners it runs, in order, each carrying its phase —
     * the one view `lava events` and the map's Events section both render.
     *
     * @return array<string, list<array{listener: string, phase: string}>>
     */
    public function ordered(): array
    {
        $ordered = [];
        foreach (array_keys($this->listeners) as $key) {
            $ordered[$key] = array_map(
                fn (string $id): array => ['listener' => $id, 'phase' => $this->phaseOf($id)],
                $this->forClass($key),
            );
        }

        return $ordered;
    }
}
