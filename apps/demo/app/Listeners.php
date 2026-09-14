<?php

declare(strict_types=1);

/**
 * Which listeners each event reaches, in the order they run.
 *
 * This is the whole list: nothing registers a listener anywhere else, so
 * `lava events` and the Events section of AGENTS.md show everything that
 * follows an event. A listener is a service from `app/Services.php`, and boot
 * refuses one that cannot take the event it is listed for (`bad_listener`).
 */
return [
    \App\Tasks\TaskCompleted::class => [\App\Tasks\LogCompletion::class],
];
