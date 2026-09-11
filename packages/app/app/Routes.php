<?php

declare(strict_types=1);

// Function handlers are not autoloadable — require their file here, where they
// are wired to routes. (Class handlers autoload normally.)
require_once __DIR__ . '/Http/health.php';

use App\Http\HelloController;
use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

return function (Router $r): void {
    // A plain function handler.
    $r->add('/health', 'health', Method::Get, Method::Head)
        ->handler('App\Http\health');

    // A controller handler: built with `new` (no constructor arguments), then
    // called with each typed parameter injected — here the route's params and
    // the App\Greeter service. `lava routes --json` shows that plan.
    $r->get('/hello/{name:str}', 'hello')
        ->handler([HelloController::class, 'show']);

    // A gated route: while its flag is off this is a real 404, not a 500.
    // `config/features.php` defines the flag; the environment can override it.
    $r->get('/beta/hello', 'beta.hello')
        ->handler([HelloController::class, 'beta'])
        ->when('beta_greeting');
};
