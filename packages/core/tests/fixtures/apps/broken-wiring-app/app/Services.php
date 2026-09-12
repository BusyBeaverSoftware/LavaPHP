<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

return function (Container $c, AppContext $ctx): void {
    // The factory resolves an id nothing ever registered — the boot sweep must
    // catch it here, not on request one, and name this exact wiring line.
    $c->singleton(\App\Wiring\Greeter::class, fn (Container $c) => new \App\Wiring\Greeter(
        $c->get('never.registered'),
    ));

    // A constructor that throws a plain exception: still a structured
    // unexpected_failure in the report, never a white screen.
    $c->singleton(\App\Wiring\Boom::class, fn (Container $c) => new \App\Wiring\Boom());

    // Resolves the broken Greeter a second time. The boot report must still
    // name Greeter's missing id ONCE: the diagnosis and its fix are the same
    // however many services reach it.
    $c->singleton(\App\Wiring\Welcome::class, fn (Container $c) => new \App\Wiring\Welcome(
        $c->get(\App\Wiring\Greeter::class),
    ));

};