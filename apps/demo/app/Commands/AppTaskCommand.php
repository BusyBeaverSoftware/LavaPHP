<?php

declare(strict_types=1);

namespace App\Commands;

use App\Tasks\TaskRepository;
use Lava\Core\Boot\App;
use Lava\Core\Console\Commands\AppCommand;
use Lava\Core\Problem\InvalidConfig;

/**
 * What this app's commands have in common: they both want the task repository,
 * and neither should build its own.
 *
 * A command that needs a service extends {@see AppCommand}, whose first act is
 * to boot the app — so `inspect()` receives the app's real container and the
 * service comes from the app rather than from a second, differently-configured
 * object constructed inside the command. A diagnostic that disagrees with the
 * thing it diagnoses is worse than no diagnostic.
 *
 * The `instanceof` is not defensive noise. `Container::get()` is typed `mixed`,
 * because a container holds heterogeneous things; the id is a *promise* about a
 * type, and this is the one place that checks the promise was kept. The
 * framework's own `db:*` commands do exactly this, for exactly this reason —
 * `AppCommand` cannot do it for them, because only the caller knows which type
 * a given id promised.
 */
abstract class AppTaskCommand extends AppCommand
{
    public function pack(): string
    {
        return 'app';
    }

    protected function tasks(App $app): TaskRepository
    {
        $service = $app->container->get(TaskRepository::class);

        if (!$service instanceof TaskRepository) {
            throw InvalidConfig::wrongService(TaskRepository::class, TaskRepository::class, $service);
        }

        return $service;
    }
}
