<?php

declare(strict_types=1);

use App\Http\RedirectTargetController;
use Lava\Core\Routing\Router;

// Old addresses answering with a redirect to the routes that replaced them.
// `posts.short` is registered before its target on purpose: a target is
// checked once every route exists.
return function (Router $r): void {
    $r->redirect('/p/{slug:str}', 'posts.short', to: 'posts.show');
    $r->get('/posts/{slug:str}', 'posts.show')->handler([RedirectTargetController::class, 'show']);
    $r->redirect('/{year:int}/{slug:str}', 'posts.dated', to: 'posts.show', status: 308);
    $r->get('/', 'home')->handler([RedirectTargetController::class, 'home']);
    $r->redirect('/latest', 'latest', to: 'home', status: 302);

    // A redirect to a gated route is absent while the gate is off (R3-B5).
    $r->redirect('/b/{slug:str}', 'beta.old', to: 'beta.show');
    $r->get('/beta/{slug:str}', 'beta.show')->handler([RedirectTargetController::class, 'show'])->when('beta_posts');

    // Registered before a route whose URLs its own pattern also matches, so
    // asked for `/g/pages/…` it would lead to itself (R3-B4). Its params use a
    // custom type on purpose: boot refuses this shape when it can sample the
    // target's URLs, and a custom fragment is what leaves the request-time
    // guard as the only check.
    $r->pattern('segment', '[a-z]+');
    $r->redirect('/g/{section:segment}/{slug:segment}', 'guides.section', to: 'guides.show');
    $r->get('/g/pages/{slug:segment}', 'guides.show')->handler([RedirectTargetController::class, 'show']);

    // A target whose URL starts with its captured value: a value starting with
    // a slash must not make the Location another host (R3-B1).
    $r->pattern('rooted', '/.*');
    $r->redirect('/docs/{rest:rooted}', 'docs.old', to: 'docs.page');
    $r->get('/{rest:rooted}', 'docs.page')->handler([RedirectTargetController::class, 'home']);
};
