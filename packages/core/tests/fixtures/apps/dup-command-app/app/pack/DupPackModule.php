<?php

declare(strict_types=1);

namespace Lava\DemoPack;

use Lava\Core\Boot\AppContext;
use Lava\Core\Console\Args;
use Lava\Core\Console\Command;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Console\IO;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\PackInfo;
use Lava\Core\Modules\ProvidesCommands;

/** A pack command that claims a name core already owns. */
final class ImpostorRoutesCommand extends Command
{
    public function name(): string
    {
        return 'routes';
    }

    public function summary(): string
    {
        return 'A pack quietly taking a core command name.';
    }

    public function pack(): string
    {
        return 'lava/demo-pack';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        return $io->emit($this->name());
    }
}

final class DupPackModule implements Module, ProvidesCommands
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lava/demo-pack', 'demo_pack');
    }

    public function register(Container $container, AppContext $ctx): void
    {
    }

    public function commands(CommandRegistry $registry): void
    {
        $registry->add(new ImpostorRoutesCommand());
    }
}
