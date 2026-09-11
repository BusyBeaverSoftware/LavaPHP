<?php

declare(strict_types=1);

use Lava\Core\Routing\Router;

return function (Router $r): void {
    // The app's own route registers FIRST; module routes land after it, so
    // the app wins any path overlap (see docs/conventions.md).
    $r->get('/', 'home')->handler([\App\Http\HomeController::class, 'index']);
};