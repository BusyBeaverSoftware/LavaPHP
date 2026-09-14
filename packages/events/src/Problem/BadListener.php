<?php

declare(strict_types=1);

namespace Lava\Events\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * An entry of `app/Listeners.php` that could not run: an event class that does
 * not exist, or a listener that cannot take the event it is listed for.
 *
 * Checked at boot, while the provider is built. Found at dispatch instead, the
 * same mistake is a `TypeError` on whichever request first fires the event,
 * which for a "send the welcome email" listener is a sign-up nobody tests.
 */
final class BadListener extends LavaProblem
{
    public static function unknownEvent(string $event, string $file): self
    {
        return new self(
            "The listeners file lists '{$event}', and no class or interface has that name.",
            'Fix the spelling or the namespace, or autoload the class; an event is any class, and a key may also be an interface its events implement.',
            ['event' => $event, 'file' => $file],
            SourceLocation::of($file, 1),
        );
    }

    public static function notInvokable(string $id, string $event, string $class, string $file): self
    {
        return new self(
            "Listener '{$id}' for {$event} is a {$class}, which has no __invoke() method.",
            "Give {$class} a public function __invoke({$event} \$event): void, or list the service that has one.",
            ['listener' => $id, 'event' => $event, 'class' => $class],
            SourceLocation::of($file, 1),
        );
    }

    public static function cannotTake(string $id, string $event, string $reason, string $file): self
    {
        return new self(
            "Listener '{$id}' cannot take {$event}: {$reason}.",
            "Declare it as public function __invoke({$event} \$event): void — its one required parameter typed as the event, a parent of it, or an interface it implements.",
            ['listener' => $id, 'event' => $event, 'reason' => $reason],
            SourceLocation::of($file, 1),
        );
    }

    public function code(): string
    {
        return 'bad_listener';
    }
}
