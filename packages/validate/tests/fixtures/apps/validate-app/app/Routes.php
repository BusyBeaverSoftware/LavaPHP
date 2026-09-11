<?php

declare(strict_types=1);

use Lava\Core\Routing\Router;

// Only the returned closure: boot re-executes this file on every boot, so
// classes live in autoloaded files and nothing is declared here.
return function (Router $r): void {
    $r->post('/users', 'users.store')->handler([\App\Http\RegisterController::class, 'store']);
    $r->post('/events', 'events.store')->handler([\App\Http\EventController::class, 'store']);

    // A route whose declaration is broken on purpose: its custom predicate
    // throws, which is an app fault rather than a validation failure. The
    // fixture exists so the 500-and-`invalid_rule` path is exercised over real
    // HTTP instead of only in a unit test.
    $r->post('/broken', 'broken.store')->handler([\App\Http\BrokenController::class, 'store']);
};
