<?php

declare(strict_types=1);

namespace App\Wiring;

/**
 * Depends on the broken Greeter — the shape that used to put one diagnosis in
 * the boot report once per dependent, because a singleton whose factory throws
 * is never cached and is re-resolved by everything that needs it.
 */
final readonly class Welcome
{
    public function __construct(public Greeter $greeter)
    {
    }
}
