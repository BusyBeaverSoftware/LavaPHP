<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Boot\Steps\BuildFeatures;
use Lava\Core\Boot\Steps\BuildRouter;
use Lava\Core\Boot\Steps\CheckAppDir;
use Lava\Core\Boot\Steps\CheckModules;
use Lava\Core\Boot\Steps\CollectFlagDefinitions;
use Lava\Core\Boot\Steps\LoadConfig;
use Lava\Core\Boot\Steps\LoadDotEnv;
use Lava\Core\Boot\Steps\LoadPackConfig;
use Lava\Core\Boot\Steps\RegisterCommands;
use Lava\Core\Boot\Steps\RegisterCoreServices;
use Lava\Core\Boot\Steps\RegisterDefaultServices;
use Lava\Core\Boot\Steps\ValidateWiring;
use Lava\Core\Boot\Steps\WireAppServices;
use Lava\Core\Boot\Steps\WireModules;

/**
 * The entire boot, as one readable list. This constant IS the boot order —
 * there is no hidden sequencing anywhere else. Steps grow in with the
 * milestones (router, commands, wiring validation) and freeze at 0.1.0.
 */
final class Kernel
{
    /** @var list<class-string<BootStep>> */
    public const STEPS = [
        CheckAppDir::class,
        LoadDotEnv::class,
        LoadConfig::class,
        CollectFlagDefinitions::class,
        BuildFeatures::class,
        CheckModules::class,
        LoadPackConfig::class,
        RegisterCoreServices::class,
        WireModules::class,
        WireAppServices::class,
        RegisterDefaultServices::class,
        BuildRouter::class,
        RegisterCommands::class,
        ValidateWiring::class,
    ];

    /**
     * The services the framework itself registers, in order, before any
     * module or app/Services.php wiring runs. A fixture test asserts the
     * container's first ids are exactly this list — the constant cannot
     * drift from the registration code.
     */
    public const CORE_SERVICES = [
        'app.dir',
        'app.env',
        \Lava\Core\Features\Features::class,
        \Lava\Core\Features\FeatureScope::class,
        \Lava\Core\Log\LineLogger::class,
    ];

    /**
     * The services core registers only when neither a pack nor app/Services.php
     * did — see {@see RegisterDefaultServices}. Both are PSR standard interfaces,
     * the ids an app most often wants to fill with a library of its own.
     */
    public const DEFAULT_SERVICES = [
        \Psr\Log\LoggerInterface::class,
        \Psr\Clock\ClockInterface::class,
    ];

    /**
     * Boots an app from its project directory. Never throws for collectable
     * problems: fatal problems yield {@see BootFailure} (which renders the
     * full report); otherwise {@see App} carries any warnings forward.
     *
     * @param array<string, mixed> $replace services a TEST substitutes, id => value —
     *        see {@see \Lava\Core\Testing\TestApp::boot()}. A front controller and
     *        the CLI pass nothing, and nothing an app writes can add an entry.
     */
    public static function boot(string $appDir, array $replace = []): App|BootFailure
    {
        $appDir = rtrim($appDir, '/');
        $ctx = new BootCtx($appDir, new \Lava\Core\Problem\ProblemReport());
        $ctx->replacements = $replace;

        foreach (self::STEPS as $stepClass) {
            $step = new $stepClass();
            try {
                $step->run($ctx);
            } catch (\Lava\Core\Problem\LavaProblem $problem) {
                $ctx->problems->add($problem);
            } catch (\Throwable $throwable) {
                $ctx->problems->add(\Lava\Core\Problem\UnexpectedFailure::of($stepClass, $throwable));
            }
        }

        if ($ctx->problems->hasFatals()) {
            return new BootFailure($ctx->problems, $appDir, $ctx->env);
        }

        // Enabled packs contribute their live manifest; disabled-but-installed
        // ones contributed theirs during WireModules. Merging here means
        // `lava about` lists every pack the app can see, with its gate state
        // read off moduleRefs rather than guessed from this map.
        $packs = $ctx->moduleManifests;
        foreach ($ctx->modules as $moduleClass => $module) {
            $packs[$moduleClass] = $module->pack();
        }

        return new App(
            $appDir,
            $ctx->env,
            $ctx->config ?? new \Lava\Core\Config\Config(),
            $ctx->features
                ?? new \Lava\Core\Features\Features(
                    new \Lava\Core\Features\FeatureSet(),
                    new \Lava\Core\Features\FeatureSettings(),
                    null,
                    'dev',
                ),
            $ctx->container ?? new \Lava\Core\Container\Container(),
            $ctx->problems,
            $ctx->router ?? new \Lava\Core\Routing\Router(),
            $ctx->globalMiddleware,
            $ctx->moduleRefs,
            $packs,
            $ctx->dotEnv,
            $ctx->envFromFile,
        );
    }
}
