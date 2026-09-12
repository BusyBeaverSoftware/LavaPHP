<?php

declare(strict_types=1);

use App\Http\CookieController;
use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

// A site that sets, scopes and removes cookies, for TestClient's cookie jar.
// `show` answers from several paths because what a request carries depends on
// where it is going.
return function (Router $r): void {
    $r->add('/session/show', 'session.show', Method::Get, Method::Post)
        ->handler([CookieController::class, 'show']);
    $r->get('/session/start', 'session.start')->handler([CookieController::class, 'start']);
    $r->get('/session/end', 'session.end')->handler([CookieController::class, 'end']);
    $r->get('/session/expired', 'session.expired')->handler([CookieController::class, 'expired']);

    $r->get('/admin/start', 'admin.start')->handler([CookieController::class, 'admin']);
    $r->get('/admin/show', 'admin.show')->handler([CookieController::class, 'show']);
    $r->get('/administrator/show', 'administrator.show')->handler([CookieController::class, 'show']);

    $r->get('/account/settings/theme', 'account.theme')->handler([CookieController::class, 'theme']);
    $r->get('/account/settings/show', 'account.settings')->handler([CookieController::class, 'show']);
    $r->get('/account/show', 'account.show')->handler([CookieController::class, 'show']);
};
