<?php

declare(strict_types=1);

namespace App\Wiring;

/** Never constructs in the broken fixture — the wiring line is what the report names. */
final readonly class Greeter
{
    public function __construct(public readonly string $name)
    {
    }
}