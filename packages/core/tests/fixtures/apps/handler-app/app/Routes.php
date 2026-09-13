<?php

declare(strict_types=1);

use App\Http\ClockController;
use App\Http\FailingController;
use App\Http\LinkController;
use Lava\Core\Routing\Router;

// Handlers that take what core registers at boot — the URL generator and the
// clock — and one that throws what a real handler throws when its database is
// down: not a problem, just an exception.
return function (Router $r): void {
    $r->get('/links/{id:int}', 'links.show')->handler([LinkController::class, 'show']);
    $r->get('/boom', 'boom')->handler([FailingController::class, 'boom']);
    $r->get('/now', 'now')->handler([ClockController::class, 'now']);
};
