<?php

declare(strict_types=1);

namespace Lava\Core\Boot;

use Lava\Core\Boot\Steps\BuildFeatures;
use Lava\Core\Boot\Steps\BuildRouter;
use Lava\Core\Boot\Steps\CheckModules;
use Lava\Core\Boot\Steps\CollectFlagDefinitions;
use Lava\Core\Boot\Steps\LoadConfig;
use Lava\Core\Boot\Steps\LoadDotEnv;
use Lava\Core\Boot\Steps\RegisterCoreServices;
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
        LoadDotEnv::class,
        LoadConfig::class,
        CollectFlagDefinitions::class,
        BuildFeatures::class,
        CheckModules::class,
        RegisterCoreServices::class,
        WireModules::class,
        WireAppServices::class,
        BuildRouter::class,
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
        \Lava\Core\Log\LineLogger::class,
        \Psr\Log\LoggerInterface::class,
    ];

    /**
     * Boots an app from its project directory. Never throws for collectable
     * problems: fatal problems yield {@see BootFailure} (which renders the
     * full report); otherwise {@see App} carries any warnings forward.
     */
    public static function boot(string $appDir): App|BootFailure
    {
        $appDir = rtrim($appDir, '/');
        $ctx = new BootCtx($appDir, new \Lava\Core\Problem\ProblemReport());

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
        );
    }
}