<?php

declare(strict_types=1);

use Lava\Core\Routing\Router;

// Only the returned closure: boot re-executes this file on every boot, so
// classes live in autoloaded files and nothing is declared here.
return function (Router $r): void {
    // The route url() reverses, with a typed param so a value the route would
    // never match is refused by the generator rather than silently rendered.
    // It renders the same template as /page — the interesting part is that the
    // NAME exists, not what the path serves.
    $r->get('/users/{id:int}', 'users.show')
        ->handler([\App\Http\TemplateController::class, 'page']);

    $r->get('/page', 'page')->handler([\App\Http\TemplateController::class, 'page']);
    $r->get('/gated', 'gated')->handler([\App\Http\TemplateController::class, 'gated']);
    $r->get('/bad-url', 'bad.url')->handler([\App\Http\TemplateController::class, 'badUrl']);
    $r->get('/bad-param', 'bad.param')->handler([\App\Http\TemplateController::class, 'badParam']);

    // One route per way a template can be wrong. Separate routes rather than
    // one route with a query parameter, so each failure is reachable over real
    // HTTP with a plain GET: the fixture is the executable spec of the three
    // problems, and a spec you have to build a request body for is a spec
    // nobody reads.
    $r->get('/missing', 'missing')->handler([\App\Http\TemplateController::class, 'missing']);
    $r->get('/broken', 'broken')->handler([\App\Http\TemplateController::class, 'broken']);
    $r->get('/untyped', 'untyped')->handler([\App\Http\TemplateController::class, 'untyped']);
    $r->get('/status', 'status')->handler([\App\Http\TemplateController::class, 'status']);
};
