<?php

declare(strict_types=1);

namespace Lava\Events\Problem;

use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * A listener registered with `$c->factory()`.
 *
 * It would run — which is why this is not a {@see BadListener} — but not as its
 * registration says. Boot builds every listener once and the provider holds the
 * instances ({@see \Lava\Events\ListenerProvider}), so a `factory()` listener is
 * one shared object, the opposite of what the word promises, and the app that
 * chose `factory()` to keep per-dispatch state on the listener gets shared state
 * and never an error (Lava Notes, R3-B10).
 *
 * Refused rather than documented, because the surprise is silent: nothing about
 * a wrong choice here fails, it just quietly holds state between dispatches.
 */
final class FactoryListener extends LavaProblem
{
    /**
     * @param string $id the id as `app/Listeners.php` writes it
     * @param string $registeredAt where the registration's closure begins
     */
    public static function of(string $id, string $event, string $registeredAt, string $file): self
    {
        return new self(
            "Listener '{$id}' for {$event} is registered with factory(), and a listener is built once at boot: every dispatch reaches that one instance.",
            "Register it with singleton() in {$registeredAt} — a listener is shared whatever its registration kind — and keep per-dispatch state on the event instead of on the listener.",
            ['listener' => $id, 'event' => $event, 'kind' => 'factory', 'registered_at' => $registeredAt],
            SourceLocation::of($file, 1),
        );
    }

    public function code(): string
    {
        return 'factory_listener';
    }
}
