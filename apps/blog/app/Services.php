<?php

declare(strict_types=1);

use App\Auth\Auth;
use App\Auth\PasswordHasher;
use App\Auth\SessionCookie;
use App\Auth\UserRepository;
use App\Blog\PostRepository;
use App\Http\ErrorPageMiddleware;
use App\Http\RequireLoginJsonMiddleware;
use App\Http\RequireLoginMiddleware;
use App\Http\SessionMiddleware;
use Lava\Core\Boot\AppContext;
use Lava\Core\Config\EnvVar;
use Lava\Core\Config\ProcessEnv;
use Lava\Core\Container\Container;
use Lava\Db\Connection;
use Lava\View\ViewRenderer;

/**
 * Every service this app has, built by visible code.
 *
 * There is no auto-wiring: a class is constructible here or it is not
 * constructible at all, and registering one id twice is fatal at boot. So this
 * file is the complete answer to "where does this come from?" — and it is also
 * the answer to "what can a handler ask for?", since a handler parameter can
 * only be the request, `RouteArgs`, or an id registered here.
 *
 * Registration order is core → packs → this file, so `Lava\Db\Connection` is
 * already bound when the repository factories below run. Everything here is a
 * singleton: none of these objects holds request state, which is precisely why
 * {@see Auth} takes the request as an argument instead of remembering a user.
 */
return function (Container $c, AppContext $ctx): void {
    // ── Data ────────────────────────────────────────────────────────────────
    $c->singleton(
        UserRepository::class,
        static fn (Container $c): UserRepository => new UserRepository($c->get(Connection::class)),
    );

    $c->singleton(
        PostRepository::class,
        static fn (Container $c): PostRepository => new PostRepository($c->get(Connection::class)),
    );

    // ── Authentication ──────────────────────────────────────────────────────
    $c->singleton(
        PasswordHasher::class,
        static fn (): PasswordHasher => new PasswordHasher(),
    );

    // Read at registration, once, so the session's behaviour cannot depend on
    // when it was first resolved. `ProcessEnv::real()` rather than `getenv()`:
    // it is the same reader boot and `lava env` use, so a value that `lava env`
    // reports is a value this factory actually saw.
    //
    // An empty secret is passed through rather than defaulted. `SessionCookie`'s
    // constructor rejects it, and that is deliberate: `EnvVar::required` below
    // fails `lava check --strict`, but a production boot does not consult
    // declarations, so the refusal also has to live where the value is used.
    $c->singleton(
        SessionCookie::class,
        static fn (): SessionCookie => new SessionCookie(
            ProcessEnv::real('SESSION_SECRET') ?? '',
            $ctx->config->string('app.session_cookie', 'lava_blog_session'),
            $ctx->config->int('app.session_ttl', 1209600),
        ),
    );

    $c->singleton(
        Auth::class,
        static fn (Container $c): Auth => new Auth($c->get(UserRepository::class)),
    );

    // ── Middleware ──────────────────────────────────────────────────────────
    // Listed in app/Middleware.php and on individual routes, but registered
    // HERE like any other service: the pipeline resolves each class-string from
    // the container at request time, so naming one that was never registered is
    // a `service_not_registered` boot problem rather than a 500 on the first
    // request that reaches it.
    $c->singleton(
        SessionMiddleware::class,
        static fn (Container $c): SessionMiddleware => new SessionMiddleware($c->get(SessionCookie::class)),
    );

    // Takes the view renderer because it renders a page, and `Auth` because the
    // layout it renders needs the signed-in user and the CSRF token — the same
    // two services every controller takes, which is the point: the error page is
    // an ordinary page of this app, not a special case with its own plumbing.
    $c->singleton(
        ErrorPageMiddleware::class,
        static fn (Container $c): ErrorPageMiddleware => new ErrorPageMiddleware(
            $c->get(ViewRenderer::class),
            $c->get(Auth::class),
        ),
    );

    $c->singleton(
        RequireLoginMiddleware::class,
        static fn (Container $c): RequireLoginMiddleware => new RequireLoginMiddleware($c->get(Auth::class)),
    );

    $c->singleton(
        RequireLoginJsonMiddleware::class,
        static fn (Container $c): RequireLoginJsonMiddleware => new RequireLoginJsonMiddleware($c->get(Auth::class)),
    );

    // ── Declared environment ────────────────────────────────────────────────
    // Declared, not read: nothing in this file consumes SESSION_SECRET, because
    // the SessionCookie factory above does. The declaration is what puts the name
    // in `lava env` with a purpose attached — and `secret: true` is what makes
    // `lava env` redact its value.
    $c->value(EnvVar::CONTAINER_ID, [
        EnvVar::required(
            'SESSION_SECRET',
            'Signs the session cookie. Generate with: php -r \'echo bin2hex(random_bytes(32)), PHP_EOL;\'',
            secret: true,
        ),
    ]);
};
