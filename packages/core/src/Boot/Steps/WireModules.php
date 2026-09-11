<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\AppContext;
use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Container\Container;
use Lava\Core\Modules\Module;
use Lava\Core\Modules\ModuleCheck;
use Lava\Core\Modules\ModuleRef;
use Lava\Core\Problem\InvalidConfig;
use Lava\Core\Problem\LavaProblem;
use Lava\Core\Problem\MissingPack;

/**
 * Wires the enabled packs, in app/Modules.php order, between the core
 * services and app/Services.php — that is the container's documented
 * registration order. Each module is instantiated, cross-checked against
 * its entry, and given the container; one failing module never blocks the
 * others.
 *
 * Disabled-but-installed packs are loaded manifest-only: pack() without
 * register(), so reports and `lava about` can still describe them. A pack
 * that is off AND missing is simply absent — off means absent.
 */
final class WireModules implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->container === null || $ctx->appContext === null) {
            return; // a fatal upstream already stopped the chain
        }

        foreach ($ctx->enabledModules as $ref) {
            try {
                $ctx->modules[$ref->moduleClass] = self::wire($ref, $ctx->container, $ctx->appContext);
            } catch (LavaProblem $problem) {
                $ctx->problems->add($problem);
            } catch (\Error $error) {
                // `new` on a constructor with required parameters lands here.
                $ctx->problems->add(new InvalidConfig(
                    "Module class {$ref->moduleClass} cannot be instantiated: {$error->getMessage()}",
                    'Module constructors must be parameterless — dependencies arrive as register() arguments.',
                    ['module_class' => $ref->moduleClass],
                    $ref->declaredAt,
                ));
            }
        }

        foreach ($ctx->disabledModules as $ref) {
            if (!class_exists($ref->moduleClass)) {
                continue; // off means absent; a missing pack that is off is fine
            }
            try {
                $module = new $ref->moduleClass();
                if ($module instanceof Module) {
                    ModuleCheck::crossCheck($ref, $module->pack());
                    $ctx->moduleManifests[$ref->moduleClass] = $module->pack();
                }
            } catch (\Throwable) {
                // Manifest loading is diagnostics data for a pack that is off;
                // it can never block boot, and the real error surfaces the
                // moment the pack is enabled.
            }
        }
    }

    private static function wire(ModuleRef $ref, Container $container, AppContext $appContext): Module
    {
        if (!class_exists($ref->moduleClass)) {
            // CheckModules verified this; the guard keeps the message exact if steps ever reorder.
            throw MissingPack::of($ref);
        }
        $module = new $ref->moduleClass();
        if (!$module instanceof Module) {
            throw new InvalidConfig(
                "Module class {$ref->moduleClass} does not implement Lava\Core\Modules\Module.",
                'A pack module implements Module (pack() + register()); check the class named in app/Modules.php.',
                ['module_class' => $ref->moduleClass],
                $ref->declaredAt,
            );
        }
        ModuleCheck::crossCheck($ref, $module->pack());
        $module->register($container, $appContext);
        return $module;
    }
}