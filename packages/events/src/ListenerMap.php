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
 *     App\Tasks\TaskCompleted::class => [App\Tasks\LogCompletion::class, App\Tasks\NotifyWatchers::class],
 * ];
 * ```
 *
 * A key is an event class or an interface; a value is a container id, or a
 * list of them. An event reaches the listeners of every key it is an instance
 * of, in the order the file lists them, and a listener named under two such
 * keys runs once. The file is the whole registry: there are no string hook
 * names and nothing registers a listener anywhere else, so what `lava events`
 * and the map show is everything that will run.
 */
final readonly class ListenerMap
{
    public const FILE = 'app/Listeners.php';

    /**
     * @param array<string, list<string>> $listeners event class => listener ids, in file order
     * @param string|null $file the file they were read from, null when the app has none
     */
    public function __construct(
        private array $listeners,
        public ?string $file = null,
    ) {
    }

    /**
     * The app's listeners, or none when it has no `app/Listeners.php`.
     *
     * @throws InvalidListenersFile when the file does not return a map of names to ids
     */
    public static function load(string $appDir): self
    {
        $file = $appDir . '/' . self::FILE;
        if (!is_file($file)) {
            return new self([]);
        }

        // Required in a closure with no scope, so the file sees nothing of this class.
        $returned = (static fn (string $path): mixed => require $path)($file);
        if (!is_array($returned)) {
            throw InvalidListenersFile::notAMap($file, get_debug_type($returned));
        }

        $listeners = [];
        foreach ($returned as $event => $ids) {
            if (!is_string($event) || $event === '') {
                throw InvalidListenersFile::notAnEvent($file, $event);
            }
            $list = is_string($ids) ? [$ids] : $ids;
            if (!is_array($list) || $list === [] || !array_is_list($list)) {
                throw InvalidListenersFile::notListeners($file, $event, get_debug_type($ids));
            }
            foreach ($list as $id) {
                if (!is_string($id) || $id === '') {
                    throw InvalidListenersFile::notListeners($file, $event, 'a list holding ' . get_debug_type($id));
                }
                $listeners[$event][] = $id;
            }
        }

        return new self($listeners, $file);
    }

    /** @return array<string, list<string>> event class => listener ids, in file order */
    public function all(): array
    {
        return $this->listeners;
    }

    /** @return list<string> the listener ids `$event` reaches, in the order they run, each once */
    public function for(object $event): array
    {
        $ids = [];
        foreach ($this->listeners as $class => $listeners) {
            if (!$event instanceof $class) {
                continue;
            }
            foreach ($listeners as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }
}
