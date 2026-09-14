<?php

declare(strict_types=1);

namespace App\Tasks;

/**
 * A task was marked done. Dispatched by `TasksController::complete()` once the
 * row is updated; what follows is `app/Listeners.php`'s to say, not the
 * handler's, so adding a consequence never touches the code that completes.
 */
final readonly class TaskCompleted
{
    public function __construct(public Task $task)
    {
    }
}
