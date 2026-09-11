<?php

declare(strict_types=1);

namespace App;

final class Greeter
{
    public function __construct(private readonly string $env)
    {
    }

    public function greet(string $who): string
    {
        return "[{$this->env}] Hello, {$who}!";
    }
}