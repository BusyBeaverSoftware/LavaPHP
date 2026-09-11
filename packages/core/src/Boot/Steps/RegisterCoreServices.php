<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\AppContext;
use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\Features;
use Lava\Core\Log\LineLogger;
use Lava\Core\Problem\InvalidConfig;
use Psr\Log\LoggerInterface;

/**
 * Registers exactly {@see \Lava\Core\Boot\Kernel::CORE_SERVICES}, in order —
 * nothing else. Module services (M3) and app/Services.php registrations come
 * after, so user wiring can always depend on these ids. A fixture test asserts
 * the container's first ids are exactly the constant, so the two cannot drift.
 *
 * Config errors surface HERE, at boot, not lazily on first get(): a bad
 * logging.level is an invalid_config problem with a fix, never a surprise
 * exception mid-request.
 */
final class RegisterCoreServices implements BootStep
{
    public function run(BootCtx $ctx): void
    {
        if ($ctx->config === null || $ctx->features === null) {
            return; // a fatal upstream already stopped the chain
        }
        $config = $ctx->config;
        $features = $ctx->features;

        $container = new Container();
        $container->value('app.dir', $ctx->appDir);
        $container->value('app.env', $ctx->env);
        $container->singleton(Features::class, static fn (): Features => $features);

        $level = $config->string('logging.level', $ctx->env === 'prod' ? 'info' : 'debug');
        if (!isset(LineLogger::LEVELS[$level])) {
            $ctx->problems->add(InvalidConfig::badType(
                'logging.level',
                'one of: ' . implode(', ', array_keys(LineLogger::LEVELS)),
                $level,
                'config/logging.php',
            ));
            $level = 'debug'; // keep later steps running so the report shows this problem alone
        }
        $container->singleton(LineLogger::class, static fn (): LineLogger => new LineLogger($level));
        $container->alias(LoggerInterface::class, LineLogger::class);

        $ctx->container = $container;
        $ctx->appContext = new AppContext($ctx->appDir, $ctx->env, $config, $features);
    }
}