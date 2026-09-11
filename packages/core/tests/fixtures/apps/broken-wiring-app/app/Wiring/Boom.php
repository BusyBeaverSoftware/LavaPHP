<?php

declare(strict_types=1);

namespace App\Wiring;

/** Stands in for every real-world "constructor hits an error" failure. */
final class Boom
{
    public function __construct()
    {
        throw new \LogicException('the boom service is not constructible yet');
    }
}