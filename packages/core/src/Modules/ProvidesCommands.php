<?php

declare(strict_types=1);

namespace Lava\Core\Modules;

use Lava\Core\Console\CommandRegistry;

/**
 * A module that contributes CLI commands.
 *
 * The optional-capability idiom, exactly like {@see ProvidesRoutes}: a module
 * implements it, RegisterCommands checks with instanceof, and a module that
 * does not care about the CLI says nothing. Growing the mandatory {@see Module}
 * interface instead would have broken every pack that predates commands.
 *
 * commands() is called AFTER the core commands are registered and BEFORE
 * app/Commands.php, so a name collision is always reported against the later
 * registration — core names are never shadowed, and the app can always claim
 * a name a pack wanted.
 */
interface ProvidesCommands
{
    /**
     * Registers the pack's commands, in the pack's own order.
     *
     * @throws \Lava\Core\Problem\DuplicateCommand when a name is already taken
     */
    public function commands(CommandRegistry $registry): void;
}
