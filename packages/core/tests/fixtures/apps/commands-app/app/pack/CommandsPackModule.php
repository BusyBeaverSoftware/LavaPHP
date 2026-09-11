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

// A fixture pack whose commands() registers one command. Classes, not closures,
// because a command is a named thing `lava list` has to show.

final class PingCommand extends Command
{
    public function name(): string
    {
        return 'demo:ping';
    }

    public function summary(): string
    {
        return 'Answer from the pack itself.';
    }

    public function pack(): string
    {
        return 'lava/demo-pack';
    }

    public function run(IO $io, Args $args, string $appDir): int
    {
        $io->data('pong', true);
        $io->line('pong');
        return $io->emit($this->name());
    }
}

final class CommandsPackModule implements Module, ProvidesCommands
{
    public function pack(): PackInfo
    {
        return PackInfo::of('lava/demo-pack', 'demo_pack');
    }

    public function register(Container $container, AppContext $ctx): void
    {
        // Nothing to wire: this fixture is about the command set.
    }

    public function commands(CommandRegistry $registry): void
    {
        $registry->add(new PingCommand());
    }
}
