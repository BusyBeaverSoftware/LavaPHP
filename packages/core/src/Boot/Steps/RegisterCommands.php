<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Console\CommandRegistry;
use Lava\Core\Modules\ProvidesCommands;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\SourceLocation;

/**
 * Assembles the app's command set: the core commands, then each enabled
 * module's ({@see ProvidesCommands}), then app/Commands.php.
 *
 * Position in the pipeline matters twice. It runs AFTER WireModules and
 * WireAppServices, so every command's dependencies are registered before the
 * command object is built; and BEFORE ValidateWiring, so the registry is part
 * of the wiring sweep — a pack command with an unsatisfiable dependency is a
 * boot problem naming it, not a crash the first time someone runs
 * `lava db:migrate`.
 *
 * Core names are registered first and a collision is FATAL, never shadowing:
 * `lava routes` has to mean the same thing in every app.
 */
final class RegisterCommands implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        // Held in a local, not re-read from $ctx at the end: loading
        // app/Commands.php runs arbitrary app code through this object, and a
        // local is the honest way to say "this container is the one I checked".
        $container = $ctx->container;
        if ($container === null) {
            return; // a fatal upstream already stopped the chain
        }

        $registry = CommandRegistry::core();

        // One failing module never blocks the others — the same contract every
        // other multi-source step keeps. Whatever registered cleanly still runs.
        foreach ($ctx->modules as $module) {
            if (!$module instanceof ProvidesCommands) {
                continue;
            }
            try {
                $module->commands($registry);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            }
        }

        $this->loadAppCommands($ctx, $registry);

        // A name its envelope's contract id cannot hold is a warning, not a
        // refusal: this step runs on every boot, a web request's included, and a
        // CLI naming rule must not stop the site serving.
        foreach ($registry->nameProblems() as $problem) {
            $ctx->problems->add($problem);
        }

        $ctx->commands = $registry;
        // Registered as a service so `lava services` shows it, packs can
        // resolve it, and ValidateWiring proves it resolvable like any other id.
        $container->value(CommandRegistry::class, $registry);
    }

    /**
     * app/Commands.php — the app's own commands, registered last so the app
     * always wins a name it wants. Loaded like every other user artifact:
     * missing means "none of that", a wrong shape is an invalid_config problem
     * naming the file, and a bad registration never hides the good ones.
     */
    private function loadAppCommands(BootCtx $ctx, CommandRegistry $registry): void
    {
        $file = $ctx->appPath('Commands.php');
        if (!is_file($file)) {
            return;
        }

        $loader = require $file;
        if (!is_callable($loader)) {
            $ctx->problems->add(new InvalidConfig(
                'app/Commands.php must return a callable that registers commands.',
                'End the file with: return function (CommandRegistry $commands): void { … };',
                ['file' => 'app/Commands.php'],
                SourceLocation::of($file, 1),
            ));
            return;
        }

        try {
            $registry->addingFor('app', static fn (CommandRegistry $commands) => $loader($commands));
        } catch (LavaProblem $problem) {
            $ctx->problems->add($problem);
        }
    }
}
