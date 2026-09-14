<?php

declare(strict_types=1);

namespace App\Tasks;

use Psr\Log\LoggerInterface;

/**
 * Writes a line to the app's log when a task is completed.
 *
 * A listener is an ordinary service: registered in `app/Services.php`, where it
 * gets its logger, and named for `TaskCompleted` in `app/Listeners.php`. Boot
 * checks that `__invoke()` takes the event it is listed for.
 */
final readonly class LogCompletion
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(TaskCompleted $event): void
    {
        $this->logger->info('task completed', ['task' => $event->task->json()]);
    }
}
