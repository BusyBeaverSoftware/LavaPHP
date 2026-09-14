<?php

declare(strict_types=1);

use Lava\Core\Boot\AppContext;
use Lava\Core\Config\EnvVar;
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
 * The file's closure is called as `(Container, AppContext)`. This app takes
 * both: `AppContext` carries the app dir, the environment name, the loaded
 * config and the flags, and it is how a factory that needs config gets it — as
 * a visible argument, rather than by smuggling config through the container.
 * The upstream URL below is the one value this app reads that way, and it is
 * read HERE, once, at registration, so a service's behaviour cannot depend on
 * when it was first resolved.
 *
 * Registration order is core → packs → this file, so `Lava\Db\Connection` and
 * `Lava\HttpClient\HttpClient` are both already bound when the factories below
 * run. The connection is built lazily and connects on first query, which is why
 * booting the app — and `lava check` — never needs a database to be reachable.
 *
 * The two app services are singletons because they hold no request state: only
 * a connection and a client, each of which is one object for the whole process.
 * If a binding a factory depends on were ever replaced with something else, the
 * constructor's own type would reject it during boot's wiring sweep — not on
 * whichever request happened to arrive first.
 */
return function (Container $c, AppContext $ctx): void {
    $c->singleton(
        \App\Tasks\TaskRepository::class,
        fn (Container $c): \App\Tasks\TaskRepository => new \App\Tasks\TaskRepository(
            $c->get(\Lava\Db\Connection::class),
        ),
    );

    // An app service composing a pack service — the same shape as the
    // repository above, and the reason a handler can take `Upstream` as a typed
    // parameter. A handler parameter is injected by TYPE (the request,
    // `RouteArgs`, or a registered id), so a bare string cannot be handed to
    // one: the URL travels as a constructor argument instead, and this is the
    // single place it is read.
    $c->singleton(
        \App\Upstream\Upstream::class,
        fn (Container $c): \App\Upstream\Upstream => new \App\Upstream\Upstream(
            $c->get(\Lava\HttpClient\HttpClient::class),
            $ctx->config->string('app.upstream', 'http://127.0.0.1:8080'),
        ),
    );

    // A listener is a service like any other too: app/Listeners.php names it
    // for an event, and this is where it gets what it needs.
    $c->singleton(
        \App\Tasks\LogCompletion::class,
        static fn (Container $c): \App\Tasks\LogCompletion => new \App\Tasks\LogCompletion(
            $c->get(\Psr\Log\LoggerInterface::class),
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

    // Declared, not read: nothing in this file consumes `UPSTREAM_URL`, because
    // `config/app.php` does. The declaration is what puts the name in `lava env`
    // with a purpose attached, and what lets `lava check` tell you it is unset —
    // an environment variable that only exists inside a `getenv()` call is
    // invisible to every diagnostic the framework has.
    $c->value(EnvVar::CONTAINER_ID, [
        EnvVar::optional(
            'UPSTREAM_URL',
            'What GET /upstream/health fetches. Defaults to this app\'s own /health.',
        ),
    ]);
};
