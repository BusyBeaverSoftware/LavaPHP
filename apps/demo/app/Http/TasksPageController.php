<?php

declare(strict_types=1);

namespace App\Http;

use App\Problem\TaskNotFound;
use App\Tasks\TaskRepository;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Routing\RouteArgs;
use Lava\View\ViewRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The same tasks, as HTML — a second controller rather than a branch inside
 * {@see TasksController}.
 *
 * There is no content negotiation in this framework, and this file is what that
 * rule looks like in practice: a route answers ONE media, and a caller who wants
 * the other asks for a different route. So `/tasks` is JSON and `/` is the page,
 * and neither handler has to ask what the caller would have preferred. A single
 * controller with `if ($request->getHeaderLine('Accept') === 'text/html')` would
 * put two response shapes in one method, hide both from `lava routes --json`,
 * and make the route's own contract depend on a header.
 *
 * The handler contract is unchanged from the JSON side: no constructor,
 * dependencies as typed method parameters. `ViewRenderer` is a registered id —
 * lava/view registers it — so it is injectable by type like any other service.
 */
final class TasksPageController
{
    public function index(TaskRepository $tasks, ViewRenderer $view): ResponseInterface
    {
        // Read once and handed to the template whole. The view decides how to
        // present the list; the handler decides which list.
        return $view->render('home', ['tasks' => $tasks->all()]);
    }

    public function show(
        ServerRequestInterface $request,
        RouteArgs $args,
        TaskRepository $tasks,
        ViewRenderer $view,
    ): ResponseInterface {
        $id = $args->int('id');
        $task = $tasks->find($id);

        if ($task === null) {
            // The app's own problem, rendered in whichever media the caller
            // asked for — JSON for curl, the diagnostics page for a browser.
            // The HTML route deliberately does NOT get a second, page-shaped
            // 404: one failure is one code with one fix, and a second rendering
            // of it would be a second thing to keep in step.
            return HttpErrors::toResponse(TaskNotFound::of($id), $request);
        }

        return $view->render('task', ['task' => $task]);
    }
}
