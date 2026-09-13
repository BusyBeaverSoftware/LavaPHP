<?php

declare(strict_types=1);

use App\ReplaceConsole\Greeter;
use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

return function (Container $c, AppContext $ctx): void {
    $c->singleton(Greeter::class, static fn (): Greeter => new Greeter('hello from the real service'));
};
