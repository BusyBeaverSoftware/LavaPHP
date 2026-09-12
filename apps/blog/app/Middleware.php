<?php

declare(strict_types=1);

/**
 * Global middleware — class-strings, outermost first. Each one wraps every
 * route, including the requests that end in a 404.
 *
 * `SessionMiddleware` is first because everything downstream depends on the
 * session existing: the CSRF token has to be available before any form is
 * rendered, including the login form for someone who has no session yet. Being
 * outermost also means its `Set-Cookie` lands on responses produced by the
 * per-route middleware — the redirect a signed-out visitor gets from
 * `RequireLoginMiddleware`, for instance.
 *
 * `ErrorPageMiddleware` is last, and both of the reasons are ordering.
 *
 * It must be *inside* `SessionMiddleware`, because a PSR-7 request is
 * immutable: the session is attached with `withAttribute()`, so only the frames
 * the session middleware passes the request *down* to can see it. An outermost
 * error page would call `Auth::csrf()` on a request with no session attribute
 * and throw `SessionMissing` while rendering the error page for a different
 * problem.
 *
 * It must be *after* nothing else, because it is only useful if it sits outside
 * every frame that can throw. `App::handle()` builds one list —
 * `[...globalMiddleware, ...route->middleware]` — so the last global entry is
 * still outside every per-route gate and outside the handler dispatch itself,
 * which is exactly the set of things whose problems need a page.
 *
 * The auth gates are NOT here. They are listed on the routes that need them
 * (`->middleware(RequireLoginMiddleware::class)` in app/Routes.php), because
 * "which requests need a user" is a property of a route, not of the app — and a
 * global gate would make `/` and `/posts/{id}` unreachable to the public.
 */
return [
    \App\Http\SessionMiddleware::class,
    \App\Http\ErrorPageMiddleware::class,
];
