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
};
