<?php

declare(strict_types=1);

// A function handler is not autoloadable, so its file is required here — at the
// one place that wires it to a route. Class handlers autoload normally and need
// no require; the `require_once` is the entire cost of using a plain function.
require_once __DIR__ . '/Http/health.php';

use App\Http\TasksController;
use Lava\Core\Routing\Method;
use Lava\Core\Routing\Router;

/**
 * Every route this app serves, in one file, with no scanning and no attributes.
 *
 * `lava routes --json` is a rendering of this file — including the dependencies
 * each handler will be given, which is why handlers take typed METHOD
 * parameters rather than constructor arguments. Reading this file tells you
 * what the app answers; reading that command tells you the same thing with the
 * wiring already resolved.
 *
 * Route names are the app's stable identifiers: `route('tasks.show', …)` in a
 * template, `lava describe tasks.show` on the command line, and the name in
 * every routing problem's context. They are a public contract — renaming one
 * breaks callers that never saw this file.
 */
return function (Router $r): void {
    // Multiple methods stated explicitly. HEAD is never mapped implicitly —
    // a route that answers a GET does not automatically answer a HEAD.
    $r->add('/health', 'health', Method::Get, Method::Head)
        ->handler('App\Http\health');

    // Static segment registered before the typed one, so the listing reads in
    // the order a human would expect. (`{id:int}` could not match "export"
    // anyway — the int pattern is digits only — so this is clarity, not
    // precedence.)
    $r->get('/tasks/export', 'tasks.export')
        ->handler([TasksController::class, 'export'])
        ->when('tasks_csv_export');

    $r->get('/tasks', 'tasks.index')->handler([TasksController::class, 'index']);
    $r->post('/tasks', 'tasks.store')->handler([TasksController::class, 'store']);
    $r->get('/tasks/{id:int}', 'tasks.show')->handler([TasksController::class, 'show']);
    $r->post('/tasks/{id:int}/complete', 'tasks.complete')->handler([TasksController::class, 'complete']);
    $r->delete('/tasks/{id:int}', 'tasks.destroy')->handler([TasksController::class, 'destroy']);
};
