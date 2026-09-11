<?php

declare(strict_types=1);

use Lava\Core\Routing\Router;

// Every way a routes file can be wrong, one per route, so the boot report
// collects all of them in one pass.
return function (Router $r): void {
    // 1. Registered fine, but no ->handler() was chained (caught at finalize).
    $r->get('/nope', 'no.handler');

    // 2. Untyped param {x} — explicit types are mandatory (caught at finalize).
    $r->get('/broken/{x}', 'broken')->handler([\App\Http\OkController::class, 'ok']);

    // 3. Gate names a flag that is defined nowhere (caught at BuildRouter).
    $r->get('/gated', 'gated')
        ->handler([\App\Http\OkController::class, 'ok'])
        ->when('nope_flag');

    // 4. Middleware class does not exist (caught at BuildRouter).
    $r->get('/mw', 'mw')
        ->handler([\App\Http\OkController::class, 'ok'])
        ->middleware(\App\Http\GhostMiddleware::class);

    // 5. Path without a leading slash — throws inside the routes file itself;
    //    everything registered above still compiles.
    $r->get('oops', 'no.slash')->handler([\App\Http\OkController::class, 'ok']);
};