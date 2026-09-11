<?php

declare(strict_types=1);

use Lava\Core\Container\Container;

/**
 * Every service this app has, built by visible code.
 *
 * There is no auto-wiring: a class is constructible here or it is not
 * constructible at all, and registering one id twice is fatal at boot. So this
 * file is the complete answer to "where does this come from?", and
 * `lava services --json` is a rendering of it — with real dependencies, taken
 * from an actual resolution, not from reading the source.
 *
 * The file's closure is called as `(Container, AppContext)`. This app needs
 * only the container, so it declares only the container: an unused second
 * parameter would be noise. `AppContext` carries the app dir, the environment
 * name, the loaded config and the flags, and is how a factory that needs config
 * gets it — as a visible argument, rather than by smuggling config through the
 * container.
 *
 * Registration order is core → packs → this file, so `Lava\Db\Connection` is
 * already bound when the factory below runs. It is built lazily and connects on
 * first query, which is why booting the app — and `lava check` — never needs a
 * database to be reachable.
 *
 * The repository is a singleton because it holds no request state: only the
 * connection, which is itself one PDO handle for the whole process. If the
 * binding under `Connection::class` were ever replaced with something else, the
 * constructor's own type would reject it during boot's wiring sweep — not on
 * whichever request happened to arrive first.
 */
return function (Container $c): void {
    $c->singleton(
        \App\Tasks\TaskRepository::class,
        fn (Container $c): \App\Tasks\TaskRepository => new \App\Tasks\TaskRepository(
            $c->get(\Lava\Db\Connection::class),
        ),
    );

    // Middleware is a service like any other. Listing it in app/Middleware.php
    // is not enough: the router resolves each class-string from the container
    // at request time, so naming one that was never registered is a boot
    // problem (`service_not_registered`), not a 500 on the first request.
    $c->singleton(
        \App\Http\RequestIdMiddleware::class,
        static fn (): \App\Http\RequestIdMiddleware => new \App\Http\RequestIdMiddleware(),
    );
};
