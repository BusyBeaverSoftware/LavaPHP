<?php

declare(strict_types=1);

// The app's own attempt at a collision — a second source, so a test can prove
// the module's failure did not stop the app from being heard.

use Lava\Core\Console\CommandRegistry;

return function (CommandRegistry $commands): void {
    $commands->add(new \App\Commands\ImpostorServicesCommand());
};
