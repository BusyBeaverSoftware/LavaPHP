<?php

declare(strict_types=1);

namespace Lava\Events\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * `app/Listeners.php` does not return a map from event classes to listener ids.
 *
 * The pack's one problem for a wrong shape, as lavaphp/db has one for a
 * migration file: the file is read at boot, so this is a boot problem naming
 * the file and the shape to write.
 */
final class InvalidListenersFile extends LavaProblem
{
    private const SHAPE = "return [App\\Tasks\\TaskCompleted::class => [App\\Tasks\\LogCompletion::class]];";

    public static function notAMap(string $file, string $returned): self
    {
        return new self(
            "The listeners file {$file} returned {$returned} instead of a map from event classes to listeners.",
            'End the file with: ' . self::SHAPE,
            ['file' => $file, 'returned' => $returned],
            SourceLocation::of($file, 1),
        );
    }

    public static function notAnEvent(string $file, int|string $key): self
    {
        return new self(
            "The listeners file {$file} has the key " . var_export($key, true) . ', which is not an event class.',
            'Key each entry by the event class it listens for: ' . self::SHAPE,
            ['file' => $file, 'key' => $key],
            SourceLocation::of($file, 1),
        );
    }

    public static function notListeners(string $file, string $event, string $got): self
    {
        return new self(
            "The listeners file {$file} gives {$event} {$got} instead of a listener id or a non-empty list of them.",
            "List the container ids of its listeners, in the order they should run: {$event}::class => [App\\Tasks\\LogCompletion::class].",
            ['file' => $file, 'event' => $event, 'got' => $got],
            SourceLocation::of($file, 1),
        );
    }

    public function code(): string
    {
        return 'invalid_listeners_file';
    }
}
