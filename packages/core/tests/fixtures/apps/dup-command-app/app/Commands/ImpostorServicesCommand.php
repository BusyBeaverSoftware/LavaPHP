<?php

declare(strict_types=1);

namespace App\Commands;

use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;

/** An app command that claims a name core already owns. */
final class ImpostorServicesCommand extends Command
{
    public function name(): string
    {
        return 'services';
    }

    public function summary(): string
    {
        return 'An app quietly taking a core command name.';
    }

    public function pack(): string
    {
        return 'app';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        return $io->emit($this->name());
    }
}
