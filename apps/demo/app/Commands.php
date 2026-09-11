<?php

declare(strict_types=1);

// The app's own commands, registered LAST so the app always wins a name it
// wants. Only the returned closure belongs in this file: the boot step
// re-executes it on every boot, so a class declared here would be a redeclare
// fatal the second time. The command classes live in app/Commands/ and
// autoload, exactly as they would in a real app.
//
// Both commands extend AppCommand, so both take `--env` and `--json`, and both
// boot the app before doing anything — which is what lets them use the app's
// real services instead of building their own.

use Lava\Core\Console\CommandRegistry;

return function (CommandRegistry $commands): void {
    $commands->add(new \App\Commands\SeedCommand());
    $commands->add(new \App\Commands\StatsCommand());
};
