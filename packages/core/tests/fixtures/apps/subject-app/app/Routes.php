<?php

declare(strict_types=1);

use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

// Function handlers are not autoloadable — load the file at the top.
require_once __DIR__ . '/Http/health.php';

return function (Router $r): void {
    $r->add('/health', 'health', Method::Get)->handler('App\Http\subject_health');

    // Both audience-gated routes share one handler: the response names the
    // matched route, so each test can tell which gate let it through.
    $r->get('/team/board', 'team.board')
        ->handler([\App\Http\BoardController::class, 'show'])
        ->when('team_preview');

    $r->get('/early', 'early.features')
        ->handler([\App\Http\BoardController::class, 'show'])
        ->when('slow_rollout');
};