<?php

declare(strict_types=1);

// The app's own commands, registered LAST so the app always wins a name it
// wants. Only the returned closure belongs in this file: the boot steps
// re-execute it on every boot, and a class declared here would be a redeclare
// fatal the second time. The command class lives in app/Commands/ and
// autoloads, exactly as it would in a real app.

use Lava\Core\Console\CommandRegistry;

return function (CommandRegistry $commands): void {
    $commands->add(new \App\Commands\AppReportCommand());
};
