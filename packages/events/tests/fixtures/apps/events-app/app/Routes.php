<?php

declare(strict_types=1);

use App\EventsFixture\CompletionController;
use Lava\Core\Routing\Router;

return function (Router $r): void {
    $r->post('/tasks/{id:int}/complete', 'tasks.complete')->handler([CompletionController::class, 'complete']);
};
