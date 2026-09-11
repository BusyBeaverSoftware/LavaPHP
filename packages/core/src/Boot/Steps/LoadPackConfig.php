<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\ConfigFile;
use Lava\Core\Modules\Module;
use Lava\Core\Problem\LavaProblem;

/**
 * Loads the config files the enabled packs declare.
 *
 * A pack's manifest names the config files it reads ({@see \Lava\Core\Modules\PackInfo}),
 * and this is what makes that declaration true. Without it `configFiles` is a
 * field nothing acts on: `lava about` would advertise that lava/db reads
 * config/database.php while `lava config` showed none of its keys, and a pack
 * would have to read its own file behind the framework's back, without
 * provenance and without the shape checks every other config value gets.
 *
 * Runs after {@see CheckModules} — which has already established that every
 * enabled module class exists — and before {@see RegisterCoreServices}, which
 * is where the immutable {@see \Lava\Core\Boot\AppContext} is built from the
 * finished Config. Loading after that point would be too late: the context
 * carries a Config by value, so a key added later would be invisible to every
 * module and every factory.
 *
 * The modules are instantiated here to read their manifests and then
 * discarded; {@see WireModules} instantiates them again to call `register()`.
 * That is safe and cheap by construction — module constructors are required
 * to be parameterless and side-effect-free, which is the same requirement
 * that lets `lava about` describe a pack without wiring it.
 */
final class LoadPackConfig implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->config === null) {
            return; // a fatal upstream already stopped the chain
        }

        foreach ($ctx->enabledModules as $ref) {
            try {
                $module = new $ref->moduleClass();
            } catch (\Throwable) {
                // WireModules reports the real instantiation failure, with
                // the constructor's own message. Reporting it here as well
                // would put the same problem in the report twice.
                continue;
            }

            if (!$module instanceof Module) {
                continue; // WireModules reports this too, and more precisely
            }

            foreach ($module->pack()->configFiles as $name) {
                $ctx->config = ConfigFile::load(
                    $ctx->config,
                    $ctx->configPath($name . '.php'),
                    $name,
                    "config/{$name}.php",
                    $ctx->problems,
                );
            }
        }
    }
}
