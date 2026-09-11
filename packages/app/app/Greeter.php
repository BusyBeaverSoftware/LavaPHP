<?php

declare(strict_types=1);

namespace App;

/**
 * A service, built by visible code. It takes a plain string rather than reading
 * config itself, so the wiring is in `app/Services.php` where an agent can see
 * it — that is the whole point of having no auto-wiring.
 */
final class Greeter
{
    public function __construct(private readonly string $greeting)
    {
    }

    public function greet(string $who): string
    {
        return "{$this->greeting}, {$who}!";
    }
}
