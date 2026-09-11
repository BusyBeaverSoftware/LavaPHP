<?php

declare(strict_types=1);

namespace App\Commands;

use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\IO;

/** A command only this app has — proof that app/Commands.php reaches the registry. */
final class AppReportCommand extends Command
{
    public function name(): string
    {
        return 'app:report';
    }

    public function summary(): string
    {
        return 'Report something only this app knows.';
    }

    public function pack(): string
    {
        return 'app';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $io->data('app_dir', $appDir);
        $io->line('app:report');
        return $io->emit($this->name());
    }
}
