<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

return function (Container $c, AppContext $ctx): void {
    $c->singleton(\App\Greeter::class, fn (Container $c): \App\Greeter => new \App\Greeter($ctx->env));
    $c->value('greeting.name', 'Lava');

    // Middleware is resolved from the container at request time — register it
    // like any other service (validated at boot by BuildRouter).
    $c->singleton(\App\Http\TimingMiddleware::class, fn (Container $c): \App\Http\TimingMiddleware => new \App\Http\TimingMiddleware());
    $c->singleton(\App\Http\AuthMiddleware::class, fn (Container $c): \App\Http\AuthMiddleware => new \App\Http\AuthMiddleware());
};