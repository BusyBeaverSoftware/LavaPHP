<?php

declare(strict_types=1);

namespace App\Http;

use App\Problem\TaskNotFound;
use App\Tasks\Task;
use App\Tasks\TaskCompleted;
use App\Tasks\TaskRepository;
use Lava\Core\Http\HttpErrors;
use Lava\Core\Http\Responses;
use Lava\Core\Routing\RouteArgs;
use Lava\Events\EventDispatcher;
use Lava\Validate\Validation\Field;
use Lava\Validate\Validation\Validator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The tasks API — the whole HTTP surface of this app.
 *
 * Like every handler in a LavaPHP app, this class is constructed with NO
 * arguments and its dependencies arrive as typed METHOD parameters. That is not
 * a style choice: it is what lets boot check the plan before a request arrives,
 * and what makes `lava routes --json` show each route's full dependency story.
 * A constructor argument would hide the dependency from both.
 *
 * Every failure leaves as a {@see \Lava\Core\Problem\LavaProblem} — a 404 for an
 * id that is not there, a 422 for input that arrived and was not usable — so
 * the JSON an agent gets from a rejected request has the same shape as the JSON
 * a broken boot produces. One shape to parse, whichever end failed.
 */
final class TasksController
{
    public function index(ServerRequestInterface $request, TaskRepository $tasks): ResponseInterface
    {
        $filter = $request->getQueryParams()['done'] ?? null;

        // `?done=done` / `?done=open`; anything else, including absent, means
        // "all". An unknown filter is deliberately not an error: a list
        // endpoint that 400s on a value it does not recognise is a worse list
        // endpoint, and the caller can see from the response what they got.
        $done = match (is_string($filter) ? $filter : null) {
            'done' => true,
            'open' => false,
            default => null,
        };

        return Responses::json([
            'tasks' => array_map(static fn (Task $task): array => $task->json(), $tasks->all($done)),
        ]);
    }

    public function show(
        ServerRequestInterface $request,
        RouteArgs $args,
        TaskRepository $tasks,
    ): ResponseInterface {
        $id = $args->int('id');
        $task = $tasks->find($id);

        if ($task === null) {
            return HttpErrors::toResponse(TaskNotFound::of($id), $request);
        }

        return Responses::json(['task' => $task->json()]);
    }

    public function store(ServerRequestInterface $request, TaskRepository $tasks): ResponseInterface
    {
        $body = $request->getParsedBody();

        // The field map is the allow-list: a key that is not declared here is
        // dropped, so a caller cannot set a column by guessing its name.
        $input = Validator::of([
            'title' => Field::str()->required()->max(200),
            'due_on' => Field::str()->regex('/\d{4}-\d{2}-\d{2}/'),
        ])->validate(is_array($body) ? $body : []);

        if ($input->failed()) {
            // ONE response carrying every bad field, each with its own message
            // and its own fix line. Fixing one field per round trip is the
            // thing this framework exists to avoid.
            return HttpErrors::forReport($input->report(), $request);
        }

        $task = $tasks->create(
            $input->string('title'),
            // An optional field that was never sent has no value to read:
            // `$input->string('due_on')` would throw `unreadable_field`. Asking
            // first is how a handler tells "absent" from "empty".
            $input->has('due_on') ? $input->string('due_on') : null,
        );

        return Responses::json(['task' => $task->json()], 201);
    }

    public function complete(
        ServerRequestInterface $request,
        RouteArgs $args,
        TaskRepository $tasks,
        EventDispatcher $events,
    ): ResponseInterface {
        $id = $args->int('id');
        $task = $tasks->complete($id);

        if ($task === null) {
            return HttpErrors::toResponse(TaskNotFound::of($id), $request);
        }

        // What follows a completion is app/Listeners.php's to say, not this handler's.
        $events->dispatch(new TaskCompleted($task));

        return Responses::json(['task' => $task->json()]);
    }

    public function destroy(
        ServerRequestInterface $request,
        RouteArgs $args,
        TaskRepository $tasks,
    ): ResponseInterface {
        $id = $args->int('id');

        if (!$tasks->delete($id)) {
            return HttpErrors::toResponse(TaskNotFound::of($id), $request);
        }

        return Responses::noContent();
    }

    /**
     * Reachable only while the `tasks_csv_export` flag is on. While it is off
     * this route is absent, and `/tasks/export` answers a real 404 — the same
     * answer an app that never had the route would give.
     */
    public function export(TaskRepository $tasks): ResponseInterface
    {
        $stream = fopen('php://temp', 'r+');

        if ($stream === false) {
            // Unreachable in practice; stated rather than assumed, because the
            // alternative is a `false` flowing into `fputcsv()`.
            throw new \RuntimeException('php://temp could not be opened for writing.');
        }

        // `escape: ''` is RFC 4180: the backslash escape `fputcsv()` otherwise
        // defaults to is a PHP extension of the format, and a spreadsheet
        // reading the file does not implement it.
        fputcsv($stream, ['id', 'title', 'done', 'due_on', 'created_at'], escape: '');
        foreach ($tasks->all() as $task) {
            fputcsv($stream, $task->csvRow(), escape: '');
        }

        rewind($stream);
        $csv = (string) stream_get_contents($stream);
        fclose($stream);

        return Responses::text($csv)->withHeader('Content-Type', 'text/csv; charset=utf-8');
    }
}
