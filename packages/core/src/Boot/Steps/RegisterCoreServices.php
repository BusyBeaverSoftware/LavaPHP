<?php

declare(strict_types=1);

namespace Lava\Core\Boot\Steps;

use Lava\Core\Boot\AppContext;
use Lava\Core\Boot\BootCtx;
use Lava\Core\Boot\BootStep;
use Lava\Core\Config\Config;
use Lava\Core\Container\Container;
use Lava\Core\Features\Features;
use Lava\Core\Features\FeatureScope;
use Lava\Core\Log\LineLogger;
use Lava\Core\Problem\InvalidConfig;

/**
 * Registers exactly {@see \Lava\Core\Boot\Kernel::CORE_SERVICES}, in order —
 * nothing else. Module services (M3) and app/Services.php registrations come
 * after, so user wiring can always depend on these ids. A fixture test asserts
 * the container's first ids are exactly the constant, so the two cannot drift.
 * `LoggerInterface` is not among them: core fills it only when nothing else
 * did, in {@see RegisterDefaultServices}.
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

        // The container is built with a test's replacements, if any, because
        // this is where it is built: nothing later — a pack, app/Services.php —
        // can add one, which is what keeps replacement a test's tool.
        $container = new Container($ctx->replacements);
        $container->value('app.dir', $ctx->appDir);
        $container->value('app.env', $ctx->env);
        // `Features` is resolved through the scope, so whoever asks during a
        // request — a handler parameter, a factory — gets the resolver bound to
        // that request's subject, and whoever asks outside one gets boot's. As a
        // singleton it handed every handler the anonymous resolver it was built
        // with, and an audience flag read `off` in every handler and template
        // while the router, which is given the bound one, read it `on`.
        $container->factory(Features::class, static function (Container $c) use ($features): Features {
            $scope = $c->get(FeatureScope::class);

            return $scope instanceof FeatureScope ? $scope->current() : $features;
        });
        $container->singleton(FeatureScope::class, static fn (): FeatureScope => new FeatureScope($features));

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

        $ctx->container = $container;
        $ctx->appContext = new AppContext($ctx->appDir, $ctx->env, $config, $features);
    }
}
