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
};