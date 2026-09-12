<?php

declare(strict_types=1);

// Function handlers are not autoloadable — require their file here, where they
// are wired to routes. (Class handlers autoload normally.)
require_once __DIR__ . '/Http/health.php';

use App\Http\Api\PostApiController;
use App\Http\AuthController;
use App\Http\AuthPageController;
use App\Http\BlogController;
use App\Http\HomeController;
use App\Http\PostPageController;
use App\Http\RequireLoginJsonMiddleware;
use App\Http\RequireLoginMiddleware;
use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

/**
 * Every route, registered explicitly. There is no convention to discover: if a
 * path answers, it is on this list.
 *
 * Two things about the shape of the list are load-bearing.
 *
 * **Every path parameter carries an explicit type — `{id:int}`, never `{id}`.**
 * That is a boot-time requirement, not a style: `lava check` rejects a bare
 * `{id}` with `bad_route_pattern` before anything is served. It also settles a
 * hazard that would otherwise need route ordering to be right — `{id:int}`
 * compiles to `\d+`, so `/posts/{id:int}` cannot swallow `/posts/new`.
 *
 * **The write routes are separate from the read routes, not methods on one
 * path.** `/posts` is `GET`-less on purpose: the list lives at `/`, and a route
 * that answered both HTML and JSON is the content negotiation this framework
 * does not do. `/api/posts` is the JSON twin, with its own gate and its own
 * failure shape.
 *
 * `->when('signups_open')` on both registration routes is a per-request gate,
 * not a boot-time one: with the flag off they are real 404s, and the layout
 * asks the same resolver before drawing the link — so the page cannot advertise
 * a route the router would refuse.
 */
return function (Router $r): void {
    $r->add('/health', 'health', Method::Get, Method::Head)
        ->handler('App\Http\health');

    // ── The blog ────────────────────────────────────────────────────────────
    $r->get('/', 'home')
        ->handler([HomeController::class, 'index']);

    // The literal path goes first — see the note above.
    $r->get('/posts/new', 'posts.new')
        ->handler([PostPageController::class, 'blank'])
        ->middleware(RequireLoginMiddleware::class);

    // No ordering hazard with `/posts/new` above: `{id:int}` compiles to `\d+`.
    $r->get('/posts/{id:int}', 'posts.show')
        ->handler([PostPageController::class, 'show']);

    $r->get('/posts/{id:int}/edit', 'posts.edit')
        ->handler([PostPageController::class, 'edit'])
        ->middleware(RequireLoginMiddleware::class);

    $r->post('/posts', 'posts.store')
        ->handler([BlogController::class, 'store'])
        ->middleware(RequireLoginMiddleware::class);

    $r->post('/posts/{id:int}', 'posts.update')
        ->handler([BlogController::class, 'update'])
        ->middleware(RequireLoginMiddleware::class);

    $r->post('/posts/{id:int}/delete', 'posts.destroy')
        ->handler([BlogController::class, 'destroy'])
        ->middleware(RequireLoginMiddleware::class);

    // ── Accounts ────────────────────────────────────────────────────────────
    $r->get('/login', 'login')
        ->handler([AuthPageController::class, 'login']);

    $r->post('/login', 'login.submit')
        ->handler([AuthController::class, 'login']);

    $r->get('/register', 'register')
        ->handler([AuthPageController::class, 'register'])
        ->when('signups_open');

    $r->post('/register', 'register.submit')
        ->handler([AuthController::class, 'register'])
        ->when('signups_open');

    // POST, because signing out is a state change. A GET logout can be fired by
    // any `<img src>` on any page, and it is CSRF-checked for the same reason.
    $r->post('/logout', 'logout')
        ->handler([AuthController::class, 'logout']);

    // ── The same posts, as JSON ─────────────────────────────────────────────
    $r->get('/api/posts', 'api.posts.index')
        ->handler([PostApiController::class, 'index']);

    $r->post('/api/posts', 'api.posts.store')
        ->handler([PostApiController::class, 'store'])
        ->middleware(RequireLoginJsonMiddleware::class);
};
