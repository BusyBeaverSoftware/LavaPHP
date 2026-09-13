<?php

declare(strict_types=1);

namespace App\ReplaceConsole;

/** A service a command reads, standing in for anything a test would rather fake: a clock, an HTTP client. */
class Greeter
{
    public function __construct(public readonly string $greeting)
    {
    }
}
