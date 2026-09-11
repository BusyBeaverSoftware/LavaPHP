<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Container\Container;

/**
 * Every service this app has, built by visible code. There is no auto-wiring,
 * and registering the same id twice is fatal at boot — so this file is the
 * complete answer to "where does this come from?".
 *
 * `AppContext` carries what the factories need (the app dir, the environment
 * name, the loaded config, the flags) as explicit arguments rather than
 * smuggling them through the container.
 */
return function (Container $c, AppContext $ctx): void {
    $c->singleton(
        \App\Greeter::class,
        fn (Container $c): \App\Greeter => new \App\Greeter($ctx->config->string('app.greeting', 'Hello')),
    );
};
