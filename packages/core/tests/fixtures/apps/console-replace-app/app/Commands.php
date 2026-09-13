<?php

declare(strict_types=1);

use Lava\Core\Console\CommandRegistry;

// Only the closure: the command class autoloads from app/ReplaceConsole/.
return function (CommandRegistry $commands): void {
    $commands->add(new \App\ReplaceConsole\GreetCommand());
};
