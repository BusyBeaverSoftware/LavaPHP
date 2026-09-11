<?php

declare(strict_types=1);

// Function handlers are not autoloadable — require their file here, where
// they are wired to routes. (Class handlers autoload normally.)
require_once __DIR__ . '/Http/health.php';

use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

return function (Router $r): void {
    // A custom param type, registered before use.
    $r->pattern('word', '[a-z]+');

    // Plain function handler, multiple methods stated explicitly.
    $r->add('/health', 'health', Method::Get, Method::Head)
        ->handler('App\Http\health');

    // Controller handler: request + typed args; route-level middleware.
    $r->get('/users/{id:int}', 'users.show')
        ->handler([\App\Http\UserController::class, 'show'])
        ->middleware(\App\Http\AuthMiddleware::class);

    // Service injection: the container id App\Greeter arrives as a parameter.
    $r->get('/greet/{name:word}', 'greet')
        ->handler([\App\Http\GreetController::class, 'greet']);

    // Gated route: per-request flag — off means a real 404.
    $r->get('/beta/dashboard', 'beta.dashboard')
        ->handler([\App\Http\DashboardController::class, 'index'])
        ->when('beta_greeting');
};